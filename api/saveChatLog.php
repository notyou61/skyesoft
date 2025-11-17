<?php
// 📁 File: skyesoft/api/saveChatLog.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Get raw POST body
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Validate input
if (!isset($data['log']) || !is_array($data['log'])) {
    echo json_encode(["success" => false, "error" => "No valid log data received."]);
    exit;
}

// Set log file path
$logDir = "../../assets/data/chatlogs";
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

$timestamp = date("Y-m-d_H-i-s");
$filename = $logDir . "/chatlog_" . $timestamp . ".json";

// Save file
file_put_contents($filename, json_encode($data['log'], JSON_PRETTY_PRINT));

// Return success response
echo json_encode(["success" => true, "message" => "Chat log saved."]);
