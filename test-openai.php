<?php
require_once 'config.php';

$ch = curl_init();

$data = [
    "model" => "gpt-3.5-turbo",
    "messages" => [["role" => "user", "content" => "Hello, who are you?"]],
];

curl_setopt_array($ch, [
    CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer $openAIAPIKey",
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
]);

$response = curl_exec($ch);
curl_close($ch);

echo "<pre>";
print_r(json_decode($response, true));
echo "</pre>";
