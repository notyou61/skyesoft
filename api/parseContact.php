<?php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$rawInput = $input['rawInput'] ?? null;

if (!$rawInput) {
    echo json_encode(['error' => 'Missing rawInput']);
    exit;
}