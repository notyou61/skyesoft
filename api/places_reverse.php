<?php
// 📁 File: api/places_reverse.php
// Purpose: Reverse-geocode lat/lng → Google Place ID (secure backend lookup)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/env_boot.php';

// Haversine helper
function dist_m($lat1,$lon1,$lat2,$lon2){
    $R=6371000; $toRad=M_PI/180;
    $dLat=($lat2-$lat1)*$toRad; $dLon=($lon2-$lon1)*$toRad;
    $a=sin($dLat/2)**2+cos($lat1*$toRad)*cos($lat2*$toRad)*sin($dLon/2)**2;
    return 2*$R*atan2(sqrt($a),sqrt(1-$a));
}

// Known office location
$office = [
    'locationGooglePlaceID' => 'ChIJf44w9yAKK4cRj-x2VuT63GQ',
    'locationLatitude'      => 33.4854056,
    'locationLongitude'     => -112.1296014
];

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$key = getenv('GOOGLE_MAPS_BACKEND_API_KEY');

if ($lat === null || $lng === null || !$key) {
    echo json_encode(['place_id'=>null,'status'=>'MISSING_PARAMS']);
    exit;
}

// Snap to office if within 100m
if (dist_m($lat,$lng,$office['locationLatitude'],$office['locationLongitude']) <= 100) {
    echo json_encode([
        'place_id'=>$office['locationGooglePlaceID'],
        'status'=>'OK_SNAP',
        'actionLocationID'=>1
    ]);
    exit;
}

// Else query Google
$url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' .
       urlencode($lat . ',' . $lng) .
       '&result_type=street_address|premise' .
       '&key=' . urlencode($key);

$resp = file_get_contents($url);
$data = json_decode($resp,true);

echo json_encode([
    'place_id' => $data['results'][0]['place_id'] ?? null,
    'status'   => $data['status'] ?? 'UNKNOWN',
    'actionLocationID' => null
]);
