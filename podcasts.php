#!/usr/bin/env php
<?php
chdir(__DIR__);

require_once('vendor/autoload.php');
require_once('PodcastController.php');

// Set up commandline options
$shortops  = "l::";
$shortops .= "m:";
$shortops .= "p:";
$shortops .= "y";
$shortops .= "t";
$shortops .= "x";
$shortops .= "a";
$shortops .= "n:";
$shortops .= "u:";

$controller = new PodcastController();

$options = getopt($shortops);

// List podcasts
if (isset($options['l'])) {
	$controller->list_podcasts(order: $options['l']);
	exit();
}

// Delete podcasts that have been listened to
if (isset($options['x'])) {
	$controller->clean_podcasts();
	exit();
}

// Add a new podcast to the database
if (isset($options['a'])) {
	if (empty($options['n']) || empty($options['u'])) {
		print "Podcast [n]ame and [u]rl must be specified\n";
		exit();
	}
	$controller->add_new_podcast(name: $options['n'], url: $options['u']);
	exit();
}

// Copy podcasts to iPod
if (isset($options['m'])) {
	$controller->move_podcasts(mode: $options['m']);
	exit();
}

//Togle podcast on or off - needs to also specify a podcast ID
if (isset($options['t'])) {
	if (isset($options['p'])) {
		$podcast_id = 0;
		$podcast_name = '';
		$podcast = $options['p'];
		if (intval($podcast) == 0) {
			$podcast_name = $podcast;
		} else {
			$podcast_id = $podcast;
		}
		$controller->toggle_podcast(podcast_id: $podcast_id, podcast_name: $podcast_name);
	} else {
		print "A podcast name or ID must be specified\n";
	}
	exit();
}


// Download podcasts
$podcast_id = 0;
$podcast_name = '';
$year = false;

if (isset($options['p'])) {
	$podcast = $options['p'];
	if (intval($podcast) == 0) {
		$podcast_name = $podcast;
	} else {
		$podcast_id = $podcast;
	}
}

if (isset($options['y'])) {
	$year = true;
}

$controller->download_podcasts(podcast_id: $podcast_id, podcast_name: $podcast_name, single_year: $year);