<?php
// File: api/addAction.php

// -- sanitize output: no stray bytes before JSON
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) { @ob_end_clean(); }
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0'); error_reporting(0);

// Config (absolute path)
$jsonPath = '/home/notyou64/public_html/data/skyesoft-data.json';

// Helpers (concise JSON responses)
function bad($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(array('status' => 'error', 'message' => $msg));
    exit;
}
function ok($data = array(), $code = 200) {
    http_response_code($code);
    echo json_encode(array('status' => 'ok') + $data);
    exit;
}

// Read JSON body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

// Validate body (must be JSON object)
if (!is_array($body)) bad('Invalid JSON body');

// Load data file
if (!is_readable($jsonPath)) bad('Data file not readable', 500);
$dataJson = file_get_contents($jsonPath);
$data = json_decode($dataJson, true);
if (!is_array($data)) bad('Data file corrupt', 500);

// Ensure arrays exist (actions, actionTypes)
if (!isset($data['actions']) || !is_array($data['actions'])) $data['actions'] = array();
if (!isset($data['actionTypes']) || !is_array($data['actionTypes'])) $data['actionTypes'] = array();

// Pull actionTypes (required)
$actionTypes = $data['actionTypes'];
if (empty($actionTypes)) bad('No action types found in data file', 500);

// Required fields (// User check)
$reqFields = array('actionTypeID', 'actionContactID');
foreach ($reqFields as $k) {
    if (!isset($body[$k])) bad('Missing required field: ' . $k);
}

// Validate actionTypeID (must exist in data file)
$validTypeIds = array();
foreach ($actionTypes as $t) {
    if (isset($t['actionTypeID'])) $validTypeIds[] = (int)$t['actionTypeID'];
}
if (!in_array((int)$body['actionTypeID'], $validTypeIds, true)) bad('Unknown actionTypeID');

// Next actionID (default 1)
$nextId = 1;
if (!empty($data['actions'])) {
    $ids = array();
    foreach ($data['actions'] as $a) {
        if (isset($a['actionID'])) $ids[] = (int)$a['actionID'];
    }
    if (!empty($ids)) $nextId = max($ids) + 1;
}

// Build action object (include all properties)
$now = time();
$action = array(
    'actionID'         => $nextId,
    'actionTypeID'     => (int)$body['actionTypeID'],
    'actionContactID'  => isset($body['actionContactID']) ? (int)$body['actionContactID'] : 0,
    'actionNote'       => isset($body['actionNote']) ? trim((string)$body['actionNote']) : '',
    'actionLatitude'   => isset($body['actionLatitude']) ? (float)$body['actionLatitude'] : null,
    'actionLongitude'  => isset($body['actionLongitude']) ? (float)$body['actionLongitude'] : null,
    'actionTimestamp'  => isset($body['actionTimestamp']) ? (int)$body['actionTimestamp'] : $now,
    'actionMeta'       => array(
        'ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        'source'    => 'addAction.php'
    )
);

// Append and persist (LOCK_EX to avoid race; do not wipe data)
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

// Success (clean JSON; skyebot.js-friendly)
ok(array('actionID' => $nextId), 201);
