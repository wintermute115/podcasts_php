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
		try {
			$dsn = "mysql:dbname={$this->database};host={$this->host}";
			$this->conn = new PDO($dsn, $this->user, $this->pass);
		} catch (Exception $e) {
			echo "Cannot connect to database\n";
			exit(1);
		}
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
		if ($check->execute()) {
			$results = $check->fetch(PDO::FETCH_ASSOC);
			$response['name'] = $results['podcast_name'];
			$response['state'] = ($results['podcast_skip'] == '1' ? "off" : "on");
		} else {
			echo "Cannot get podcast list\n";
			exit(1);
		}
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
		if ($check->execute()) {
			$results = $check->fetch(PDO::FETCH_ASSOC);
			$response['name'] = $podcast_name;
			$response['state'] = ($results['podcast_skip'] == '1' ? "off" : "on");	
		} else {
			echo "Cannot update database";
			exit(1);
		}
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

	/**
	 * Add a new podcast to the database
	 *
	 * @param string $name
	 * @param string $url
	 * @return boolean
	 */
	public function add_new_podcast(string $name, string $url, DateTime $date) :bool {
		$date = $date->modify("-1 day");
		$date = $date->format("Y-m-d 00:00:00");
		$sql = <<<EOT
INSERT INTO podcasts
	(podcast_name, podcast_feed, podcast_last_downloaded, podcast_skip)
VALUES
	(:name, :url, :date, '1');
EOT;
		$insert = $this->conn->prepare($sql);
		$insert->bindParam(':name', $name, PDO::PARAM_STR);
		$insert->bindParam(':url', $url, PDO::PARAM_STR);
		$insert->bindParam(':date', $date, PDO::PARAM_STR);
		return $insert->execute();
	}

	/**
	 * Updates the URL for a feed. Triggered when a 301/302 is found
	 *
	 * @param integer $podcast_id
	 * @param string $podcast_url
	 * @return boolean
	 */
	public function update_url(int $podcast_id, string $podcast_url): bool {
		$sql = <<<EOT
UPDATE podcasts 
SET podcast_feed = :url
WHERE podcast_id = :id;
EOT;
	$update = $this->conn->prepare($sql);
	$update->bindParam(':url', $podcast_url, PDO::PARAM_STR);
	$update->bindParam(':id', $podcast_id, PDO::PARAM_INT);
	return $update->execute();
}

	/**
	 * Create a backup of the database in case of disaster
	 *
	 * @param string $location
	 * @return void
	 */
	public function mysqldump(string $location) :void {
		exec("mysqldump -u" . $this->user . " -p" . $this->pass . " " . $this->database . " > " . $location . " 2>/dev/null");
	}
}
