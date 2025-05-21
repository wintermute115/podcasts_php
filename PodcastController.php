<?php

require_once('MysqlConn.php');
require_once('PodcastCurl.php');
require_once('FileConn.php');

/**
 * Core controller module that handles interactions between different parts of the system
 */
class PodcastController {
	private $dbconn;
	private $curl;
	private $fileconn;
	private $colors;
	private $external_display;

	public function __construct() {
		$this->dbconn = new MysqlConn();
		$this->curl = new PodcastCurl();
		$this->fileconn = new FileConn();
		$this->colors = [
			'bright' => "\033[1;37m",
			'std' => "\033[0;37m",
			'grey' => "\033[2;37m",
			'end' => "\033[0m"
		];
		$this->external_display = "../ledmatrix/ledmatrix.py";
	}

	/**
	 * Display a list off the podcasts being tracked in the database
	 *
	 * @param string $order
	 * 		'd' - Display by date, most recently updated first
	 * 		'i' - Display by ID, first added to the system first
	 * 		'n' - Display by name, alphabetically
	 * @return void
	 */
	public function list_podcasts(string $order = 'd') :void {
		$list = $this->dbconn->list_podcasts($order);

		$max_name = 0;
		foreach ($list as $item) {
			$max_name = max($max_name, strlen($item['podcast_name']));
		}
		echo $this->colors['bright'];
		echo str_pad("ID", 4);
		echo str_pad("Title", $max_name + 1);
		echo "Last Recieved";
		echo $this->colors['end'];
		echo "\n";

		foreach($list as $item) {
			if($item['podcast_skip'] == 1) {
				echo $this->colors['grey'];
			} else {
				echo $this->colors['std'];
			}
			echo str_pad($item['podcast_id'], 4);
			echo str_pad($item['podcast_name'], $max_name + 1);
			echo $item['podcast_last_downloaded'];
			echo $this->colors['end'];
			echo "\n";
		}
	}

	/**
	 * Download the podcasts, either a single specified feed, or all that aren't marked to be ignored
	 *
	 * @param integer $podcast_id
	 * @param string $podcast_name
	 * @param boolean $single_year
	 * @return void
	 */
	public function download_podcasts(int $podcast_id = 0, string $podcast_name = '', bool $single_year = false) :void {
		if ($this->fileconn->check_lockfile()) {
			echo "Another download is in progress. Please try again later.\n";
			return;
		}

		if (!$this->curl->test_connection()) {
			echo "No internet connection\n";
			exit(1);
		}

		$downloaded = 0;

		$temporary_playlist = [];

		$this->fileconn->create_lockfile();
		$list = $this->dbconn->list_podcasts(order: 'n');
		foreach($list as $item) {
			$last_download = DateTime::createFromFormat('Y-m-d H:i:s', $item['podcast_last_downloaded']);
			if ($podcast_id === $item['podcast_id'] || $podcast_name === $item['podcast_name'] || ($podcast_id === 0 && $podcast_name === '' && $item['podcast_skip'] === '0')) {
				echo $item['podcast_name'] . "\n";
				$podcasts = $this->curl->download_feed(url: $item['podcast_feed'], start_date: $item['podcast_last_downloaded'], single_year: $single_year);
				$count_podcasts = 0;
				foreach($podcasts as $podcast) {
					$count_podcasts++;
					$duration = $podcast['duration'] ?? "??:??";
					$podcast_date = DateTime::createFromFormat('Y-m-d H:i:s', $podcast['date']);
					$length = $podcast['length'] ?? 0;
					$bitstream = $this->curl->get_podcast(url: $podcast['url'], title: $podcast['title'], duration: $duration, length: $length);
					$location = $this->fileconn->save_podcast(bitstream: $bitstream, podcast_name: $item['podcast_name'], episode_title: $podcast['title'], date: $podcast_date);
					$temporary_playlist[$podcast['date'] . $item['podcast_name']]= $location;
					$downloaded++;
					$last_download = max($last_download, $podcast_date);
					$this->fileconn->write_download_log(title: $podcast['title'], filename: basename($location), duration: $duration, desc: $podcast['desc']);
				}
				if ($count_podcasts > 0) {
					$this->dbconn->update_last_downloaded(podcast_id: $item['podcast_id'], date: $last_download);
				}
			}
		}
		echo $this->colors['bright'];
		echo "Downloaded " . $downloaded . ($downloaded == 1 ? " podcast" : " podcasts");
		echo $this->colors['end'];
		echo "\n";
		ksort($temporary_playlist);
		$playlist = '';
		foreach($temporary_playlist as $playlist_item) {
			$playlist .= $playlist_item . "\n";
		}

		if ($downloaded > 0) {
			$this->fileconn->save_playlist($playlist);
			$this->dbconn->mysqldump($this->fileconn->mysqldump_location);
		}
		$this->fileconn->remove_lockfile();
		$this->external_display();
	}

	/**
	 * Toggle whether or not a feed is set to be downloaded
	 *
	 * @param integer $podcast_id
	 * @param string $podcast_name
	 * @return voidToggle the status 
	 */
	public function toggle_podcast(int $podcast_id, string $podcast_name) :void {
		$response = [];
		if ($podcast_id !== 0) {
			$response = $this->dbconn->toggle_podcast_by_id(podcast_id: $podcast_id);
		} else {
			$response = $this->dbconn->toggle_podcast_by_name(podcast_name: $podcast_name);
		}
		echo "Podcast \"" . $response['name'] . "\" is now " . $response['state'] . ".\n";
		$this->fileconn->write_toggle_log(name: $response['name'], state: $response['state']);
	}

	/**
	 * Move downloaded podcasts to the iPod
	 *
	 * @param string $mode
	 * @return void
	 */
	public function move_podcasts(string $mode) :void {
		if (!in_array($mode, ["a", "i", "o"])) {
			print "A valid mode has not been set. Can be [a]ppend, [i]nsert or [o]verwrite.\n";
			return;
		}
		$result = $this->fileconn->move_podcasts();
		print $result['message'];
		if (!$result['success']) {
			return;
		}
		$this->fileconn->copy_playlist(mode: $mode);
		$this->fileconn->write_move_log(mode: $mode, message: chop($result['message']));
		$this->fileconn->backup(folder: "Podcasts", delete: true);
		$this->fileconn->backup(folder: "Music", delete: false);
		$this->fileconn->backup(folder: "Playlists", delete: true);
		$this->external_display();
	}

	/**
	 * Remove all podcasts that are before the current point in the playlist
	 *
	 * @return void
	 */
	public function clean_podcasts() :void {
		$deleted = $this->fileconn->clean_podcasts();
		// Print out data
		$total = 0;
		$max_len = 0;
		$max_num = 0;
		ksort($deleted);
		foreach ($deleted as $podcast => $count) {
			$total += $count;
			$max_len = max($max_len, strlen($podcast));
			$max_num = max($max_num, $count);
		}
		$total_podcasts = count($deleted);
		echo $this->colors['bright'];
		echo $total . ($total === 1 ? " episode" : " episodes") . " of " . $total_podcasts . ($total_podcasts === 1 ? " podcast" : " podcasts") . " have been deleted.";
		echo $this->colors['end'] . "\n";
		echo str_pad("", $max_len + $max_num + 2, '-') . "\n";
		foreach ($deleted as $podcast => $count) {
			echo str_pad($podcast, $max_len) . "  " . str_pad("", $count, "X") . "\n";
		}
	}

	/**
	 * Add a new podcast into the database to be downloaded
	 *
	 * @param string $name
	 * @param string $url
	 * @return void
	 */
	public function add_new_podcast(string $name, string $url) :void {
		$feed_start_date = $this->curl->get_feed_start_date(url: $url);
		if ($this->dbconn->add_new_podcast(name: $name, url: $url, date: $feed_start_date)) {
			echo "Podcast \"$name\" [$url] has been added to the library\n";
			$this->fileconn->write_add_log(name: $name, url: $url);
		} else {
			"Could not add new podcasts to the library\n";
		}
	}

	private function external_display() :void {
		if ($this->external_display != '' && file_exists($this->external_display) && is_executable(($this->external_display))) {
			exec($this->external_display);
		} 
	}
}