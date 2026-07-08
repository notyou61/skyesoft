<?php
declare(strict_types=1);
$apn = '17342369A';

$url = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);

$response = file_get_contents($url);

echo '<h2>MapID Response</h2>';
echo '<pre>';
echo htmlspecialchars($response);
echo '</pre>';

echo '<h2>Decoded MapID JSON</h2>';
echo '<pre>';
print_r(json_decode($response, true));
echo '</pre>';