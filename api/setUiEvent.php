<?php
// ========== Helper Function (DRY) ==========
function writeUiEvent($type, $action, $title, $message, $user, $icon, $color, $durationSec = 8, $source = "user", $time = null) {
    $event = [
        "type"        => $type,
        "action"      => $action,
        "title"       => $title,
        "message"     => $message,
        "user"        => $user,
        "time"        => $time ?: time(),
        "color"       => $color,
        "icon"        => $icon,
        "durationSec" => $durationSec,
        "source"      => $source
    ];
    // Adjust the path to your actual data folder if needed
    file_put_contents(__DIR__ . '/../assets/data/uiEvent.json', json_encode($event, JSON_PRETTY_PRINT));
}

// ========== Handle POST Request ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Accept JSON or form data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    // Minimal required validation
    $fields = ['type','action','title','message','user','icon','color','durationSec','source'];
    $missing = array_diff($fields, array_keys($input));
    if (count($missing) === 0) {
        writeUiEvent(
            $input['type'],
            $input['action'],
            $input['title'],
            $input['message'],
            $input['user'],
            $input['icon'],
            $input['color'],
            $input['durationSec'],
            $input['source'],
            isset($input['time']) ? $input['time'] : null
        );
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'missing' => $missing]);
    }
    exit;
}

// ========== (Optional) Expose Function for Internal PHP Use ==========
/*
// Usage in other PHP files:
include_once 'setUiEvent.php';
writeUiEvent(...);
*/
