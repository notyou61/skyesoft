<?php
// apiTest.php - Quick test for OpenAI API connectivity

$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    die("❌ API key not found in environment.\n");
}

$url = "https://api.openai.com/v1/chat/completions";

$data = [
    "model" => "gpt-4o-mini", // lightweight model for testing
    "messages" => [
        ["role" => "system", "content" => "You are a helpful assistant."],
        ["role" => "user", "content" => "Hello, can you hear me?"]
    ],
    "max_tokens" => 50
];

$options = [
    "http" => [
        "header"  => [
            "Content-type: application/json",
            "Authorization: Bearer " . $apiKey
        ],
        "method"  => "POST",
        "content" => json_encode($data),
        "timeout" => 30
    ]
];

$context  = stream_context_create($options);
$result = @file_get_contents($url, false, $context);

if ($result === FALSE) {
    echo "❌ Request failed.\n";
    if (isset($http_response_header)) {
        echo "Response headers:\n";
        print_r($http_response_header);
    }
} else {
    echo "✅ Success! Response:\n";
    echo $result;
}
