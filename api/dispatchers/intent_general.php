<?php
// Handles general / data-aware intents using the SSE live stream
require_once __DIR__ . '/../helpers.php';
header('Content-Type: application/json; charset=UTF-8');

// Ensure dynamic data context exists
if (!isset($dynamicData) || !is_array($dynamicData)) {
    sendJsonResponse("⚠️ No dynamic data available.", "error");
    exit;
}

// Attempt semantic lookup in the SSE stream
$result = querySSE($prompt, $dynamicData);

if (is_array($result) && isset($result['value'])) {
    sendJsonResponse(
        $result['message'],
        'general',
        array(
            'sessionId'   => $sessionId,
            'resolvedKey' => $result['key'],
            'value'       => $result['value']
        )
    );
} else {
    sendJsonResponse(
        "⚠️ No direct SSE data found for: '{$prompt}'",
        'error',
        array('sessionId' => $sessionId)
    );
}
exit;