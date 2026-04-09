<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/utils/validateAddress.php';

$input = json_decode(file_get_contents('php://input'), true);

$address = [
    'address' => $input['address'] ?? '',
    'city'    => $input['city'] ?? '',
    'state'   => $input['state'] ?? '',
    'zip'     => $input['zip'] ?? ''
];

try {
    $result = validateAddressUSPS($address);

    echo json_encode([
        'status' => $result['valid'] ? 'valid' : 'invalid',
        'result' => $result
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}