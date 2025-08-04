<?php
// 📁 File: api/addAction.php

// #region 🌐 Setup & Includes
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
$jsonPath = __DIR__ . '/../assets/data/skyesoft-data.json';
$envPath  = __DIR__ . '/../.env';
require_once __DIR__ . '/setUiEvent.php'; // Loads $actionTypes and triggerUserUiEvent
// #endregion

// #region 🛡️ Input Validation & Variables
$input  = file_get_contents('php://input');
$action = json_decode($input, true);

if (!$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
$required = ['actionTypeID', 'actionContactID', 'actionNote', 'actionTimestamp', 'actionLatitude', 'actionLongitude'];
foreach ($required as $key) {
    if (!isset($action[$key]) || $action[$key] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing or empty field: $key"]);
        exit;
    }
}
if (!is_numeric($action['actionLatitude']) || $action['actionLatitude'] < -90 || $action['actionLatitude'] > 90) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid latitude (must be between -90 and 90)']);
    exit;
}
if (!is_numeric($action['actionLongitude']) || $action['actionLongitude'] < -180 || $action['actionLongitude'] > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid longitude (must be between -180 and 180)']);
    exit;
}
function actionTypeIdExists($id, $actionTypes) {
    foreach ($actionTypes as $at) if (isset($at['actionTypeID']) && $at['actionTypeID'] == $id) return true;
    return false;
}
if (!actionTypeIdExists($action['actionTypeID'], $actionTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid actionTypeID']);
    exit;
}
$action['actionNote'] = htmlspecialchars($action['actionNote'], ENT_QUOTES, 'UTF-8');

// Get username for UI events
$username = 'Unknown';
if (isset($data['contacts']) && is_array($data['contacts'])) {
    foreach ($data['contacts'] as $c) {
        if (isset($c['contactID']) && $c['contactID'] == $action['actionContactID']) {
            $username = htmlspecialchars($c['contactName'], ENT_QUOTES, 'UTF-8');
            break;
        }
    }
}
// #endregion

// #region 🌍 Google Place ID Lookup
function getEnvVar($key, $envPath) {
    static $env = null;
    if ($env === null) {
        $env = [];
        if (file_exists($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
            }
        }
    }
    return isset($env[$key]) ? $env[$key] : null;
}
function getGooglePlaceIdFromLatLng($lat, $lng, $apiKey) {
    if (!$apiKey) return "Place ID unavailable: Missing API key";
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$apiKey}";
    $response = @file_get_contents($url);
    if ($response === false) return "Place ID unavailable: Network error";
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) return "Place ID unavailable: Invalid response";
    if (!empty($data['results'][0]['place_id'])) return $data['results'][0]['place_id'];
    return "Place ID unavailable";
}
$apiKey = getEnvVar('GOOGLE_MAPS_BACKEND_API_KEY', $envPath);
$action['actionGooglePlaceId'] = getGooglePlaceIdFromLatLng($action['actionLatitude'], $action['actionLongitude'], $apiKey);
// #endregion

// #region 📝 Main Action Storage Logic
// Robust JSON file load/init
if (!file_exists($jsonPath)) {
    $data = ['actions' => [], 'contacts' => []];
    file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT));
} else {
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!$data) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not read JSON data']);
        exit;
    }
}
// Auto-increment actionID
$maxId = 0;
if (isset($data['actions']) && is_array($data['actions'])) {
    foreach ($data['actions'] as $act) {
        if (isset($act['actionID']) && $act['actionID'] > $maxId) $maxId = $act['actionID'];
    }
}
$action['actionID'] = $maxId + 1;

// Atomic append & save (with file lock)
$success = false;
$fp = fopen($jsonPath, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $raw = stream_get_contents($fp);
    $currentData = $raw ? json_decode($raw, true) : ['actions' => [], 'contacts' => []];
    if (!$currentData) $currentData = ['actions' => [], 'contacts' => []];
    $currentData['actions'][] = $action;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($currentData, JSON_PRETTY_PRINT));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    $success = true;
    $data = $currentData; // For downstream use
} else {
    if ($fp) fclose($fp);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to lock/write JSON file']);
    exit;
}
// #endregion

// #region 🚨 UI Event Logic (Modal Notification)
$triggerTypes = [1, 2]; // Only these types trigger a modal
if ($success && in_array($action['actionTypeID'], $triggerTypes)) {
    // Set session event with unique id for frontend dedupe
    $_SESSION['uiEvent'] = [
        "type" => "modal",
        "action" => $action['actionTypeID'],
        "title" => $action['actionTypeID'] == 1 ? "🔑 Login" : "🚪 Logout",
        "message" => ($action['actionTypeID'] == 1
            ? "User logged into the system at "
            : "User logged out of the system at ")
            . date('g:i A'),
        "user" => $username,
        "id" => uniqid("event_", true),
        "color" => $action['actionTypeID'] == 1 ? "#3bb143" : "#d9534f",
        "icon" => $action['actionTypeID'] == 1 ? "🔑" : "🚪"
    ];
    // Optionally, also trigger deeper system UI event
    if (!triggerUserUiEvent($action['actionTypeID'], $action['actionContactID'], $username, $actionTypes)) {
        error_log("Failed to trigger UI event: actionTypeID={$action['actionTypeID']}, contactID={$action['actionContactID']}, user={$username}");
    }
}
// #endregion

// #region 📤 Output Response
http_response_code($success ? 201 : 500);
echo json_encode([
    'success'    => $success,
    'actionID'   => $action['actionID'],
    'actionType' => $action['actionTypeID'],
    'user'       => $username
]);
exit;
// #endregion