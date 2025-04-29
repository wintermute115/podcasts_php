<?php

require_once('vendor/simplepie/autoloader.php');

/**
 * Handles all connections to the internet
 */
class PodcastCurl {
	private $uastring;
	private $parser;

	public function __construct() {
		$this->uastring = "User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:30.0) Gecko/20100101 Firefox/30.0";
		$this->parser = new SimplePie\SimplePie();
	}


	/**
	 * Ensure we have an active connection to the internet
	 *
	 * @return boolean
	 */
	public function test_connection() :bool {
		$ch = curl_init("https://www.google.com/");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
		curl_setopt($ch, CURLOPT_CONNECT_ONLY, true);
		if (curl_exec($ch) === false) {
			return false;
		}
		return true;
	}

	/**
	 * Get a list of all episodes in a feed waiting to be downlaoded
	 *
	 * @param string $url
	 * @param string $start_date
	 * @return array
	 */
	public function download_feed(string $url, string $start_date, bool $single_year) :array {
		$last_date = DateTime::createFromFormat('Y-m-d H:i:s', $start_date);
		// If the year flag is set, skip back that far before downloading
		$end_date = ($single_year ? $last_date : new DateTime());
		$end_date->add(DateInterval::createFromDateString("1 year"));
		$items = [];
		$this->parser->set_feed_url($url);
		$this->parser->init();
		$this->parser->handle_content_type();
		$entries = $this->parser->get_items();
		foreach($entries as $entry) {
			$item_date = DateTime::createFromFormat('Y-m-d H:i:s', $entry->get_date('Y-m-d H:i:s'));
			if ($item_date > $last_date && $item_date <= $end_date) {
				$enclosure = $entry->get_enclosure();
				if ($enclosure && ($enclosure->get_medium() == 'audio' || $enclosure->get_handler() == 'mp3')) {
					$item = [
						"title" => $entry->get_title(),
						"url" => $enclosure->get_link(),
						"date" => $item_date->format('Y-m-d H:i:s'),
						"duration" => $enclosure->get_duration(true),
						"length" => $enclosure->get_length(),
						"desc" => preg_replace("/\s{2, }/", " ", str_replace("\n", " ", strip_tags($enclosure->get_description() ?? $entry->get_description())))
					];
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	/**
	 * Download an episode of a podcast
	 *
	 * @param string $url
	 * @param string $title
	 * @param string $duration
	 * @param integer $length
	 * @return string
	 */
	public function get_podcast(string $url, string $title, string $duration, int $length) :string {
		echo "Downloading \"" . $title . "\" [" . $duration . "]\n";
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_NOPROGRESS, false );
		curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, [$this, 'show_status']);
		$file = curl_exec($ch);
		curl_close($ch);
		$length = number_format($length / 1024);
		echo str_pad('', 40) . "\r";
		echo "$length Kb\n";
		return $file;
	}

	/**
	 * Show the amount downloaded in realtime
	 *
	 * @param CurlHandle $ch
	 * @param integer $total_size
	 * @param integer $downloaded
	 * @param integer $to_upload
	 * @param integer $uploaded
	 * @return integer
	 */
	private function show_status(CurlHandle $ch, int $total_size, int $downloaded, int $to_upload, int $uploaded) : int {
		echo "Downloaded " . number_format($downloaded / 1024) . " of " . number_format($total_size / 1024) . "Kb\r";
		return 0;
	}
}
