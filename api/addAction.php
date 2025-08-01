<?php

// #region ⏺️ Universal Action Logger — addAction.php

header('Content-Type: application/json');
ob_start(); // <--- Buffer all output

$jsonPath = __DIR__ . '/../assets/data/skyesoft-data.json';
$envPath  = __DIR__ . '/../.env';

// Read JSON POST body
$input  = file_get_contents('php://input');
$action = json_decode($input, true);

// Debug: log input and action
file_put_contents(__DIR__ . '/debug-path.txt', "RAW INPUT: " . $input . "\n", FILE_APPEND);
file_put_contents(__DIR__ . '/debug-path.txt', "ACTION: " . print_r($action, true) . "\n", FILE_APPEND);

// Required fields to check
$required = [
    'actionTypeID', 'actionContactID', 'actionNote',
    'actionLatitude', 'actionLongitude', 'actionTimestamp'
];

// Validate required fields
foreach ($required as $key) {
    if (!isset($action[$key]) || $action[$key] === '') {
        file_put_contents(__DIR__ . '/debug-path.txt', "MISSING: $key\n", FILE_APPEND);
        $response = [
            "status" => "error",
            "message" => "Missing required field: $key"
        ];
        echo json_encode($response);

        // Capture and save the full output (for debug!)
        $actualOutput = ob_get_contents();
        file_put_contents(__DIR__ . '/debug-actual-output.txt', $actualOutput);
        ob_end_flush();
        exit;
    }
}

// Log that all fields are present
file_put_contents(__DIR__ . '/debug-path.txt', "ALL FIELDS PRESENT. Logging action.\n", FILE_APPEND);

// --- Optional: Store all actions as a JSON array for JS-friendly logs (RECOMMENDED) ---
// Read existing actions (as an array)
$allActions = [];
if (file_exists($jsonPath)) {
    $raw = file_get_contents($jsonPath);
    $allActions = json_decode($raw, true);
    if (!is_array($allActions)) $allActions = [];
}
$allActions[] = $action;
// Save back as JSON array (overwrite file)
file_put_contents($jsonPath, json_encode($allActions, JSON_PRETTY_PRINT));

// Respond to client
$response = [
    "status"  => "ok",
    "message" => "Action logged successfully."
];
echo json_encode($response);

// Capture and save the full output (for debug!)
$actualOutput = ob_get_contents();
file_put_contents(__DIR__ . '/debug-actual-output.txt', $actualOutput);
ob_end_flush();


// #region .env loader (template, not used here but ready)
/*
// Example: Load .env if you need it later
if (file_exists($envPath)) {
    $envVars = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
    // Access like $envVars['YOUR_KEY']
}
*/
// #endregion

// #endregion

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