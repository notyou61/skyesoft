<?php
// File: api/addAction.php

header('Content-Type: application/json');
// ---- Paths and Setup
$jsonPath = __DIR__ . '/home/notyou64/data/skyesoft-data.json';
$envPath = __DIR__ . '/../.env';
require_once __DIR__ . '/setUiEvent.php'; // Only if $actionTypes is defined here
// ---- Load POSTed action
$input = file_get_contents('php://input');
$action = json_decode($input, true);
if (!$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}
// ---- Validate required fields
$required = ['actionTypeID', 'actionContactID', 'actionNote', 'actionTimestamp', 'actionLatitude', 'actionLongitude'];
foreach ($required as $key) {
    if (!isset($action[$key]) || $action[$key] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing or empty field: $key"]);
        exit;
    }
}
// ---- Validate numeric fields
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

// ---- Validate actionTypeID
function actionTypeIdExists($id, $actionTypes) {
    foreach ($actionTypes as $at) {
        if (isset($at['actionTypeID']) && $at['actionTypeID'] == $id) {
            return true;
        }
    }
    return false;
}
if (!actionTypeIdExists($action['actionTypeID'], $actionTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid actionTypeID']);
    exit;
}
// ---- Sanitize actionNote to prevent XSS
$action['actionNote'] = htmlspecialchars($action['actionNote'], ENT_QUOTES, 'UTF-8');

// ---- Always look up Place ID server-side (ignore what frontend sends)
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
    if (!$apiKey) {
        return "Place ID unavailable: Missing API key";
    }
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$apiKey}";
    $response = @file_get_contents($url); // TODO: Replace with cURL for better error handling
    if ($response === false) {
        error_log("Google Maps API error: Network issue for lat={$lat}, lng={$lng}");
        return "Place ID unavailable: Network error";
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Google Maps API error: Invalid response for lat={$lat}, lng={$lng}");
        return "Place ID unavailable: Invalid response";
    }
    if (!empty($data['results'][0]['place_id'])) {
        return $data['results'][0]['place_id'];
    }
    return "Place ID unavailable";
}

$apiKey = getEnvVar('GOOGLE_MAPS_BACKEND_API_KEY', $envPath);
$action['actionGooglePlaceId'] = getGooglePlaceIdFromLatLng($action['actionLatitude'], $action['actionLongitude'], $apiKey);
// ---- Robust JSON file load/init
if (!file_exists($jsonPath)) {
    $data = [
        'actions' => [],
        'contacts' => [],
        'entities' => [],
        'locations' => [],
        'actionTypes' => [],
        'siteMeta' => [],
        'uiEvent' => null
    ];
    file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT));
} else {
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!$data) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not read JSON data']);
        exit;
    }
}
// ---- Assign next actionID (auto-increment)
$maxId = 0;
if (isset($data['actions']) && is_array($data['actions'])) {
    foreach ($data['actions'] as $act) {
        if (isset($act['actionID']) && $act['actionID'] > $maxId) {
            $maxId = $act['actionID'];
        }
    }
}
$action['actionID'] = $maxId + 1;
// ---- Atomic append & save (with file lock)
$success = false;
$backupPath = $jsonPath . '.' . date('Ymd_His') . '.bak';

$fp = fopen($jsonPath, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    // Read existing data
    rewind($fp);
    $raw = stream_get_contents($fp);
    $currentData = $raw ? json_decode($raw, true) : [
        'actions' => [],
        'contacts' => [],
        'entities' => [],
        'locations' => [],
        'actionTypes' => [],
        'siteMeta' => [],
        'uiEvent' => null
    ];
    if (!$currentData) {
        // If the file was corrupt, back it up before resetting!
        file_put_contents($backupPath, $raw);
        $currentData = [
            'actions' => [],
            'contacts' => [],
            'entities' => [],
            'locations' => [],
            'actionTypes' => [],
            'siteMeta' => [],
            'uiEvent' => null
        ];
    }

    // Append the new action
    $currentData['actions'][] = $action;

    // --- Universal Global UI Event for ALL Actions ---
    $eventType = null;
    foreach ($actionTypes as $at) {
        if (isset($at['actionTypeID']) && $at['actionTypeID'] == $action['actionTypeID']) {
            $eventType = $at;
            break;
        }
    }
    if ($eventType) {
        // Dynamic user name lookup using contactName and contactID
        $userName = "User";
        if (isset($currentData['contacts']) && is_array($currentData['contacts'])) {
            foreach ($currentData['contacts'] as $c) {
                if (isset($c['contactID']) && $c['contactID'] == $action['actionContactID']) {
                    $userName = htmlspecialchars($c['contactName'], ENT_QUOTES, 'UTF-8');
                    break;
                }
            }
        }
        // Build event title/message
        $title = isset($eventType['icon']) ? "{$eventType['icon']} {$eventType['actionName']}" : $eventType['actionName'];
        $msgTime = is_numeric($action['actionTimestamp'])
            ? date('g:i A', $action['actionTimestamp'] / 1000)
            : date('g:i A');
        $message = "{$userName} performed action: {$eventType['actionName']} at {$msgTime}";
        if (!empty($action['actionNote'])) {
            $message .= " — " . htmlspecialchars($action['actionNote'], ENT_QUOTES, 'UTF-8');
        }
        // Set the global ephemeral uiEvent
        $currentData['uiEvent'] = [
            'type' => 'modal',
            'action' => $action['actionTypeID'],
            'title' => $title,
            'message' => $message,
            'user' => $action['actionContactID'],
            'timestamp' => time(),
            'eventID' => uniqid()
        ];
    } else {
        error_log("Failed to set UI event: Invalid actionTypeID={$action['actionTypeID']}");
    }
    // Backup current file before write (optional, but highly recommended for MTCO)
    file_put_contents($backupPath, $raw);
    // Write new data
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($currentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $success = true;
} else {
    if ($fp) fclose($fp);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to lock/write JSON file']);
    exit;
}
// ---- Final response
http_response_code($success ? 201 : 500);
echo json_encode(['success' => $success, 'actionID' => $action['actionID']]);
exit;
