<?php

declare(strict_types=1);

/**
 * Skyesoft — Phoenix CSP Diagnostic Tool
 *
 * Purpose:
 * Hardcodes a known CSP-positive Phoenix location and interrogates the
 * City of Phoenix Planning & Permit Cases Layer 4 without hardcoding
 * or assuming the CSP determination.
 *
 * Suggested Path: /skyesoft/tools/testPhoenixCsp.php
 * PHP Version:    8.0+
 */

header('Content-Type: application/json; charset=utf-8');

// Known CSP-positive test location
$testLocation = [
    'address'      => '12202 N Cave Creek Rd, Phoenix, AZ 85022',
    'parcelNumber' => '16614002B',
    'latitude'     => 33.5971784,
    'longitude'    => -112.0380891
];

$layerUrl = 'https://maps.phoenix.gov/pub/rest/services/Public/Planning_Permit/MapServer/4';

// Query layer metadata first so the actual Phoenix schema is visible.
$metadata = phoenixCspRequest($layerUrl, [
    'f' => 'json'
]);

// Run the same point-intersection query used by the current resolver.
$pointQuery = phoenixCspRequest($layerUrl . '/query', [
    'where'          => '1=1',
    'geometry'       => $testLocation['longitude'] . ',' . $testLocation['latitude'],
    'geometryType'   => 'esriGeometryPoint',
    'spatialRel'     => 'esriSpatialRelIntersects',
    'inSR'           => 4326,
    'outSR'          => 4326,
    'outFields'      => '*',
    'returnGeometry' => 'true',
    'f'              => 'json'
]);

// Diagnostic only: use a small envelope around the known property.
// This is not a CSP determination; it helps reveal nearby case geometry.
$delta = 0.001;
$envelope = [
    'xmin' => $testLocation['longitude'] - $delta,
    'ymin' => $testLocation['latitude'] - $delta,
    'xmax' => $testLocation['longitude'] + $delta,
    'ymax' => $testLocation['latitude'] + $delta,
    'spatialReference' => [
        'wkid' => 4326
    ]
];

$nearbyQuery = phoenixCspRequest($layerUrl . '/query', [
    'where'          => '1=1',
    'geometry'       => json_encode($envelope, JSON_UNESCAPED_SLASHES),
    'geometryType'   => 'esriGeometryEnvelope',
    'spatialRel'     => 'esriSpatialRelIntersects',
    'inSR'           => 4326,
    'outSR'          => 4326,
    'outFields'      => '*',
    'returnGeometry' => 'true',
    'f'              => 'json'
]);

$output = [
    'success'      => true,
    'testPurpose'  => 'Discover how Phoenix Layer 4 represents a known CSP-positive property. No CSP result is hardcoded.',
    'testLocation' => $testLocation,
    'source'       => [
        'provider' => 'City of Phoenix',
        'layer'    => 'Planning & Permit Cases',
        'layerId'  => 4,
        'url'      => $layerUrl
    ],
    'layerMetadata' => [
        'requestSuccess' => $metadata['success'],
        'httpCode'       => $metadata['httpCode'],
        'errorMessage'   => $metadata['errorMessage'],
        'response'       => $metadata['data']
    ],
    'pointIntersectionTest' => [
        'requestSuccess' => $pointQuery['success'],
        'httpCode'       => $pointQuery['httpCode'],
        'featureCount'   => phoenixFeatureCount($pointQuery['data']),
        'errorMessage'   => $pointQuery['errorMessage'],
        'response'       => $pointQuery['data']
    ],
    'nearbyEnvelopeTest' => [
        'note'           => 'Diagnostic search around the known property; nearby records are not automatically CSP matches.',
        'requestSuccess' => $nearbyQuery['success'],
        'httpCode'       => $nearbyQuery['httpCode'],
        'featureCount'   => phoenixFeatureCount($nearbyQuery['data']),
        'errorMessage'   => $nearbyQuery['errorMessage'],
        'response'       => $nearbyQuery['data']
    ]
];

echo json_encode(
    $output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

function phoenixCspRequest(string $url, array $params): array {
    $requestUrl = $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $requestUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Cache-Control: no-cache'
        ],
        CURLOPT_USERAGENT      => 'Skyesoft-Phoenix-CSP-Diagnostic/1.0'
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'success'      => false,
            'httpCode'     => $httpCode,
            'errorMessage' => $curlError !== '' ? $curlError : 'Empty response.',
            'data'         => null
        ];
    }

    $decoded = json_decode((string)$response, true);

    if (!is_array($decoded)) {
        return [
            'success'      => false,
            'httpCode'     => $httpCode,
            'errorMessage' => 'Invalid JSON: ' . json_last_error_msg(),
            'data'         => null
        ];
    }

    if (!empty($decoded['error'])) {
        return [
            'success'      => false,
            'httpCode'     => $httpCode,
            'errorMessage' => $decoded['error']['message'] ?? 'ArcGIS error.',
            'data'         => $decoded
        ];
    }

    return [
        'success'      => $httpCode >= 200 && $httpCode < 300,
        'httpCode'     => $httpCode,
        'errorMessage' => null,
        'data'         => $decoded
    ];
}

function phoenixFeatureCount(?array $data): int {
    if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
        return 0;
    }

    return count($data['features']);
}