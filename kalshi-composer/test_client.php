<?php
declare(strict_types=1);


require_once __DIR__ . '/src/KalshiClient.php';


try {

    // Initialize client
    $kalshi = new KalshiClient();


    // Test known authenticated endpoint
    $result = $kalshi->get(
        '/trade-api/v2/portfolio/balance'
    );


    header('Content-Type: application/json');

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {

    http_response_code(500);

    header('Content-Type: text/plain');

    echo 'Kalshi Client Error: ' . $e->getMessage();
}