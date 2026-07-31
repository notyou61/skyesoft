<?php
/**
 * saveReviewState.php
 * Skyesoft – Signature Review State Handler
 */

declare(strict_types=1);

header('Content-Type: application/json');

$stateFile = __DIR__ . '/emailSignatureExtraction/candidateReviewState.json';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$candidateId = $data['candidateId'] ?? null;
$newState    = $data['state'] ?? null;

if (!$candidateId || !is_array($newState)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$states = [];
if (file_exists($stateFile)) {
    $states = json_decode(file_get_contents($stateFile), true) ?? [];
}

// Merge state for candidate
$states[$candidateId] = array_merge($states[$candidateId] ?? [], $newState);

if (file_put_contents($stateFile, json_encode($states, JSON_PRETTY_PRINT))) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write state file']);
}