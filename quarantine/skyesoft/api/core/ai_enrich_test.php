<?php
// askOpenAI.php - Simple OpenAI API endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // For local testing
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prompt = isset($input['prompt']) ? trim($input['prompt']) : '';

if (empty($prompt)) {
    http_response_code(400);
    echo json_encode(['error' => 'No prompt provided']);
    exit;
}

// OpenAI API configuration
$apiKey = 'YOUR_OPENAI_API_KEY';  // Replace with your actual key
$url = 'https://api.openai.com/v1/chat/completions';
$data = array(
    'model' => 'gpt-3.5-turbo',
    'messages' => array(array('role' => 'user', 'content' => $prompt)),
    'max_tokens' => 150,
    'temperature' => 0.7
);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    http_response_code($httpCode ?: 500);
    echo json_encode(['error' => 'OpenAI API failed: ' . ($error ?: $httpCode)]);
} else {
    $result = json_decode($response, true);
    $aiText = isset($result['choices'][0]['message']['content']) ? trim($result['choices'][0]['message']['content']) : 'No response generated';
    echo json_encode(array('response' => $aiText));
}
?>