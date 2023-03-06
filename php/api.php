<?php
require_once('config.php');
require_once('functions.php');
$conn = new mysqli($servername, $username, $password, $dbname);

$optimized = array();
$rows = array();
$keys = array();
$data = array();
$candidates = array();

//lowercase all keys, eliminate inconsistency headaches
if(is_array($_GET)) {
    $optimized = optimizer($_GET);
}

$election_id = get_election_id($optimized['constituency'],$optimized['year'],$optimized['month']);

if($election_id):
$candidates = get_candidates_from_election_id($election_id);
$occupations = get_voter_occupation_distribution($election_id);


foreach($occupations as $d) {
    $data[$d['candidate_id']][] = $d;
    $keys[$d['candidate_id']] = $d['candidate_id'];
}
foreach($keys as $candidate_id) {
    $candidates[$candidate_id]['voters'] = $data[$candidate_id];
}

endif;
header("Content-Type: application/json");

print json_encode($candidates,JSON_HEX_QUOT | JSON_HEX_TAG);
$conn->close();
