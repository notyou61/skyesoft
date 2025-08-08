<?php
// 📁 File: api/setUiEvent.php
// --- Ephemeral helper for dynamic UI Event (Office Board, session-based) ---
// (PHP 5.3+ compatible; DRY)

// Define actionTypes here (or load from config/db/json as you scale)
$actionTypes = array(
    array("actionTypeID" => 1, "actionCRUDType" => "Create", "actionName" => "Login",  "actionDescription" => "logged into the system."),
    array("actionTypeID" => 2, "actionCRUDType" => "Create", "actionName" => "Logout", "actionDescription" => "logged out of the system.")
    // Add more as needed...
);

// -- Helper function: set a UI event for this user/session only --
function triggerUserUiEvent($actionTypeID, $userId, $userName, $actionTypes, $options = array()) {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    );
    $defaultColors = array(
        1 => '#3bb143',
        2 => '#f0ad4e'
    );
    $icon = isset($options['icon']) ? $options['icon'] : (isset($defaultIcons[$actionTypeID]) ? $defaultIcons[$actionTypeID] : '📢');
    $color = isset($options['color']) ? $options['color'] : (isset($defaultColors[$actionTypeID]) ? $defaultColors[$actionTypeID] : '#3498db');
    $durationSec = isset($options['durationSec']) ? $options['durationSec'] : 8;

    // Title and message
    $title = $icon . ' ' . $actionType['actionName'];
    $desc = isset($actionType['actionDescription']) ? $actionType['actionDescription'] : $actionType['actionName'];
    $dt = new DateTime("now", new DateTimeZone("America/Phoenix"));
    $phoenixTime = $dt->format("g:i A");
    $message = $userName . ' ' . strtolower($desc) . ' at ' . $phoenixTime;
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

    // **NEW: Ephemeral—store in user session only!**
    $_SESSION['uiEvent'] = $event;
    return true;
}

// --- Only define helpers. No POST handler, echo, or exit ---
// --- If included from PHP, use the triggerUserUiEvent() function directly! ---