<?php
require_once('config.php');
require_once('functions.php');

/**
 * 	Available criteria (can be multiple, separate with semicolons, e.g., constituency=London;Bath;Bristol):
 * 	For yes/no criteria, acceptable flags = any of "1","Y","y","yes","true"
 * 	year
 * 	from_year
 * 	to_year
 * 	general_election_id
 * 	election_id
 * 	month
 * 	constituency (named)
 * 	countyboroughuniv (c, b, or u)
 * 	byelectiongeneral (b or g)
 * 	contested (takes acceptable flags)
 * 	include_results (who won and lost, takes acceptable flags)
 * 	include_votes (voter info and how they voted, takes acceptable flags)
 * 	has_data (restrict to those where we have polling data, takes acceptable flags)
 * 	candidate (candidate name
 */

$conn = new mysqli($servername, $username, $password, $dbname);

//initialize some variables, add geo
$sql = 	"SELECT e.*, c.lat, c.lng
	 	FROM elections e 
	 	JOIN constituencies c 
		ON c.constituency_id = e.constituency_id ";

$optimized = array(); //will enforce lowercase keys for $_GET array
$options = array(); //for building WHERE clause, derived from optimized $_GET variables
$big_array = array(); //will contain all user parameters in order, for use in prepared query

//lowercase all keys, eliminate inconsistency headaches
if(is_array($_GET)) {
	$optimized = optimizer($_GET);
}

if (isset($optimized["election_id"])) {
	$array = explode(";",$optimized['election_id']);
	$big_array = array_merge($big_array,$array);
	$options['election_id'] = "e.election_id IN ("
		.	str_repeat("?,",count($array)-1)
		.	"?)";
}

//candidate name?
if(isset($optimized['candidate']) && !empty($optimized['candidate'])) {
	$election_ids = get_elections_from_candidates($optimized['candidate']);
	$other_ids = array();
	// now we have election_ids BUT: did they also request specific election_ids?
	if(isset($optimized['election_id'])) {
		$other_ids = explode(";",$optimized['election_id']);
	}

	$array = array_unique(array_merge($election_ids,$other_ids));
	$big_array = $array; //starting over with big_array
	$options['election_id'] = "e.election_id IN ("
		.	str_repeat("?,",count($array)-1)
		.	"?)";

	$optimized['include_results'] = 1; //make sure to include results if candidate requested
}

//limit to elections with data?
if(isset($optimized['has_data'])) {
	$options[] = "e.has_data = 1";
}
//cast all years as integers for safety

//single year requested?
if(isset($optimized['year'])) {
	$options[] = "e.election_year = '"
	.	(int) $optimized['year']
	.	"'";
} else { //either single year OR a range, can't be both
	if (isset($optimized["from_year"])) {
		$options[] = "e.election_year >= '"
		.	(int) $optimized["from_year"]
		.	"'";
	}
	
	if (isset($optimized["to_year"])) {
		$options[] = "e.election_year <= '"
		.	(int) $optimized["to_year"]
		.	"'";
	}
}

if(isset($optimized['general_election_id'])) {
	$array = explode(";",$optimized['general_election_id']);
	$big_array = array_merge($big_array,$array);
	$options[] = "e.general_election_id IN ("
		.	str_repeat("?,",count($array)-1)
		.	"?)";
}

if (isset($optimized["month"])) {
	$array = explode(";",$optimized['month']);
	$ok_months = array();
	foreach($array as $month) {
		$month = strtolower($month);
		if(isset($months[$month])) {
			$ok_months[] = $months[$month]; //will be valid
		} else {
			$ok_months[] = $month; //could be valid or invalid but hey we did our best
		}
	}
	$big_array = array_merge($big_array,$ok_months);
	$options[] = "e.election_month IN ("
	.	str_repeat("?,",count($ok_months)-1)
	.	"?)";
}

if (isset($optimized["constituency"])) {
	$array = explode(";",$optimized['constituency']);
	foreach($array as &$constituency) {
		if(strstr($constituency,'Newcastle')) {
			if(strstr($constituency,'Lyme')) {
				$constituency = 'Newcastle-under-Lyme';
			} else $constituency = 'Newcastle-upon-Tyne';
		} elseif (strstr($constituency,'Berwick')) {
			$constituency = 'Berwick-upon-Tweed';
		} elseif (strstr($constituency,'Kingston')) {
			$constituency = 'Kingston-upon-Hull';
		}
	}
	$big_array = array_merge($big_array,$array);
	$options[] = "e.constituency IN ("
	.	str_repeat("?,",count($array)-1)
	.	"?)";
}

if (isset($optimized["countyboroughuniv"])) {
	$array = explode(";",$optimized['countyboroughuniv']);
	$big_array = array_merge($big_array,$array);
	$options[] = "e.countyboroughuniv IN ("
	.	str_repeat("?,",count($array)-1)
	.	"?)";
}

if (isset($optimized["byelectiongeneral"])) {
	$array = explode(";",$optimized['byelectiongeneral']);
	$big_array = array_merge($big_array,$array);
	$options[] = "e.by_election_general IN ("
	.	str_repeat("?,",count($array)-1)
	.	"?)";
}

if (isset($optimized["contested"])) {
	$array = explode(";",$optimized['contested']);
	$big_array = array_merge($big_array,$array);
	$options[] = "e.contested IN ("
	.	str_repeat("?,",count($array)-1)
	.	"?)";
	}

if(count($options)) {
	$sql .= "WHERE e.office = 'parliament' AND "
	.	implode(" AND ",$options);
}

$stmt  = $conn->prepare($sql); // prepare
$n = count($big_array);
if($n) {
	$types = str_repeat('s', $n); //types
	$stmt->bind_param($types, ...$big_array); // bind array at once
}
$stmt->execute();
$result = $stmt->get_result(); // get the mysqli result
$rows = $result->fetch_all(MYSQLI_ASSOC); // fetch the data
$years = getYearRange($rows);

if(isset($optimized['include_results']) && in_array($optimized['include_results'],$acceptable_flags)) {
	foreach($rows as &$row) {
		$results = election_results($row['election_id']);
		$row['results'] = count($results) ? $results : "information not available"; 
	}
}

//full vote details?
if(isset($optimized['include_votes']) && in_array($optimized['include_votes'],$acceptable_flags)) {
	foreach($rows as &$row) {
		$votes = get_votes($row['election_id']);
		$row['votes'] = count($votes) ? $votes : "information not available";
	}
}

//number of distinct voters
if(isset($optimized['include_voter_count']) && in_array($optimized['include_voter_count'],$acceptable_flags)) {
	foreach($rows as &$row) {
		$voter_count = voter_count($row['election_id']);
		$row['num_voters'] = $voter_count;
	}
}

$n = count($rows);
$response = array(
	"num_results"=>$n,
	"earliest_year"=>$years['earliest'],
	"latest_year"=>$years['latest'],
	"elections"=>$rows
);
if(empty($n)) {
	$response['earliest_year'] = "not applicable";
	$response['latest_year'] = "not applicable";
	$response['elections'] = "no elections found for criteria provided";
}
print json_encode($response,JSON_HEX_QUOT | JSON_HEX_TAG);
$conn->close();


?>


