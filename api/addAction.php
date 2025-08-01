<?php

// #region ⏺️ Universal Action Logger — addAction.php

header('Content-Type: application/json');

// Set your file paths
$jsonPath = __DIR__ . '/../assets/data/skyesoft-data.json';
$envPath = __DIR__ . '/../.env';

$input = file_get_contents('php://input');
$action = json_decode($input, true);
die(json_encode(array(
  "input" => $input,
  "action" => $action,
  "php_version" => phpversion()
)));


file_put_contents(__DIR__ . '/debug-path.txt', $jsonPath . "\n", FILE_APPEND);

// Debug: Write out the resolved JSON path to a file
file_put_contents('/home/notyou64/public_html/skyesoft/assets/data/debug.txt', "PATH=" . $jsonPath . "\n", FILE_APPEND);

// region: .env loader
function getEnvVar($key, $envPath) {
    static $env = null;
    if ($env === null) {
        $env = array();
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
// endregion

// region: Place ID lookup via Google Geocoding API
function getGooglePlaceIdFromLatLng($lat, $lng, $apiKey) {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$apiKey}";
    $response = @file_get_contents($url);
    $data = json_decode($response, true);
    if (!empty($data['results'][0]['place_id'])) {
        return $data['results'][0]['place_id'];
    }
    return "Place ID unavailable";
}
// endregion

// region: Load action object from POST
$input = file_get_contents('php://input');
$action = json_decode($input, true);
//
file_put_contents(__DIR__ . '/debug-action-raw.txt', $input . "\n", FILE_APPEND);
file_put_contents(__DIR__ . '/debug-action-parsed.txt', print_r($action, true) . "\n", FILE_APPEND);
//
if (!$action) {
    echo json_encode(array('success' => false, 'error' => 'Invalid JSON'));
    exit;
}
// endregion

// region: Validate required fields
$required = array('actionTypeID', 'actionContactID', 'actionNote', 'actionTimestamp', 'actionLatitude', 'actionLongitude');
foreach ($required as $key) {
    if (!isset($action[$key])) {
        echo json_encode(array('success' => false, 'error' => "Missing: $key"));
        exit;
    }
}
// endregion

// region: Always look up Place ID server-side (ignore what frontend sends)
$apiKey = getEnvVar('GOOGLE_MAPS_BACKEND_API_KEY', $envPath);
$action['actionGooglePlaceId'] = getGooglePlaceIdFromLatLng($action['actionLatitude'], $action['actionLongitude'], $apiKey);
// endregion

// region: Load current skyesoft-data.json
$data = json_decode(file_get_contents($jsonPath), true);
if (!$data) {
    echo json_encode(array('success' => false, 'error' => 'Could not read JSON data'));
    exit;
}
// endregion

// region: Assign next actionID (auto-increment)
$maxId = 0;
if (isset($data['actions']) && is_array($data['actions'])) {
    foreach ($data['actions'] as $act) {
        if (isset($act['actionID']) && $act['actionID'] > $maxId) {
            $maxId = $act['actionID'];
        }
    }
}
$action['actionID'] = $maxId + 1;
// endregion

// region: Append and save
$data['actions'][] = $action;
if (file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT))) {
    // --- Office Board Event Trigger: Login/Logout/Other
    // Only trigger for specific action types (e.g., 1=login, 2=logout)
    $triggerTypes = array(1, 2); // Expand as needed for other events
    if (in_array($action['actionTypeID'], $triggerTypes)) {
        require_once(__DIR__ . '/setUiEvent.php');

        // Define actionTypes here or load from JSON/DB as you scale
        $actionTypes = array(
            array("actionTypeID" => 1, "actionCRUDType" => "Create", "actionName" => "Login",  "actionDescription" => "logged into the system."),
            array("actionTypeID" => 2, "actionCRUDType" => "Create", "actionName" => "Logout", "actionDescription" => "logged out of the system.")
            // Add more as needed...
        );

        // --- Dynamic user name lookup by contactID (if present in contacts array) ---
        $userName = "User";
        if (isset($data['contacts']) && is_array($data['contacts'])) {
            foreach ($data['contacts'] as $c) {
                if (isset($c['contactID']) && $c['contactID'] == $action['actionContactID']) {
                    $userName = $c['contactName'];
                    break;
                }
            }
        }

        triggerUserUiEvent($action['actionTypeID'], $action['actionContactID'], $userName, $actionTypes);
    }

    echo json_encode(array('success' => true, 'actionID' => $action['actionID']));
} else {
    echo json_encode(array('success' => false, 'error' => 'Failed to write to JSON file'));
}
// endregion

// #endregion