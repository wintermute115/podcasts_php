#!/usr/bin/env php
<?php

/**
 * Handles all communication with the database
 */
class MysqlConn {
	private $host = 'localhost';
	private $database = 'podcasts';
	private $user = 'root';
	private $pass = 'root';
	private $conn;

	public function __construct() {
		$dsn = "mysql:dbname={$this->database};host={$this->host}";
		$this->conn = new PDO($dsn, $this->user, $this->pass);
	}

	/**
	 * Display a list off the podcasts being tracked in the database
	 *
	 * @param string $order
	 * 		'd' - Display by date, most recently updated first
	 * 		'i' - Display by ID, first added to the system first
	 * 		'n' - Display by name, alphabetically
	 * @return array
	 */
	public function list_podcasts (string $order) :array {
		$sql = <<<EOT
SELECT 
	podcast_id, 
	podcast_name, 
	podcast_feed, 
	podcast_last_downloaded,
	podcast_skip
FROM
	podcasts
ORDER BY 
EOT;
		$order_param = match ($order) {
			'i' => 'podcast_id ASC',
			'n' => 'podcast_name ASC',
			'd' => 'podcast_last_downloaded DESC',
			default => null
		};
		$sql .= $order_param;
		$list = $this->conn->prepare($sql);
		$list->execute();
		$results = $list->fetchAll(PDO::FETCH_ASSOC);
		return $results;
	}

	/**
	 * Given a podcast ID, toggle whether or not it'll be downloaded
	 *
	 * @param integer $podcast_id
	 * @return array
	 */
	public function toggle_podcast_by_id(int $podcast_id) :array {
		$response = [
			"name" => '',
			"state" => ''
		];
		// Make the change
		$sql = "UPDATE podcasts SET podcast_skip = (SELECT CASE podcast_skip WHEN '0' THEN '1' ELSE '0' END) WHERE podcast_id = :podcast_id;";
		$toggle = $this->conn->prepare($sql);
		$toggle->bindParam(':podcast_id', $podcast_id, PDO::PARAM_INT);
		$toggle->execute();
		//Check the new state
		$sql = "SELECT podcast_name, podcast_skip FROM podcasts WHERE podcast_id = :podcast_id";
		$check = $this->conn->prepare($sql);
		$check->bindParam(':podcast_id', $podcast_id, PDO::PARAM_INT);
		$check->execute();
		$results = $check->fetch(PDO::FETCH_ASSOC);
		$response['name'] = $results['podcast_name'];
		$response['state'] = ($results['podcast_skip'] == '1' ? "off" : "on");
		return $response;
	}

	/**
	 * Given a podcast name, toggle whether or not it'll be downloaded
	 *
	 * @param string $podcast_name
	 * @return array
	 */
	public function toggle_podcast_by_name(string $podcast_name) :array {
		$response = [
			"name" => '',
			"state" => ''
		];
		// Make the change
		$sql = "UPDATE podcasts SET podcast_skip = (SELECT CASE podcast_skip WHEN '0' THEN '1' ELSE '0' END) WHERE podcast_name = :podcast_name;";
		$toggle = $this->conn->prepare($sql);
		$toggle->bindParam(':podcast_name', $podcast_name, PDO::PARAM_STR);
		$toggle->execute();
		//Check the new state
		$sql = "SELECT podcast_skip FROM podcasts WHERE podcast_name = :podcast_name";
		$check = $this->conn->prepare($sql);
		$check->bindParam(':podcast_name', $podcast_name, PDO::PARAM_STR);
		$check->execute();
		$results = $check->fetch(PDO::FETCH_ASSOC);
		$response['name'] = $podcast_name;
		$response['state'] = ($results['podcast_skip'] == '1' ? "off" : "on");
		return $response;
	}

	/**
	 * Update when a podcast was last downloaded
	 *
	 * @param integer $podcast_id
	 * @param DateTime $date
	 * @return void
	 */
	public function update_last_downloaded(int $podcast_id, DateTime $date) :void {
		$sql = "UPDATE podcasts SET podcast_last_downloaded = :date WHERE podcast_id = :id;";
		$update = $this->conn->prepare($sql);
		$date = $date->format("Y-m-d H:i:s");
		$update->bindParam(":date", $date, PDO::PARAM_STR);
		$update->bindParam(":id", $podcast_id, PDO::PARAM_INT);
		$update->execute();
	}
}