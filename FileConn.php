<?php
use Id3\Id3Parser;
use PhpRsync\Rsync;

/**
 * Handles all interactions with the filesystem
 */
class FileConn {
	private $ipod;
	private $download_loc;
	private $lockfile;
	private $logfile;
	private $bookmarks;
	private $bookmark_regex;
	private $playlist;
	private $download_playlist;
	private $backup_location;
	public $mysqldump_location;

	public function __construct() {
		$this->ipod = "/media/ross/iPodClassic/";
		// $this->ipod = "/home/ross/ipod/";
		$this->download_loc = "/home/ross/Downloads/New_Podcasts/";
		$this->lockfile = $this->download_loc . "podcasts.lock";
		$this->logfile = "/home/ross/scripts/podcasts_php/logs/podcasts_" . date("Y") . ".log";
		$this->bookmarks = $this->ipod . ".rockbox/most-recent.bmark";
		$this->bookmark_regex = "/^>\d*;(\d*);(?:\d*;){7}\/Playlists\/(.*)\.m3u8/";
		$this->playlist = $this->ipod . "Playlists/Podcasts.m3u8";
		$this->download_playlist = $this->download_loc . "Playlists/Podcasts.m3u8";
		$this->backup_location = "/home/ross/Documents/ipod/";
		$this->mysqldump_location = "/home/ross/pCloudDrive/podcasts.sql";
	}

	/**
	 * Creates a lockfile so the system doesn't try doing two things at once
	 *
	 * @return void
	 */
	public function create_lockfile() :void {
		touch($this->lockfile);
	}

	/**
	 * Frees up the lockfile once the job is complete
	 *
	 * @return void
	 */
	public function remove_lockfile() :void {
		unlink($this->lockfile);
	}

	/**
	 * Checks to see if the lockfile exists
	 *
	 * @return boolean
	 */
	public function check_lockfile() :bool {
		if (file_exists($this->lockfile)) {
			$file_created = DateTimeImmutable::createFromFormat("U", filemtime($this->lockfile));
			$now = new DateTimeImmutable();
			$max_age = DateInterval::createFromDateString("2 hours");
			$limit = $file_created->add($max_age);
			if ($limit < $now) {
				// If the lockfile is more than 2 hours old, delete it.
				$this->remove_lockfile();
				return false;
			} else {
				return true;
			}
		} else {
			return false;
		}
	}

	/**
	 * Chacks to see if the iPod is attached
	 *
	 * @return boolean
	 */
	public function ipod_attached() :bool {
		return file_exists($this->ipod);
	}	

	/**
	 * Counts the number of podcast episodes waiting to be copied over
	 *
	 * @return integer
	 */
	public function count_podcasts() :int {
		if (!file_exists($this->download_loc . "Podcasts/")) {
			return 0;
		}
		return count(glob($this->download_loc . "Podcasts/*"));
	}

	/**
	 * Creates a filename for a podcast
	 *
	 * @param integer $len
	 * @return string
	 */
	private function create_random_name(?DateTime $date = null, int $len=20) :string {
		$output = '';
		if (!empty($date)) {
			$output .= $date->format("Ymd_His_");
		}
		$len -= strlen($output);
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$alphabet_len = strlen($alphabet);
		for ($i=0; $i<$len; $i++) {
			$output .= $alphabet[random_int(0, $alphabet_len - 1)];
		}

		return $output . ".mp3";
	}

	/**
	 * If the specified filepath doesn't exist, create it
	 *
	 * @param string $path
	 * @return void
	 */
	private function create_path(string $path) :void {
		$path_part = "";
		$directories = explode("/", $path);
		foreach ($directories as $directory) {
			$path_part .= $directory . "/";
			if(!is_dir($path_part)) {
				mkdir($path_part);
			}
		}
	}

	/**
	 * Move podcasts onto the iPod
	 *
	 * @return array
	 */
	public function move_podcasts() :array {
		if($this->check_lockfile()) {
			return ["success" => false, "message" => "A download is in progress; Please try again later.\n"];
		}
		if(!$this->ipod_attached()) {
			return ["success" => false, "message" => "iPod not attached!\n"];
		}
		if($this->count_podcasts() == 0) {
			return ["success" => false, "message" => "No podcasts to copy!\n"];
		}
		//Copy files
		$this->create_lockfile();
		echo "Copying files… ";
		$counts = $this->movedir(from: $this->download_loc . "Podcasts/", to: $this->ipod . "Podcasts/");
		if ($counts['error'] > 0) {
			return ["success" => false, "message" => "Error: Could not copy podcasts\n"];
		}
		$output  = $counts['files'];
		$output .= ($counts['files'] === 1 ? " episode" : " episodes") . " of ";
		$output .= $counts['dirs'];
		$output .= ($counts['dirs'] === 1 ? " podcast" : " podcasts") . " copied over.\n";
		echo "Done.\n";
		$this->remove_lockfile();
		return ["success" => true, "message" => $output];
	}

	/**
	 * Integrate the new podcasts into the iPod's playlist
	 *
	 * @param string $mode
	 * 		'a' - Append - Add new items to the end of the playlist
	 * 		'i' - Insert - Add new items after the currently playing item
	 * 		'o' - Overwrite - Delete the current playlist and replace it
	 * @return boolean
	 */
	public function copy_playlist(string $mode) :bool {
		$fh = fopen($this->download_playlist, "r");
		$playlist = fread($fh, filesize($this->download_playlist));
		fclose($fh);
		echo "Writing playlist… ";
		if ($mode == 'a') {
			$fh = fopen($this->playlist, "a");
			fwrite($fh, $playlist);
			fclose($fh);
		} elseif ($mode == "o") {
			// Create a copy of the existing playlist, in case we need to roll back
			copy($this->playlist, $this->playlist . ".old");
			$fh = fopen($this->playlist, "w");
			fwrite($fh, $playlist);
			fclose($fh);
		} elseif ($mode == "i") {
			$bookmark_list = file($this->bookmarks);
			foreach($bookmark_list as $bookmark) {
				preg_match($this->bookmark_regex, $bookmark, $matches);
				if ($matches[2] === 'Podcasts') {
					$position = $matches[1];
					$podcast_array = file($this->playlist);
					$new_podcast_list = "";
					for ($i=0; $i<=$position;$i++) {
						$new_podcast_list .= array_shift($podcast_array);
					}
					$new_podcast_list .= $playlist;
					$new_podcast_list .= implode("", $podcast_array);
					$fh = fopen($this->playlist, "w");
					fwrite($fh, $new_podcast_list);
					fclose($fh);
				}
			}

		}
		// Wipe of the temporary playlist
		$fh = fopen($this->download_playlist, 'w');
		fwrite($fh, '');
		fclose($fh);
		echo "Done.\n";
		return true;
	}

	/**
	 * Save a downloaded podcast so it's ready to be copied to the iPod
	 *
	 * @param string $bitstream
	 * @param string $podcast_name
	 * @param DateTime $date
	 * @return string
	 */
	public function save_podcast(string $bitstream, string $podcast_name, string $episode_title, DateTime $date) :string {
		$filename = $this->create_random_name(date: $date);
		$this->create_path($this->download_loc . "Podcasts/" . $podcast_name . "/");
		$path = "Podcasts/" . $podcast_name . "/" . $filename;
		$fh = fopen($this->download_loc . $path, "wb");
		fwrite($fh, $bitstream);
		fclose($fh);

		// Make sure a title has been
		$id3 = new getID3();
		$id3->setOption(array('encoding' => 'UTF-8'));
		$tag_info = $id3->analyze($this->download_loc . $path);
		$id3->CopyTagsToComments($tag_info);
		if (!isset($tag_info['comments_html']['title'])) {
			$tagwriter = new getid3_writetags();
			$tagwriter->filename = $this->download_loc . $path;
			$tagwriter->remove_other_tags = false;
			$tag_data = [];
			$tag_data['title'][0] = html_entity_decode($episode_title, ENT_QUOTES);
			$tagwriter->tag_data = $tag_data;
			$tagwriter->tagformats = ['id3v1', 'id3v2.3'];
			$tagwriter->WriteTags();
		}

		// Make sure embedded images are in the right format
		if (isset($tag_info['id3v2']['APIC'])) {
			$jpgfile = $this->download_loc . "temp.jpg";
			$image = $tag_info['id3v2']['APIC'][0]['data'];
			$gd_image = imagecreatefromstring($image);
			if ($gd_image) {
				$gd_image = imagescale($gd_image, 500);
				imageinterlace($gd_image, false);
	
				imagejpeg($gd_image, $jpgfile);
	
				$fh = fopen($jpgfile, "rb");
				$memimage = fread($fh, filesize($jpgfile));
				fclose($fh);
				unlink($jpgfile);
	
				$tagwriter = new getid3_writetags();
				$tagwriter->filename = $this->download_loc . $path;
				$tagwriter->tagformats = ['id3v2.3'];
				$tagwriter->remove_other_tags = false;
				$tagdata = [];    
				foreach (['title', 'album', 'artist', 'genre', 'year'] as $tag) {
					if (!isset($tag_info['comments'][$tag])) {
						$tag_info['comments'][$tag] = [''];
					}
					$tagdata[$tag][0] = $tag_info['comments'][$tag][0];
				}
				$tagdata['attached_picture'][0]['data'] = $memimage;
				$tagdata['attached_picture'][0]['picturetypeid'] = 0x03;
				$tagdata['attached_picture'][0]['mime'] = 'image/jpeg';
				$tagdata['attached_picture'][0]['description'] = 'Episode Art';
	
				$tagwriter->tag_data = $tagdata;
				$tagwriter->WriteTags();	
			}
		}

		return "/" . $path;
	}

	/**
	 * Save the list of downloaded podcast in playlist format
	 *
	 * @param string $playlist
	 * @return void
	 */
	public function save_playlist(string $playlist) : void {
		echo "Creating playlist… ";
		$this->create_path($this->download_loc . "Playlists/");
		$fh = fopen($this->download_playlist, "a");
		fwrite($fh, $playlist);
		fclose($fh);
		echo "Done\n";
		return;
	}

	/**
	 * Write to the logfile
	 *
	 * @param string $message
	 * @return void
	 */
	private function write_to_log(string $message) :void {
		$fh = fopen($this->logfile, "a");
		fwrite($fh, $message);
		fclose($fh);
	}

	/**
	 * Get the timestamp formatted for the log entries
	 *
	 * @return string
	 */
	private function log_timestamp() :string {
		$now = new DateTime();
		$time = $now->format("Y-m-d H:i:s") . " -- ";
		return $time;
	}

	/**
	 * Write a log entry saying an episode has been downloaded
	 *
	 * @param string $title
	 * @param string $filename
	 * @param string $duration
	 * @param string $desc
	 * @return void
	 */
	public function write_download_log(string $title, string $filename, string $duration, string $desc) :void {
		$log_entry  = $this->log_timestamp();
		$log_entry .= "Downloading \"$title\" ";
		$log_entry .= "[$filename] - ";
		$log_entry .= "[$duration]\n";
		$log_entry .= str_pad("", 23);
		$log_entry .= "$desc\n";
		$this->write_to_log($log_entry);
	}

	/**
	 * Write a log entry saying podcasts have been copied to the iPod
	 *
	 * @param string $mode
	 * @param string $message
	 * @return void
	 */
	public function write_move_log(string $mode, string $message) : void {
		$log_entry  = $this->log_timestamp();
		$log_entry .= match($mode) {
			'a' => "Append mode",
			'i' => "Insert mode",
			'o' => "Overwrite mode"
		};
		$log_entry .= " - " . $message . "\n";
		$log_entry .= "-------------------\n";
		$this->write_to_log($log_entry);
	}

	/**
	 * Write a log entry saying a podcast has been toggled on or off
	 *
	 * @param string $name
	 * @param string $state
	 * @return void
	 */
	public function write_toggle_log(string $name, string $state) :void {
		$log_entry = $this->log_timestamp();
		$log_entry .= "Podcast \"" . $name . "\" is now " . $state . "\n";
		$this->write_to_log($log_entry);
	}

	/**
	 * Write a log entry saying a new podcast has been added
	 *
	 * @param string $name
	 * @param string $url
	 * @return void
	 */
	public function write_add_log(string $name, string $url) :void {
		$log_entry  = $this->log_timestamp();
		$log_entry .= "Podcast \"$name\" at $url has been added (currently disabled)\n";
		$this->write_to_log($log_entry);
	}

	/**
	 * Remove all podcasts that are before the current point in the playlist
	 *
	 * @return array
	 */
	public function clean_podcasts() :array {
		$podcasts_deleted = [];
		$bookmarks_rebuilt = "";
		// Get the location from the recent bookmarks file
		$bookmark_list = file($this->bookmarks);
		foreach($bookmark_list as $bookmark) {
			preg_match($this->bookmark_regex, $bookmark, $matches);
			if ($matches[2] === 'Podcasts') {
				// Reset the playlist counter to 0
				preg_replace("/^>\d*;(\d*)/", "0", $bookmark);

				$position = $matches[1];
				// Get the playlist file
				$playlist = file($this->playlist);
				for ($i=0; $i<$position; $i++) {
					$delete = array_shift($playlist);
					echo "Deleting " . $delete;
					unlink(chop($this->ipod . $delete));
					$folder_regex = "/^.*\/Podcasts\/([^\/]*)\//";
					preg_match($folder_regex, $delete, $matches);
					$folder = $matches[1];
					if (isset($podcasts_deleted[$folder])) {
						$podcasts_deleted[$folder]++;
					} else {
						$podcasts_deleted[$folder] = 1;
					}
					// Write the remains of the playlist back to disk
					$fh = fopen($this->playlist, "w");
					fwrite($fh, implode("", $playlist));
					fclose($fh);
				}
				// Reset the playlist counter to 0
				$bookmark = preg_replace("/^(>\d*;)(\d*)/", '${1}0', $bookmark);
			}
			$bookmarks_rebuilt .= $bookmark;
		}
		$fh = fopen($this->bookmarks, "w");
		fwrite($fh, $bookmarks_rebuilt);
		fclose($fh);
		return $podcasts_deleted;
	}
	
	/**
	 * Move a directory and all contents from one location to another
	 *
	 * @param string $from
	 * @param string $to
	 * @return array
	 */
	private function movedir(string $from, string $to) :array {
		$results = ["dirs" => 0, "files" => 0, "error" => 0];
		$dir = opendir($from);
		while (($file = readdir($dir)) !== false) {
			if ($file != "." && $file != "..") {
				if (is_dir($from . $file)) {
					if (!is_dir($to . $file)) {
						mkdir($to . $file);
					}
					$subset = $this->movedir($from . $file . "/", $to . $file . "/");
					$results["dirs"] += $subset["dirs"] + 1;
					$results["files"] += $subset["files"];
					$results['error'] += $subset["error"];
				} else {
					if (copy($from . $file, $to . $file)) {
						$results["files"]++;
					} else {
						$results['error']++;
					}
				}
			}
		}
		if ($results['error'] == 0) {
			array_map('unlink', glob("$from/*.*"));
			rmdir($from);
		}
		return $results;
	}

	/**
	 * Backup content after copying, in case of disaster
	 *
	 * @param string $folder
	 * @param boolean $delete
	 * @return void
	 */
	public function backup(string $folder, bool $delete=false) :void {
		echo "Backing up " . strtolower($folder) . "… ";
		$this->create_path($this->backup_location . $folder . "/");
		$local = new PhpRsync\Connection('local', $this->backup_location);
		$rsync = new Rsync($local);
		$options = [
			'archive' => true,
			'delete' => $delete
		];
		$rsync->run($this->ipod . $folder . "/", $folder . "/", $options);
		echo "Done.\n";
	}
}
