<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Vote_m extends CI_Model {


	function __construct()
	{
		parent::__construct();
		$this->load->database();
	}

	// events log
	function log_events($signature, $body)
	{
		$this->db->set('signature', $signature)
			->set('events', $body)
			->insert('eventlog');
		return $this->db->insert_id();

	}
}
