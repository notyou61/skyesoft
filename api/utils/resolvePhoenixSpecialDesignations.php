<?php

declare(strict_types=1);

/**
 * Skyesoft — Phoenix Special Designations GIS Resolver
 * 
 * File Path: api/utils/resolvePhoenixSpecialDesignations.php
 * File Version: 1.0.0
 * Schema Version: 3.3.0
 * 
 * Executes spatial queries against official Phoenix ArcGIS endpoints 
 * for Zoning Overlays, Historic Properties, and Comprehensive Sign Plans.
 */

/**
 * Master spatial resolver for Phoenix Special Designations.
 *
 * @param float $latitude WGS84 Latitude
 * @param float $longitude WGS84 Longitude
 * @return array Normalized structure following Skyesoft v3.3.0 report contract
 */
function resolvePhoenixSpecialDesignations(float $latitude, float $longitude): array
{
    $now = time();

    $overlays = queryPhoenixZoningOverlays($latitude, $longitude, $now);
    $historic = queryPhoenixHistoricDesignations($latitude, $longitude, $now);
    $csp      = queryPhoenixComprehensiveSignPlan($latitude, $longitude, $now);

    $payload = [
        'zoningOverlays'        => $overlays,
        'historicDesignation'   => $historic,
        'comprehensiveSignPlan' => $csp,
    ];

    $payload['isComplete'] = specialDesignationsAreComplete($payload);

    return $payload;
}

/**
 * 1. Confirmed Phoenix Zoning Overlays GIS (Layer 0)
 * Endpoint: https://maps.phoenix.gov/pub/rest/services/Public/ZoningOverlays/MapServer/0
 */
function queryPhoenixZoningOverlays(float $lat, float $lng, int $timestamp): array
{
    $url = 'https://maps.phoenix.gov/pub/rest/services/Public/ZoningOverlays/MapServer/0/query';
    $params = [
        'geometry'     => "{$lng},{$lat}",
        'geometryType' => 'esriGeometryPoint',
        'spatialRel'   => 'esriSpatialRelIntersects',
        'inSR'         => 4326,
        'outFields'    => 'NAME,CASE_YR,REGULATORY',
        'f'            => 'json',
    ];

    $response = executePhoenixGisCurlRequest($url, $params);

    if (!$response['success']) {
        return [
            'determination' => null,
            'status'        => 'error',
            'matches'       => [],
            'source'        => 'City of Phoenix Zoning Overlays GIS (Layer 0)',
            'checkedAt'     => $timestamp,
            'errorMessage'  => $response['error'],
        ];
    }

    $features = $response['data']['features'] ?? [];

    if ($features === []) {
        return [
            'determination' => 'no',
            'status'        => 'noneIdentified',
            'matches'       => [],
            'source'        => 'City of Phoenix Zoning Overlays GIS (Layer 0)',
            'checkedAt'     => $timestamp,
        ];
    }

    $matches = [];
    foreach ($features as $f) {
        $attrs = $f['attributes'] ?? [];
        $name = trim((string)($attrs['NAME'] ?? ''));
        if ($name !== '') {
            $matches[] = [
                'name'       => $name,
                'caseYear'   => $attrs['CASE_YR'] ?? null,
                'regulatory' => $attrs['REGULATORY'] ?? null,
            ];
        }
    }

    return [
        'determination' => 'yes',
        'status'        => 'found',
        'matches'       => $matches,
        'source'        => 'City of Phoenix Zoning Overlays GIS (Layer 0)',
        'checkedAt'     => $timestamp,
    ];
}

/**
 * 2. Confirmed Phoenix Historic Properties MapServer (Layer 0)
 * Endpoint: https://maps.phoenix.gov/pub/rest/services/Public/HistoricProperties/MapServer/0
 */
function queryPhoenixHistoricDesignations(float $lat, float $lng, int $timestamp): array
{
    $url = 'https://maps.phoenix.gov/pub/rest/services/Public/HistoricProperties/MapServer/0/query';
    $params = [
        'geometry'     => "{$lng},{$lat}",
        'geometryType' => 'esriGeometryPoint',
        'spatialRel'   => 'esriSpatialRelIntersects',
        'inSR'         => 4326,
        'outFields'    => 'NAME,TYPE,STATUS,LANDMARK',
        'f'            => 'json',
    ];

    $response = executePhoenixGisCurlRequest($url, $params);

    if (!$response['success']) {
        return [
            'determination' => null,
            'status'        => 'error',
            'matches'       => [],
            'source'        => 'City of Phoenix Historic Properties GIS (Layer 0)',
            'checkedAt'     => $timestamp,
            'errorMessage'  => $response['error'],
        ];
    }

    $features = $response['data']['features'] ?? [];

    if ($features === []) {
        return [
            'determination' => 'no',
            'status'        => 'noneIdentified',
            'matches'       => [],
            'source'        => 'City of Phoenix Historic Properties GIS (Layer 0)',
            'checkedAt'     => $timestamp,
        ];
    }

    $matches = [];
    foreach ($features as $f) {
        $attrs = $f['attributes'] ?? [];
        $name = trim((string)($attrs['NAME'] ?? ''));
        if ($name !== '') {
            $matches[] = [
                'name'     => $name,
                'type'     => $attrs['TYPE'] ?? null,
                'status'   => $attrs['STATUS'] ?? null,
                'landmark' => $attrs['LANDMARK'] ?? null,
            ];
        }
    }

    return [
        'determination' => 'yes',
        'status'        => 'found',
        'matches'       => $matches,
        'source'        => 'City of Phoenix Historic Properties GIS (Layer 0)',
        'checkedAt'     => $timestamp,
    ];
}

/**
 * 3. Confirmed Phoenix Planning & Permit ZA Cases (Layer 4)
 * Candidate discovery for Comprehensive Sign Plan (CSP)
 * Endpoint: https://maps.phoenix.gov/pub/rest/services/Public/Planning_Permit/MapServer/4
 */
function queryPhoenixComprehensiveSignPlan(float $lat, float $lng, int $timestamp): array
{
    $url = 'https://maps.phoenix.gov/pub/rest/services/Public/Planning_Permit/MapServer/4/query';
    $params = [
        'geometry'     => "{$lng},{$lat}",
        'geometryType' => 'esriGeometryPoint',
        'spatialRel'   => 'esriSpatialRelIntersects',
        'inSR'         => 4326,
        'outFields'    => '*', // Retrieves full metadata attributes for inspection
        'f'            => 'json',
    ];

    $response = executePhoenixGisCurlRequest($url, $params);

    if (!$response['success']) {
        return [
            'determination' => null,
            'status'        => 'error',
            'caseNumber'    => null,
            'cases'         => [],
            'source'        => 'City of Phoenix Planning and Permit GIS (Layer 4)',
            'checkedAt'     => $timestamp,
            'errorMessage'  => $response['error'],
        ];
    }

    $features = $response['data']['features'] ?? [];

    // Zero candidates discovered = Authoritative No
    if ($features === []) {
        return [
            'determination' => 'no',
            'status'        => 'noneIdentified',
            'caseNumber'    => null,
            'cases'         => [],
            'source'        => 'City of Phoenix Planning and Permit GIS (Layer 4)',
            'checkedAt'     => $timestamp,
        ];
    }

    $candidateCases = array_map(
        static fn(array $f): array => ['rawAttributes' => $f['attributes'] ?? []],
        $features
    );

    // Candidate cases exist: blocks automatic yes/no until verified
    return [
        'determination' => null,
        'status'        => 'manualReviewRequired',
        'caseNumber'    => null,
        'cases'         => $candidateCases,
        'source'        => 'City of Phoenix Planning and Permit GIS (Layer 4)',
        'checkedAt'     => $timestamp,
    ];
}

/**
 * Shared cURL Request Engine for Phoenix GIS REST API.
 */
function executePhoenixGisCurlRequest(string $baseUrl, array $params): array
{
    $queryString = http_build_query($params);
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => "{$baseUrl}?{$queryString}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'Skyesoft-ZoningResolver/1.3',
    ]);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError !== '' || $httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP {$httpCode} / Error: {$curlError}"];
    }

    /** @var array|null $json */
    $json = json_decode((string)$body, true);

    if (!is_array($json) || isset($json['error'])) {
        return ['success' => false, 'error' => 'Invalid JSON or GIS service error response'];
    }

    return ['success' => true, 'data' => $json];
}

/**
 * Completion Gate Contract
 * Validates that all three determinations have reached terminal 'yes' or 'no' states.
 */
function specialDesignationsAreComplete(array $specialDesignations): bool
{
    $requiredKeys = ['zoningOverlays', 'historicDesignation', 'comprehensiveSignPlan'];

    foreach ($requiredKeys as $key) {
        $determination = $specialDesignations[$key]['determination'] ?? null;
        if ($determination !== 'yes' && $determination !== 'no') {
            return false;
        }
    }

    return true;
}