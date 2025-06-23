<?php
header("Content-Type: application/json");

$prompt = $_POST["prompt"] ?? '';
if (!$prompt) {
  http_response_code(400);
  echo json_encode(["error" => "Missing prompt"]);
  exit;
}

$envPath = dirname(__DIR__) . '/.env';
if (!file_exists($envPath)) {
  http_response_code(500);
  echo json_encode(["error" => ".env file not found"]);
  exit;
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$apiKey = '';
foreach ($lines as $line) {
  if (strpos(trim($line), 'openAIAPIKey=') === 0) {
    $apiKey = trim(explode('=', $line, 2)[1]);
    break;
  }
}

if (!$apiKey) {
  http_response_code(500);
  echo json_encode(["error" => "API key not found"]);
  exit;
}

$data = [
  "model" => "gpt-3.5-turbo",
  "messages" => [["role" => "user", "content" => $prompt]],
  "temperature" => 0.7
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
  ],
  CURLOPT_POSTFIELDS => json_encode($data)
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
  echo json_encode(["error" => curl_error($ch)]);
} else {
  echo $response;
}
curl_close($ch);
