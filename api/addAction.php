<?php
// File: api/addAction.php
// Purpose: Log an action with locationID

// --- Output hygiene (strip any prepended junk)
while (ob_get_level()) { @ob_end_clean(); }
@ini_set('zlib.output_compression', '0');
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

// Helpers
function sendJson($arr, $code=200){
    $payload = json_encode($arr);
    if ($payload === false) $payload = '{"status":"error","message":"JSON encode failed"}';
    http_response_code($code);
    $buf = ob_get_contents();
    if ($buf !== false && strlen($buf)) { @ob_clean(); }
    echo $payload;
    exit;
}
function bad($msg, $code=400){ sendJson(array('status'=>'error','message'=>$msg), $code); }
function ok($data=array(), $code=200){ sendJson(array('status'=>'ok') + $data, $code); }

// Config (absolute path)
$jsonPath = '/home/notyou64/public_html/data/skyesoft-data.json';

// Read JSON body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);
if (!is_array($body)) bad('Invalid JSON body');

// Load data file
if (!is_readable($jsonPath)) bad('Data file not readable', 500);
$dataJson = file_get_contents($jsonPath);
$data = json_decode($dataJson, true);
if (!is_array($data)) bad('Data file corrupt', 500);

// Ensure arrays exist
if (!isset($data['actions']) || !is_array($data['actions'])) $data['actions'] = array();
if (!isset($data['actionTypes']) || !is_array($data['actionTypes'])) $data['actionTypes'] = array();

// Pull actionTypes (required)
$actionTypes = $data['actionTypes'];
if (empty($actionTypes)) bad('No action types found in data file', 500);

// Required fields
$reqFields = array('actionTypeID', 'actionContactID');
foreach ($reqFields as $k) {
    if (!isset($body[$k])) bad('Missing required field: ' . $k);
}

// Validate actionTypeID
$validTypeIds = array();
foreach ($actionTypes as $t) {
    if (isset($t['actionTypeID'])) $validTypeIds[] = (int)$t['actionTypeID'];
}
if (!in_array((int)$body['actionTypeID'], $validTypeIds, true)) bad('Unknown actionTypeID');

// Next actionID (scan existing)
$nextId = 1;
if (!empty($data['actions'])) {
    $ids = array();
    foreach ($data['actions'] as $a) {
        if (isset($a['actionID'])) $ids[] = (int)$a['actionID'];
    }
    if (!empty($ids)) $nextId = max($ids) + 1;
}

// Init ms timestamp (fallback)
$nowMs = (int) round(microtime(true) * 1000);

// Get place_id and locationID from places_reverse.php
$placeId = null;
$locationID = null;
if (isset($body['actionLatitude']) && isset($body['actionLongitude'])) {
    $url = 'http://localhost/skyesoft/api/places_reverse.php?lat=' . urlencode($body['actionLatitude']) . '&lng=' . urlencode($body['actionLongitude']);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ));
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        bad('Failed to fetch place_id: ' . $err, 500);
    }
    
    $placeData = json_decode($response, true);
    if (is_array($placeData)) {
        $placeId = isset($placeData['place_id']) ? $placeData['place_id'] : null;
        $locationID = isset($placeData['locationID']) ? $placeData['locationID'] : null;
    } else {
        bad('Invalid response from places_reverse.php', 500);
    }
}

// Use client-provided place_id if available and no locationID matched
if ($locationID === null && isset($body['actionGooglePlaceId']) && $body['actionGooglePlaceId'] !== '') {
    $placeId = (string)$body['actionGooglePlaceId'];
}

// Build action (whitelist fields)
$action = array(
    'actionID'            => $nextId,
    'actionTypeID'        => isset($body['actionTypeID']) ? (int)$body['actionTypeID'] : 0,
    'actionContactID'     => isset($body['actionContactID']) ? (int)$body['actionContactID'] : 0,
    'actionNote'          => isset($body['actionNote']) ? substr(trim((string)$body['actionNote']), 0, 500) : '',
    'actionLatitude'      => isset($body['actionLatitude']) ? (float)$body['actionLatitude'] : null,
    'actionLongitude'     => isset($body['actionLongitude']) ? (float)$body['actionLongitude'] : null,
    'actionGooglePlaceId' => $placeId,
    'locationID'          => $locationID, // New field, null if no unambiguous match
    'actionTimestamp'     => isset($body['actionTimestamp']) ? (int)$body['actionTimestamp'] : $nowMs,
    'actionMeta'          => array(
        'ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        'source'    => 'addAction.php'
    )
);

// Append and persist
$data['actions'][] = $action;

$fp = fopen($jsonPath, 'c+');
if (!$fp) bad('Unable to open data file for writing', 500);
if (!flock($fp, LOCK_EX)) { fclose($fp); bad('Unable to lock data file', 500); }

rewind($fp);
$encoded = json_encode($data, JSON_PRETTY_PRINT);
if ($encoded === false) {
    flock($fp, LOCK_UN); fclose($fp);
    bad('JSON encode failed', 500);
}

ftruncate($fp, 0);
$w = fwrite($fp, $encoded);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
if ($w === false) bad('Write failed', 500);

// Success
ok(array('actionID' => $nextId), 201);
?>