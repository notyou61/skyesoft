<?php
// /skyesoft/api/addAction.php — log an action (PHP 5.x safe)

// (output hygiene)
while (ob_get_level()) { @ob_end_clean(); }
@ini_set('zlib.output_compression', '0');
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/env_boot.php';

// (helpers)
function sendJson($arr, $code=200){
  $payload = json_encode($arr);
  if ($payload === false) $payload = '{"status":"error","message":"JSON encode failed"}';
  http_response_code($code);
  $buf = ob_get_contents();
  if ($buf !== false && strlen($buf)) { @ob_clean(); }
  echo $payload; exit;
}
function bad($msg,$code=400){ sendJson(array('status'=>'error','message'=>$msg),$code); }
function ok($data=array(),$code=200){ sendJson(array('status'=>'ok') + $data,$code); }

// (distance meters)
function dist_m($lat1,$lon1,$lat2,$lon2){
  $R=6371000; $toRad=M_PI/180;
  $dLat=($lat2-$lat1)*$toRad; $dLon=($lon2-$lon1)*$toRad;
  $a=sin($dLat/2)*sin($dLat/2)+cos($lat1*$toRad)*cos($lat2*$toRad)*sin($dLon/2)*sin($dLon/2);
  return 2*$R*atan2(sqrt($a),sqrt(1-$a));
}

// (data path)
$jsonPath = '/home/notyou64/public_html/data/skyesoft-data.json';

// (read JSON body)
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) bad('Invalid JSON body');

// (load data file)
if (!is_readable($jsonPath)) bad('Data file not readable', 500);
$data = json_decode(file_get_contents($jsonPath), true);
if (!is_array($data)) bad('Data file corrupt', 500);

// (ensure arrays)
if (!isset($data['actions'])     || !is_array($data['actions']))     $data['actions']     = array();
if (!isset($data['actionTypes']) || !is_array($data['actionTypes'])) $data['actionTypes'] = array();
if (!isset($data['locations'])   || !is_array($data['locations']))   $data['locations']   = array();

// (required fields)
$req = array('actionTypeID','actionContactID');
foreach ($req as $k){ if (!isset($body[$k])) bad('Missing required field: '.$k); }

// (validate actionTypeID)
$validTypeIds = array();
foreach ($data['actionTypes'] as $t){ if (isset($t['actionTypeID'])) $validTypeIds[]=(int)$t['actionTypeID']; }
if (!in_array((int)$body['actionTypeID'], $validTypeIds, true)) bad('Unknown actionTypeID');

// (next actionID)
$nextId = 1;
if (!empty($data['actions'])){
  $ids = array();
  foreach ($data['actions'] as $a){ if (isset($a['actionID'])) $ids[]=(int)$a['actionID']; }
  if (!empty($ids)) $nextId = max($ids)+1;
}

// (timestamp ms)
$nowMs = (int) round(microtime(true)*1000);

// (lookup place via public endpoint)
$placeId = null;
$actionLocationID = null;
$latProvided = isset($body['actionLatitude']);
$lngProvided = isset($body['actionLongitude']);

if ($latProvided && $lngProvided) {
  $lat = (float)$body['actionLatitude'];
  $lng = (float)$body['actionLongitude'];

  $url = 'https://skyelighting.com/skyesoft/api/places_reverse.php?lat='
       . urlencode($lat) . '&lng=' . urlencode($lng) . '&ts=' . time();

  $resp = null; $err = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_TIMEOUT=>10,
      CURLOPT_SSL_VERIFYPEER=>true,
      CURLOPT_SSL_VERIFYHOST=>2
    ));
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(array('http'=>array('timeout'=>10)));
    $resp = @file_get_contents($url,false,$ctx);
    if ($resp === false) $err = 'HTTP request failed';
  }

  if (!$err && is_string($resp) && strlen($resp)>0) {
    $pd = json_decode($resp, true);
    if (is_array($pd)) {
      $placeId          = isset($pd['place_id']) ? $pd['place_id'] : null;
      $actionLocationID = isset($pd['actionLocationID']) ? $pd['actionLocationID'] : null;
    } else {
      error_log('[addAction] place lookup non-JSON: '.substr($resp,0,200));
    }
  } else if ($err) {
    error_log('[addAction] place lookup error: '.$err);
  }
}

// (prefer client-sent place id if lookup failed)
if ($placeId === null && isset($body['actionGooglePlaceId']) && $body['actionGooglePlaceId']!=='') {
  $placeId = (string)$body['actionGooglePlaceId'];
}

// (optional local snap to data.locations if no actionLocationID yet)
if ($actionLocationID === null && $latProvided && $lngProvided && !empty($data['locations'])) {
  $best = null; $bestDist = 1e12;
  foreach ($data['locations'] as $loc) {
    if (!isset($loc['locationLatitude'],$loc['locationLongitude'])) continue;
    $d = dist_m((float)$body['actionLatitude'], (float)$body['actionLongitude'], $loc['locationLatitude'], $loc['locationLongitude']);
    if ($d < $bestDist) { $best = $loc; $bestDist = $d; }
  }
  if ($best && $bestDist <= 150) {
    $actionLocationID = isset($best['locationID']) ? (int)$best['locationID'] : null;
    if (!empty($best['locationGooglePlaceID'])) {
      $placeId = $best['locationGooglePlaceID']; // (normalize to canonical)
    }
  }
}

// (build action)
$action = array(
  'actionID'            => $nextId,
  'actionTypeID'        => isset($body['actionTypeID']) ? (int)$body['actionTypeID'] : 0,
  'actionContactID'     => isset($body['actionContactID']) ? (int)$body['actionContactID'] : 0,
  'actionNote'          => isset($body['actionNote']) ? substr(trim((string)$body['actionNote']),0,500) : '',
  'actionLatitude'      => $latProvided ? (float)$body['actionLatitude'] : null,
  'actionLongitude'     => $lngProvided ? (float)$body['actionLongitude'] : null,
  'actionGooglePlaceId' => $placeId,          // (may be null)
  'actionLocationID'    => $actionLocationID, // (null if no snap)
  'actionTimestamp'     => isset($body['actionTimestamp']) ? (int)$body['actionTimestamp'] : $nowMs,
  'actionMeta'          => array(
      'ip'        => isset($_SERVER['REMOTE_ADDR'])     ? $_SERVER['REMOTE_ADDR']     : '',
      'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
      'source'    => 'addAction.php'
  )
);

// (persist with lock)
$fp = fopen($jsonPath, 'c+');
if (!$fp) bad('Unable to open data file for writing', 500);
if (!flock($fp, LOCK_EX)) { fclose($fp); bad('Unable to lock data file', 500); }

rewind($fp);
$pretty  = defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0;
$encoded = json_encode($data, $pretty);
$data['actions'][] = $action; // (append after reading but before encoding)
$encoded = json_encode($data, $pretty);
if ($encoded === false) { flock($fp, LOCK_UN); fclose($fp); bad('JSON encode failed', 500); }

ftruncate($fp, 0);
$w = fwrite($fp, $encoded);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
if ($w === false) bad('Write failed', 500);

// (done)
ok(array('actionID'=>$nextId), 201);
