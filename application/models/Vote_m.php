<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Vote_m extends CI_Model {

  function __construct(){
    parent::__construct();
    $this->load->database();
  }

  // Events Log
  function log_events($signature, $body)
  {
    $this->db->set('signature', $signature)
    ->set('events', $body)
    ->insert('eventlog');

    return $this->db->insert_id();
  }

  function checkMod($roomId)
  {
    $data = $this->db->where('room_id',$roomId)
      ->get('vote')
      ->row_array();
    if(count($data) > 0) return $data;
    return false;
  }

  function saveMod($voteId, $profile, $roomId)
  {
    $data = $this->db->set('vote_id', $voteId)
    ->set('user_id', $profile['userId'])
    ->set('display_name', $profile['displayName'])
    ->set('room_id', $roomId)
    ->insert('vote');

    return $this->db->insert_id();
  }

  function changeStatus($status,$voteId)
  {
    $data = $this->db->set('status',$status)
    ->where('vote_id',$roomId)
    ->update('vote');

    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  function getVoteId($userId,$roomId)
  {
    $data = $this->db->select('vote_id')
    ->where('user_id', $userId)
    ->where('room_id',$roomId)
    ->get('vote')
    ->row();

    if($data->num_rows > 0) return $data;
    return false;
  }

  function getDetailVote($voteId)
  {
    $data = $this->db->where('vote_id', $voteId)
    ->get('vote')
    ->row_array();

    if(count($data) > 0) return $data;
    return false;
  }

  function matchVoteId($userMessage)
  {
    $data = $this->db->select('vote_id')
    ->where('vote_id',$userMessage)
    ->get('vote')
    ->row();
    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  function addVoteTitle($userMessage)
  {
    $data = $this->db->set('title',$userMessage)
    ->where('vote_id', $voteId)
    ->update('vote');
    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  function addCandidate($candidate, $voteId)
  {
    $data = $this->db->set('vote_id', $vote_id)
    ->set('candidates', $candidate)
    ->insert('vote_contain');

    return $this->db->insert_id();
  }

  function getCandidateList($voteId)
  {
    $data = $this->db->where('vote_id', $voteId)
    ->get('vote_contain');

    return $data->result_array();
  }
  // Users
  function getUser($userId)
  {
    $data = $this->db->where('user_id', $userId)
    ->get('users')
    ->row_array();

    if(count($data) > 0) 
    {
      return $data;
    }
    else 
    {
      return false;
    }
  }

  function saveUser($profile)
  {
    $data = $this->db->set('user_id', $profile['userId'])
    ->set('display_name', $profile['displayName'])
    ->insert('users');

    return $this->db->insert_id();
  }

  function updateAction($action,$userId)
  {
    $data = $this->db->set('action',$action)
    ->where('user_id',$userId)
    ->update('users');

    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  function addDetailAction($voteId,$userId)
  {
    $data = $this->db->set('detail_action',$voteId)
    ->where('user_id',$userId)
    ->update('users');

    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  function getDetailAction($userId)
  {
    $data = $this->db->select('detail_action')
    ->where('user_id',$userId)
    ->get('users')
    ->row();

    if(count($data) > 0) 
    {
      return $data;
    }
    else 
    {
      return false;
    }
  }

  function submitVote($voteId,$userMessage)
  {
    $data = $this->db->set('votes = votes + 1')
    ->where('vote_id', $voteId)
    ->where('candidates', $userMessage)
    ->update('vote_contain');

    if($this->db->affected_rows() > 0)
    {
      return true;
    }
    else
    {
      return false;
    }

  }

  function getWinner($voteId)
  {
    $data = $this->db->where('votes = (SELECT MAX(votes) FROM vote_contain)',NULL,FALSE)
    ->where('vote_id', $voteId)
    ->get('vote_contain')
    ->result();

    if(count($data) > 0) 
    {
      return $data;
    }
    else 
    {
      return false;
    }

  }

/*  function getRoomMemberData($roomId)
  {

  }
  // Question
  function getQuestion($questionNum){}

  function isAnswerEqual($number, $answer){}

  function setUserProgress($user_id, $newNumber){}

  function setScore($user_id, $score){}
*/
}
