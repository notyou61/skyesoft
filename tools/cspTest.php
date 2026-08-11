<?php

declare(strict_types=1);

/**
 * Skyesoft — Phoenix Special Designations & CSP Resolver
 *
 * File Path:        api/utils/resolvePhoenixSpecialDesignations.php
 * File Version:     1.2.6-test
 * Schema Version:   3.4.0
 * Last Updated:     2026-08-11
 * PHP Version:      8.0+
 */

function resolvePhoenixSpecialDesignations(
    float $latitude,
    float $longitude,
    array $options = []
): array {
    $startedAt = microtime(true);

    $endpoints = [
        'overlays' => [
            'url'       => 'https://maps.phoenix.gov/pub/rest/services/Public/ZoningOverlays/MapServer/0',
            'outFields' => 'NAME,CASE_YR,REGULATORY',
            'source'    => 'City of Phoenix Zoning Overlays GIS'
        ],
        'historic' => [
            'url'       => 'https://maps.phoenix.gov/pub/rest/services/Public/HistoricProperties/MapServer/0',
            'outFields' => 'NAME,TYPE,STATUS,LANDMARK',
            'source'    => 'City of Phoenix Historic Preservation GIS'
        ],
        'csp' => [
            'url'       => 'https://maps.phoenix.gov/pub/rest/services/Public/Planning_Permit/MapServer/4',
            'outFields' => '*',
            'source'    => 'City of Phoenix Planning & Permit Cases GIS'
        ]
    ];

    $overlayResult  = queryPhoenixArcGisLayer($endpoints['overlays'], $latitude, $longitude, $options);
    $historicResult = queryPhoenixArcGisLayer($endpoints['historic'], $latitude, $longitude, $options);
    $cspResult      = queryPhoenixArcGisLayer($endpoints['csp'], $latitude, $longitude, $options);

    $overlayPayload  = parsePhoenixOverlayFeatures($overlayResult, $endpoints['overlays']['source']);
    $historicPayload = parsePhoenixHistoricFeatures($historicResult, $endpoints['historic']['source']);
    $cspPayload      = parsePhoenixCspDiagnosticFeatures($cspResult, $endpoints['csp']['source']);

    // Known CSP test fixture (Castle Megastore).
    // Temporary tools-only test: verifies downstream CSP response/report handling.
    // This does NOT indicate that Phoenix GIS detected the CSP.
    $isKnownCspTestLocation = (
        abs($latitude - 33.5971784) <= 0.00001 &&
        abs($longitude - (-112.0380891)) <= 0.00001
    );

    if ($isKnownCspTestLocation) {
        $cspPayload = [
            'determination' => 'yes',
            'status'        => 'identified',
            'caseNumber'    => null,
            'cases'         => [
                [
                    'name'    => 'Castle Megastore CSP Test Fixture',
                    'address' => '12202 N Cave Creek Rd, Phoenix, AZ 85022'
                ]
            ],
            'source'        => 'Skyesoft CSP test fixture — known-positive location',
            'checkedAt'     => time(),
            'errorMessage'  => null,
            'testFixture'   => true
        ];
    }

    // CSP is diagnostic until the returned Phoenix fields are verified.
    // Do not infer Yes/No from an unverified Planning & Permit layer schema.
    // Complete only when all three objects contain an allowed determination.
    $allowedDeterminations = ['yes', 'no', 'notAvailable'];
    $isComplete = (
        in_array($overlayPayload['determination'] ?? null, $allowedDeterminations, true) &&
        in_array($historicPayload['determination'] ?? null, $allowedDeterminations, true) &&
        in_array($cspPayload['determination'] ?? null, $allowedDeterminations, true)
    );

    return [
        'isComplete'            => $isComplete,
        'historicDesignation'   => $historicPayload,
        'zoningOverlays'        => $overlayPayload,
        'comprehensiveSignPlan' => $cspPayload,
        'responseTimeMs'        => (int)round((microtime(true) - $startedAt) * 1000)
    ];
}

function queryPhoenixArcGisLayer(
    array $layerConfig,
    float $latitude,
    float $longitude,
    array $options = []
): array {
    $startedAt   = microtime(true);
    $serviceUrl  = rtrim($layerConfig['url'], '/');
    $maxAttempts = (int)($options['maxAttempts'] ?? 3);
    $baseDelayMs = (int)($options['retryDelayMs'] ?? 400);

    $params = [
        'where'          => '1=1',
        'geometry'       => "{$longitude},{$latitude}",
        'geometryType'   => 'esriGeometryPoint',
        'spatialRel'     => 'esriSpatialRelIntersects',
        'inSR'           => 4326,
        'outSR'          => 4326, // Adds spatial reference output projection
        'outFields'      => $layerConfig['outFields'] ?? '*',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ];

    $queryUrl = $serviceUrl . '/query?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $lastAttempt = null;
    $attemptLogs = [];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $attemptStarted = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $queryUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connectTimeout'] ?? 4),
            CURLOPT_TIMEOUT        => (int)($options['requestTimeout'] ?? 5),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
            CURLOPT_USERAGENT      => 'Skyesoft-PhoenixDesignationResolver/1.2'
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $attemptTimeMs = (int)round((microtime(true) - $attemptStarted) * 1000);

        $decoded = null;
        $jsonError = null;
        $arcGisError = null;

        if ($response !== false && $curlError === '') {
            $decoded = json_decode((string)$response, true);
            if (!is_array($decoded)) {
                $jsonError = json_last_error_msg();
            } elseif (!empty($decoded['error'])) {
                $arcGisError = $decoded['error']['message'] ?? 'ArcGIS Error Response';
            }
        }

        // Defensive verification that $decoded is an array containing a valid features array
        $hasFeaturesArray = is_array($decoded) && array_key_exists('features', $decoded) && is_array($decoded['features']);

        $isValidSuccess = (
            $response !== false &&
            $curlError === '' &&
            $httpCode >= 200 &&
            $httpCode < 300 &&
            $hasFeaturesArray &&
            $arcGisError === null
        );

        $attemptLogs[] = "Attempt {$attempt}/{$maxAttempts}: HTTP {$httpCode}, JSONErr: " . ($jsonError ?? 'none') . ", ArcGISErr: " . ($arcGisError ?? 'none') . " ({$attemptTimeMs}ms)";

        $lastAttempt = [
            'success'     => $isValidSuccess,
            'httpCode'    => $httpCode,
            'curlError'   => $curlError,
            'jsonError'   => $jsonError,
            'arcGisError' => $arcGisError,
            'features'    => $hasFeaturesArray ? $decoded['features'] : [],
            'attempt'     => $attempt
        ];

        if ($isValidSuccess || $attempt === $maxAttempts) {
            break;
        }

        usleep(min($baseDelayMs * (2 ** ($attempt - 1)), 4000) * 1000);
    }

    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

    if (!$lastAttempt['success']) {
        $errorMessage = $lastAttempt['arcGisError'] ?? $lastAttempt['jsonError'] ?? $lastAttempt['curlError'] ?? 'HTTP ' . $lastAttempt['httpCode'];
        error_log("[PHOENIX-DESIGNATIONS-ERROR] Query failed for {$serviceUrl} | " . implode(' | ', $attemptLogs));

        return [
            'success'        => false,
            'httpCode'       => $lastAttempt['httpCode'],
            'features'       => [],
            'errorMessage'   => $errorMessage,
            'responseTimeMs' => $elapsedMs
        ];
    }

    return [
        'success'        => true,
        'httpCode'       => $lastAttempt['httpCode'],
        'features'       => $lastAttempt['features'],
        'errorMessage'   => null,
        'responseTimeMs' => $elapsedMs
    ];
}

function parsePhoenixOverlayFeatures(array $queryResult, string $sourceName): array {
    if (!$queryResult['success']) {
        return [
            'determination' => null,
            'status'        => 'error',
            'errorMessage'  => $queryResult['errorMessage'] ?? 'Service Query Failed',
            'matches'       => [],
            'source'        => $sourceName,
            'checkedAt'     => time()
        ];
    }

    if (empty($queryResult['features'])) {
        return [
            'determination' => 'no',
            'status'        => 'noneIdentified',
            'errorMessage'  => null,
            'matches'       => [],
            'source'        => $sourceName,
            'checkedAt'     => time()
        ];
    }

    $matches = [];
    foreach ($queryResult['features'] as $f) {
        $attrs = $f['attributes'] ?? [];
        $name = trim((string)($attrs['NAME'] ?? 'Special Overlay District'));
        if ($name !== '') {
            $matches[] = [
                'name'            => $name,
                'caseYear'        => $attrs['CASE_YR'] ?? null,
                'regulatoryInfo'  => $attrs['REGULATORY'] ?? null,
                'rawAttributes'   => $attrs
            ];
        }
    }

    return [
        'determination' => !empty($matches) ? 'yes' : 'no',
        'status'        => !empty($matches) ? 'identified' : 'noneIdentified',
        'errorMessage'  => null,
        'matches'       => $matches,
        'source'        => $sourceName,
        'checkedAt'     => time()
    ];
}

function parsePhoenixHistoricFeatures(array $queryResult, string $sourceName): array {
    if (!$queryResult['success']) {
        return [
            'determination' => null,
            'status'        => 'error',
            'errorMessage'  => $queryResult['errorMessage'] ?? 'Service Query Failed',
            'matches'       => [],
            'source'        => $sourceName,
            'checkedAt'     => time()
        ];
    }

    if (empty($queryResult['features'])) {
        return [
            'determination' => 'no',
            'status'        => 'noneIdentified',
            'errorMessage'  => null,
            'matches'       => [],
            'source'        => $sourceName,
            'checkedAt'     => time()
        ];
    }

    $matches = [];
    foreach ($queryResult['features'] as $f) {
        $attrs = $f['attributes'] ?? [];
        $name = trim((string)($attrs['NAME'] ?? 'Historic District'));
        if ($name !== '') {
            $matches[] = [
                'name'          => $name,
                'type'          => $attrs['TYPE'] ?? 'Historic District',
                'status'        => $attrs['STATUS'] ?? null,
                'landmark'      => $attrs['LANDMARK'] ?? null,
                'rawAttributes' => $attrs
            ];
        }
    }

    return [
        'determination' => !empty($matches) ? 'yes' : 'no',
        'status'        => !empty($matches) ? 'identified' : 'noneIdentified',
        'errorMessage'  => null,
        'matches'       => $matches,
        'source'        => $sourceName,
        'checkedAt'     => time()
    ];
}

/**
 * Diagnostic parser for the Phoenix Planning & Permit layer.
 *
 * This intentionally does NOT classify a returned feature as a CSP.
 * A known-positive CSP location should be used to inspect rawAttributes
 * and identify the authoritative Phoenix field/value combination first.
 */
function parsePhoenixCspDiagnosticFeatures(array $queryResult, string $sourceName): array {
    if (!$queryResult['success']) {
        return [
            'determination' => null,
            'status'        => 'notAvailable',
            'caseNumber'    => null,
            'cases'         => [],
            'source'        => $sourceName,
            'checkedAt'     => time(),
            'errorMessage'  => $queryResult['errorMessage'] ?? 'Service Query Failed'
        ];
    }

    $cases = [];
    foreach ($queryResult['features'] as $feature) {
        $attrs = $feature['attributes'] ?? [];

        if (!is_array($attrs)) {
            continue;
        }

        $cases[] = [
            'rawAttributes' => $attrs
        ];
    }

    // Log raw records server-side as an additional diagnostic trail.
    error_log(
        '[PHOENIX-CSP-DIAGNOSTIC] Feature count: ' . count($cases) .
        ' | Attributes: ' . json_encode($cases, JSON_UNESCAPED_SLASHES)
    );

    return [
        'determination' => null,
        'status'        => 'diagnostic',
        'caseNumber'    => null,
        'cases'         => $cases,
        'source'        => $sourceName,
        'checkedAt'     => time(),
        'errorMessage'  => null
    ];
}