<?php defined('BASEPATH') OR exit('No direct script access allowed');

use \LINE\LINEBot;
use \LINE\LINEBot\HTTPClient\CurlHTTPClient;
use \LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use \LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use \LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends CI_Controller {

    private $bot;
    private $events;
    private $signature;
    private $user;
    private $moderator;

    function __construct()
    {
        parent::__construct();
        $this->load->model('vote_m');

        // create bot object
        $httpClient = new CurlHTTPClient($_ENV['CHANNEL_ACCESS_TOKEN']);
        $this->bot  = new LINEBot($httpClient, ['channelSecret' => $_ENV['CHANNEL_SECRET']]);
    }

    public function index()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') 
        {
            echo "Hello World!";
            header('HTTP/1.1 400 Only POST method allowed');
        exit;
        }

        // get request
        $body = file_get_contents('php://input');
        $this->signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : "-";
        $this->events = json_decode($body, true);

        // log every event requests
        $this->vote_m->log_events($this->signature, $body);

        if(is_array($this->events['events']))
        {
            foreach ($this->events['events'] as $event)
            {
                // get user profile
                $getProf = $this->bot->getProfile($event['source']['userId']);
                $profile = $getProf->getJSONDecodedBody();

                // if event type is follow (user add the bot)
                if($event['type'] == 'follow' || $event['type'] == 'join' || $event['type'] == 'unfollow')
                {
                    $this->{$event['type'].'Callback'}($event, $profile);
                }
                // event type probably is message
                else
                {
                    $test = $event['message']['text'];
                    $userMessage = strtolower($test);
                    $source = $this->checkSource($event);
                    $sourceId = $this->checkSourceId($event);
                    if(strtolower($userMessage) == "leave")
                    {
                        $this->leave($event, $sourceId);
                    }
                    // if message come from room or group
                    else if($source == 'room' || $source == 'group')
                    {
                        // check if user is moderator or not
                        $this->moderator = $this->vote_m->checkMod($sourceId);

                        // if moderator not found
                        if(! $this->moderator)
                        {
                            // if someone volunteering as moderator
                            if(strtolower($userMessage) == "mod")
                            {
                                // save data user to database
                                $this->saveModerator($event, $profile, $sourceId);    
                            }
                            else
                            {
                                // bot send message saying moderator is not found
                                $this->missingModerator($event);    
                            }
                        }
                        else
                        {
                            // only moderator can request
                            if($this->moderator['user_id'] == $event['source']['userId'] )
                            {   
                                if(strtolower($userMessage) == "leave")
                                {
                                    $this->leave($event, $sourceId);
                                }
                                else
                                {
                                    $this->manageVote($event, $this->moderator, $userMessage, $sourceId);
                                }
                            }
                            // another user message
                            else
                            {
                                return 0;
                            }
                        }
                    }
                    // if message come privately from user
                    else
                    {
                        $this->user = $this->vote_m->getUser($profile['userId']);
                        // action 5 : join vote
                        // input : user input access code
                        // output : candidate list of vote will be given
                        if($this->user['action'] == 5)
                        {
                            $checkAvailability = $this->vote_m->checkAvailability($this->user['user_id'], $userMessage);
                            $match = $this->vote_m->matchVoteId($userMessage);
                            if($match == true)
                            {
                                $checkAvailability = $this->vote_m->checkAvailability($this->user['user_id'], $userMessage);
                                if($checkAvailability == false)
                                {
                                    $message = "Anda sudah pernah melakukan voting pada pemilihan ini sebelumnya. Satu orang hanya dapat melakukan voting sekali. Kode ini sudah tidak berlaku";
                                    $this->sendMessage($event, $message);

                                    $action = 0;
                                    $this->vote_m->updateAction($action,$this->user['user_id']);
                                }
                                else
                                {
                                    $this->vote_m->addDetailAction($userMessage, $this->user['user_id']);

                                    // bot show the list of candidate to room
                                    $detailVote = $this->vote_m->getDetailVote($userMessage);
                                    $showList = $this->vote_m->getCandidateList($userMessage);
                                    foreach($showList as $row)
                                    {
                                        $candidates[] = new MessageTemplateActionBuilder($row['candidates'], $row['candidates']);
                                    }
                                    $buttonTemplate = new ButtonTemplateBuilder($detailVote['title'],"Pilih kandidatmu" ,NULL , $candidates);
                                    $messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat voting", $buttonTemplate);
                                    $this->bot->replyMessage($event['replyToken'], $messageBuilder);

                                    $action = 6;
                                    $this->vote_m->updateAction($action,$this->user['user_id']);
                                }
                            }
                            else
                            {
                                $message = "maaf, kode yang anda masukkan salah. coba ulangi lagi";
                                $this->sendMessage($event, $message);
                            }
                        }
                        // action 6 : join vote
                        // input : system save vote data to database
                        // output : bot tell user that vote data has been saved
                        else if($this->user['action'] == 6)
                        {
                            $submit = $this->vote_m->submitVote($this->user['detail_action'], $userMessage);
                            if($submit == true)
                            {
                                $action = 0;
                                $this->vote_m->addVoter($this->user['user_id'], $this->user['detail_action'], $userMessage);
                                $this->vote_m->nullifyAction($action, $this->user['user_id']);

                                $message = "Data voting anda telah diterima.";
                                $message2 = "Terima kasih telah berpatisipasi dalam pemilihan ini. Nantikan hasil votingnya :)";
                                
                                $this->sendMessage2($event, $message, $message2);
                            }
                            else
                            {
                                $action = 5;
                                $this->vote_m->updateAction($action,$this->user['user_id']);
                                $message = "Data voting gagal diterima. Masukkan kembali kode akses anda";

                                $this->sendMessage($event, $message);
                            }
                        }
                        else
                        {
                            if($userMessage == "1" || $userMessage == "create vote")
                            {

                            }
                            else if($userMessage == "2" || $userMessage == "join vote")
                            {
                                $message = "Masukkan kode akses kamu disini";
                            
                                $this->sendMessage($event, $message);

                                $action = 5;
                                $this->vote_m->updateAction($action,$this->user['user_id']);
                            }
                            else
                            {
                                $this->tellMessage($event, $profile);
                            }
                        }
                    } 
                }
            }
        }
    } 

    private function followCallback($event, $profile)
    {
        $this->vote_m->saveUser($profile);
        // bot send welcome message
        $message = "Salam kenal, " . $profile['displayName'] . " :) \n";
        $message .= "Terima kasih telah menambahkan saya sebagai teman \n\n\n";
        $message .= "Saya adalah bot yang dapat membantu kalian untuk dalam proses voting :)";
        $message2 = "Ketik '1' atau 'create vote' untuk membuat voting\n\n";
        $message2 .= "Ketik '2' atau 'join vote' untuk mengikuti voting yang sedang berlangsung";

        $this->sendMessage2($event, $message, $message2);
    }

    private function unfollowCallback($event, $profile)
    {
        $this->vote_m->deleteUser($event);
    }

    private function joinCallback($event)
    {
        // bot send welcome message
        $message = "Salam kenal semuanya :) \n";
        $message .= "Terima kasih telah mengundang saya kedalam grup ini \n";
        $message .= "\n\nSaya akan membantu kalian untuk dalam proses voting :) ";
        $message2 = "Ajukan salah satu dari kalian sebagai moderator terlebih dahulu :)";
        $message2 = "Ketik 'mod' pada kolom chat untuk mengajukan diri";

        $this->sendMessage2($event, $message, $message2);
    }

    private function sendMessage($event, $message)
    {
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
    }

    private function sendMessage2($event, $message, $message2)
    {
        $textMessageBuilder = new TextMessageBuilder($message);
        $textMessageBuilder2 = new TextMessageBuilder($message2);
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($textMessageBuilder);
        $multiMessageBuilder->add($textMessageBuilder2);

        // send reply message
        $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
    }

    private function checkSource($event)
    {
        if($event['source']['type'] == 'room')
        {
            $source = 'room';
        }
        else if($event['source']['type'] == 'group')
        {
            $source = 'group';
        }
        else if($event['source']['type'] == 'user')
        {
            $source = 'user';
        }
        return $source;
    }
  
    private function checkSourceId($event)
    {
        if($event['source']['type'] == 'room')
        {
            $sourceId = $event['source']['roomId'];
        }
        else if($event['source']['type'] == 'group')
        {
            $sourceId = $event['source']['groupId'];
        }
        else if($event['source']['type'] == 'user')
        {
            $sourceId = $event['source']['userId'];
        }
        return $sourceId;
    }

    private function saveModerator($event , $profile ,$sourceId)
    {
        // generate vote id
        $voteId = $this->generateRandomString();

        // save user as moderator
        $this->vote_m->saveMod($voteId, $profile, $sourceId);

        // bot send message
        $message = $profile['displayName'] . " mengajukan diri sebagai moderator";
        $message2 = "Mulai voting dengan mengetikkan '1' atau 'create vote' pada kolom chat";
    
        $this->sendMessage2($event, $message, $message2);
    }

    private function missingModerator($event)
    {
        $message = 'Moderator belum ditemukan.';
        $message2 = 'Ajukan diri sebagai moderator dengan mengetik "mod" pada kolom chat';
    
        $this->sendMessage2($event, $message, $message2);
    }

    private function tellMessage($event, $profile)
    {
        // bot send welcome message
        $message = "Hai " . $profile['displayName'] . ", apa yang ingin kamu lakukan?";
        $message2 = "Ketik '1' atau 'create vote' untuk membuat voting\n\n";
        $message2 .= "Ketik '2' atau 'join vote' untuk mengikuti voting yang sedang berlangsung";

        $this->sendMessage2($event, $message, $message2);
    }

    private function leave($event, $sourceId)
    {
        //delete data from database
        $this->vote_m->deleteVote($sourceId);

        if($event['source']['type'] == 'room')
        {
            $this->bot->leaveRoom($sourceId);
        }
        else if($event['source']['type'] == 'group')
        {
            $this->bot->leaveGroup($sourceId);
        }
        else
        {
            return 0;
        }   
    }

    private function generateRandomString($length = 5) 
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function manageVote($event, $moderator, $userMessage, $sourceId)
    {
        // check status of moderator
        // 0 : Haven't done anything or Vote Ended
        // 1 : Enter Title of Voting
        // 2 : Add Candidates
        // 3 : Begin Vote
        if($moderator['status'] == 0)
        {
            // enter title of voting
            if($userMessage == '1' || $userMessage == 'create vote')
            {
                $message = "Masukkan judul untuk pemilihan ini";
                $this->sendMessage($event, $message);

                $status = 1;
                // change status in database
                $this->vote_m->changeStatus($status, $moderator['vote_id']);
            }
            // reminder to moderator to type in chatbox
            else
            {
                $message = "Hai" . $profile['displayName']. ". Kamu adalah moderator.\n";
                $message = 'Ketik "1" untuk memulai voting';
                $this->sendMessage($event, $message);
            }
        }
        else if($moderator['status'] == 1)
        {
            // add user message to database
            $this->vote_m->addVoteTitle($userMessage , $moderator['vote_id']);

            // bot send next assignment to user
            $message = "Masukkan nama calon kandidat untuk pemilihan ini";
            $message .= "\n\nformat : add (nama kandidat)";
            $message .= "\ncontoh : add budi";
            $this->sendMessage($event, $message);

            $status = 2;
            // change status in database
            $this->vote_m->changeStatus($status, $moderator['vote_id']);
        }
        else if($this->moderator['status'] == 2)
        {
            if($userMessage == "3" or $userMessage == "begin vote")
            {
                // change status in database
                $status = 3;
                $this->vote_m->changeStatus($status, $moderator['vote_id']);

                $message = "Voting dimulai. Voting akan berakhir saat ". $moderator['displayName'] ." mengakhiri waktu voting.\n\n";
                $message .= "Selanjutnya silahkan mengikuti proses voting dengan mengirimkan kode dibawah kepada saya melalui private chat :)\n";
                $message .= "Kode untuk mengikuti proses voting : " . $moderator['vote_id'];
                $message .= "\n\nVoting berakhir ketika moderator mengetikkan 'End Vote' pada chat";

                $this->sendMessage($event, $message);

                //then, user can join voting by put the code on private chat with bot
            }
            // moderator add candidate to list
            else if(strpos($userMessage,'add') !== false)
            {
                $candidate = str_replace('add ', '', $userMessage);

                // add candidates to database
                $this->vote_m->addCandidate($candidate, $moderator['vote_id']);

                $message = "List Kandidat\n";
                // bot show the list of candidate to room
                $showList = $this->vote_m->getCandidateList($moderator['vote_id']);
                $rowNum = 1;
                foreach($showList as $row)
                {
                    $message .= $rowNum . ". " . $row['candidates'] . "\n";
                    $rowNum++;
                }

                $message .= "\n\nHapus kandidat dari list dengan mengetik 'remove (nama kandidat)' pada kolom chat.";
                $message .= "\ncontoh : remove budi\n";
                $message .= "\nKetik '3' atau 'begin vote' untuk memulai vote";
                $this->sendMessage($event, $message);
            }
            // moderator remove candidate from list
            else if(strpos($userMessage,'remove') !== false)
            {
                $candidate = str_replace('remove ','', $userMessage);
                // remove candidate from list
                $this->vote_m->removeCandidate($candidate, $moderator['vote_id']);
                $message = "List Kandidat\n";
                // bot show the list of candidate to room
                $showList = $this->vote_m->getCandidateList($moderator['vote_id']);
                $rowNum = 1;
                foreach($showList as $row)
                {
                    $message .= $rowNum . ". " . $row['candidates'] . "\n";
                    $rowNum++;
                }
                $message .= "\n\nHapus kandidat dari list dengan mengetik 'remove (nama kandidat)' pada kolom chat.";
                $message .= "\n contoh : remove budi\n";
                $message .= "\n\nKetik '3' atau 'begin vote' untuk memulai vote";
                $this->sendMessage($event, $message);
            }
            else if($userMessage == 'list')
            {
                $message = "List Kandidat\n";
                // bot show the list of candidate to room
                $showList = $this->vote_m->getCandidateList($moderator['vote_id']);
                $rowNum = 1;
                foreach($showList as $row)
                {
                    $message .= $rowNum . ". " . $row['candidates'] . "\n";
                    $rowNum++;
                }

                $message .= "\n\nHapus kandidat dari list dengan mengetik 'remove (nama kandidat)' pada kolom chat.";
                $message .= "\n contoh : remove budi\n";
                $message .= "\n\nKetik '3' atau 'mulai vote' untuk memulai vote";
                $this->sendMessage($event, $message);
            }
            else
            {
                return 0;
            }
        }
        else if($moderator['status'] == 3)
        {
            if($userMessage == 'end vote')
            {
                $message = '"' . $moderator['title'] . '"';
                $message .= "\n\nHasil Voting\n";
                // bot show the list of candidate to room
                $winner = $this->vote_m->getWinner($moderator['vote_id']);
                $showList = $this->vote_m->getCandidateList($moderator['vote_id']);
                $rowNum = 1;
                $total = 0;
                foreach($showList as $row)
                {
                    $message .= $rowNum . ". " . $row['candidates'] . " = " . $row['votes'] . " suara\n";
                    $rowNum++;
                }
                foreach($winner as $win)
                {
                    $message .= "\n\nSelamat " . $win['candidates'] . " karena telah memenangkan voting dengan total suara sebanyak " .$row['votes']." suara :)";
                    $total += 1;
                }
                if($total > 1)
                {
                    $message .= "\n\nDikarenakan terdapat lebih dari 1 pemenang, maka disarankan untuk melakukan voting ulang :)";
                } 

                $message2 = "Terima kasih kepada semua yang telah ikut berpartisipasi :)";

                $this->sendMessage2($event, $message, $message2);

                // change status in database
                $status = 0;
                $this->vote_m->changeStatus($status, $moderator['vote_id']);

                // bot leave the room
                $this->leave($event, $sourceId);
            }
            else
            {
                return 0;
            }
        }
                    
    }
}
