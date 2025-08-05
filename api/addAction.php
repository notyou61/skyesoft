<?php
//  File: api/addAction.php

// #region  Universal Action Logger

header('Content-Type: application/json');

// --- Ephemeral (session-based) UI Event Handler ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Paths and Setup
$jsonPath = __DIR__ . '/../assets/data/skyesoft-data.json';
$envPath = __DIR__ . '/../.env';
require_once __DIR__ . '/setUiEvent.php'; // Defines $actionTypes and triggerUserUiEvent

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
$fp = fopen($jsonPath, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $raw = stream_get_contents($fp);
    $currentData = $raw ? json_decode($raw, true) : ['actions' => [], 'contacts' => []];
    if (!$currentData) {
        $currentData = ['actions' => [], 'contacts' => []];
    }
    $currentData['actions'][] = $action;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($currentData, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $success = true;
    $data = $currentData; // For downstream use
} else {
    if ($fp) fclose($fp);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to lock/write JSON file']);
    exit;
}

// ---- Session-based UI Event Trigger (Login/Logout/etc)
$triggerTypes = [1, 2]; // Adjust as needed for other action types
if (in_array($action['actionTypeID'], $triggerTypes)) {
    // Dynamic user name lookup by contactID (if present in contacts array)
    $userName = "User";
    if (isset($data['contacts']) && is_array($data['contacts'])) {
        foreach ($data['contacts'] as $c) {
            if (isset($c['contactID']) && $c['contactID'] == $action['actionContactID']) {
                $userName = htmlspecialchars($c['contactName'], ENT_QUOTES, 'UTF-8'); // Sanitize for XSS
                break;
            }
        }
    }
    // Trigger UI event (sets $_SESSION['uiEvent'] in setUiEvent.php)
    if (!triggerUserUiEvent($action['actionTypeID'], $action['actionContactID'], $userName, $actionTypes)) {
        error_log("Failed to trigger UI event for actionTypeID={$action['actionTypeID']}, contactID={$action['actionContactID']}");
    }
}

// ---- Final response
http_response_code($success ? 201 : 500); // 201 for resource creation (REST convention)
echo json_encode(['success' => $success, 'actionID' => $action['actionID']]);
exit;

// #endregion
