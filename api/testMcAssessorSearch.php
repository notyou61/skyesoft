<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$q = $_GET['q'] ?? '225 N 1ST ST BUCKEYE AZ 85326';

$url =
    'https://mcassessor.maricopa.gov/search/property/?q=' .
    urlencode($q);

echo '<h2>Request URL</h2>';
echo '<pre>' . htmlspecialchars($url) . '</pre>';

$response = file_get_contents($url);

echo '<h2>Raw Response</h2>';
echo '<pre>';
echo htmlspecialchars($response);
echo '</pre>';

$data = json_decode($response, true);

echo '<h2>Decoded JSON</h2>';
echo '<pre>';
print_r($data);
echo '</pre>';