<?php
// --- Minimal, Dynamic UI Event API for Office Board ---
// (PHP 5.3 compatible)

// Define actionTypes here (or load from config/db/json as you scale)
$actionTypes = array(
    array("actionTypeID" => 1, "actionCRUDType" => "Create", "actionName" => "Login",  "actionDescription" => "logged into the system."),
    array("actionTypeID" => 2, "actionCRUDType" => "Create", "actionName" => "Logout", "actionDescription" => "logged out of the system.")
    // Add more as needed...
);

// -- Helper function --
function triggerUserUiEvent($actionTypeID, $userId, $userName, $actionTypes, $options = array()) {
    // Lookup actionType
    $actionType = null;
    foreach ($actionTypes as $at) {
        if ($at['actionTypeID'] == $actionTypeID) {
            $actionType = $at;
            break;
        }
    }
    if (!$actionType) {
        error_log("Unknown actionTypeID: $actionTypeID");
        return false;
    }

    // Set icon and color defaults (expand as needed)
    $defaultIcons = array(
        1 => '🔑',  // Login
        2 => '🚪'   // Logout
        // Future: 3 => '📝', etc.
    );
    $defaultColors = array(
        1 => '#3bb143',
        2 => '#f0ad4e'
    );
    $icon = isset($options['icon']) ? $options['icon'] : (isset($defaultIcons[$actionTypeID]) ? $defaultIcons[$actionTypeID] : '📢');
    $color = isset($options['color']) ? $options['color'] : (isset($defaultColors[$actionTypeID]) ? $defaultColors[$actionTypeID] : '#3498db');
    $durationSec = isset($options['durationSec']) ? $options['durationSec'] : 10;

    // Title and message
    $title = $icon . ' ' . $actionType['actionName'];
    $desc = isset($actionType['actionDescription']) ? $actionType['actionDescription'] : $actionType['actionName'];
    $message = $userName . ' ' . strtolower($desc) . ' at ' . date("g:i A");
    if (!empty($options['extraMsg'])) {
        $message .= " — " . $options['extraMsg'];
    }

    $event = array(
        "type"        => "modal",
        "action"      => $actionTypeID,
        "title"       => $title,
        "message"     => $message,
        "user"        => $userId,
        "time"        => time(),
        "color"       => $color,
        "icon"        => $icon,
        "durationSec" => $durationSec,
        "source"      => "user"
    );

    $uiEventPath = dirname(__FILE__) . '/../assets/data/uiEvent.json'; // Adjust path as needed
    file_put_contents($uiEventPath, json_encode($event, JSON_PRETTY_PRINT));
    return true;
}

// --- API: Accept POSTed event parameters (from AJAX, PHP, etc.) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept JSON or form data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $actionTypeID = isset($input['actionTypeID']) ? intval($input['actionTypeID']) : null;
    $userId       = isset($input['userId']) ? intval($input['userId']) : null;
    $userName     = isset($input['userName']) ? $input['userName'] : '';
    $options      = array();
    if (isset($input['extraMsg']))    $options['extraMsg']    = $input['extraMsg'];
    if (isset($input['icon']))        $options['icon']        = $input['icon'];
    if (isset($input['color']))       $options['color']       = $input['color'];
    if (isset($input['durationSec'])) $options['durationSec'] = intval($input['durationSec']);

    if ($actionTypeID && $userId && $userName) {
        $ok = triggerUserUiEvent($actionTypeID, $userId, $userName, $actionTypes, $options);
        header('Content-Type: application/json');
        echo json_encode(array('status' => $ok ? 'success' : 'error'));
    } else {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Missing required fields'));
    }
    exit;
}

// --- If included from PHP, use the triggerUserUiEvent() function directly! ---
?>
