<?php

declare(strict_types=1);

/**
 * Skyesoft — Jurisdictional Zoning Resolution Utility
 *
 * File Version:     1.5.6
 * Schema Version:   3.4.0
 * Last Updated:     2026-08-07
 * PHP Version:      8.0+
 */

#region SECTION 00 — Public Resolver

if (file_exists(__DIR__ . '/resolvePhoenixSpecialDesignations.php')) {
    require_once __DIR__ . '/resolvePhoenixSpecialDesignations.php';
}

function resolveZoning(
    ?string $jurisdictionName,
    ?float $latitude,
    ?float $longitude,
    ?string $apnRaw = null,
    array $options = []
): array {
    $startedAt = microtime(true);
    $result = buildZoningResult([
        'jurisdictionName' => normalizeZoningJurisdictionName($jurisdictionName, $options),
        'apnRaw'           => normalizeZoningApn($apnRaw)
    ]);

    if ($result['jurisdictionName'] === null) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unresolved',
            'reason'  => 'missing_jurisdiction',
            'message' => 'Zoning could not be resolved without a governing jurisdiction.'
        ]);
    }

    $registryResult = loadJurisdictionZoningConfig($result['jurisdictionName'], $options);

    if (!$registryResult['success']) {
        $configStatus = $registryResult['reason'] === 'zoning_config_missing' ? 'not_configured' : 'unavailable';
        return finalizeZoningResult($result, $startedAt, [
            'status'  => $configStatus,
            'reason'  => $registryResult['reason'],
            'message' => $registryResult['message']
        ]);
    }

    $source = $registryResult['source'];
    $result['provider']     = $source['provider'] ?? null;
    $result['queryMethod']  = $source['queryMethod'] ?? null;
    $result['zoningSource'] = $source['provider'] ?? null;
    $result['sourceUrl']    = $source['serviceUrl'] ?? null;

    if (($source['isActive'] ?? false) !== true) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'not_configured',
            'reason'  => 'jurisdiction_source_inactive',
            'message' => 'The configured zoning source is not active.'
        ]);
    }

    $provider = strtolower(trim((string)($source['adapter'] ?? '')));
    if (!in_array($provider, ['arcgis_feature_service', 'arcgis_map_service'], true)) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unsupported',
            'reason'  => 'unsupported_zoning_provider',
            'message' => 'The configured zoning provider is not currently supported.'
        ]);
    }

    $queryResult = queryArcGisZoningSource($source, $latitude, $longitude, $result['apnRaw'], $options);

    if (!$queryResult['success']) {
        return finalizeZoningResult($result, $startedAt, [
            'status'         => $queryResult['status'],
            'reason'         => $queryResult['reason'],
            'message'        => $queryResult['message'],
            'httpCode'       => $queryResult['httpCode'] ?? null,
            'responseTimeMs' => $queryResult['responseTimeMs'] ?? null,
            'attempts'       => $queryResult['attempts'] ?? 1
        ]);
    }

    $features = $queryResult['features'];
    if (count($features) === 0) {
        return finalizeZoningResult($result, $startedAt, [
            'status'         => 'unresolved',
            'reason'         => 'no_zoning_feature_at_coordinate',
            'message'        => 'The official zoning source returned no zoning feature.',
            'httpCode'       => $queryResult['httpCode'] ?? null,
            'responseTimeMs' => $queryResult['responseTimeMs'],
            'attempts'       => $queryResult['attempts'] ?? 1
        ]);
    }

    $normalizedFeatures = [];
    foreach ($features as $feature) {
        $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
        $normalizedFeatures[] = normalizeZoningFeature($attributes, $source);
    }

    $normalizedFeatures = array_values(array_filter(
        $normalizedFeatures,
        static fn(array $f): bool => $f['zoningCode'] !== null || $f['zoningDescription'] !== null
    ));

    if (empty($normalizedFeatures)) {
        return finalizeZoningResult($result, $startedAt, [
            'status'         => 'unresolved',
            'reason'         => 'zoning_fields_empty',
            'message'        => 'A zoning feature was found, but its configured zoning fields were empty.',
            'httpCode'       => $queryResult['httpCode'] ?? null,
            'responseTimeMs' => $queryResult['responseTimeMs'],
            'attempts'       => $queryResult['attempts'] ?? 1
        ]);
    }

    $primaryFeature = $normalizedFeatures[0];
    $baseRequiresReview = count($normalizedFeatures) > 1;

    // Overlay Processing
    $specialDesignations = null;
    $overlayHandler = $options['overlayResolver'] ?? $source['overlayResolver'] ?? null;

    if ($overlayHandler === null && strtolower($result['jurisdictionName']) === 'phoenix') {
        $overlayHandler = 'resolvePhoenixSpecialDesignations';
    }

    if ($latitude !== null && $longitude !== null && !empty($overlayHandler) && is_callable($overlayHandler)) {
        $specialDesignations = call_user_func($overlayHandler, (float)$latitude, (float)$longitude);
    }

    $formatDesignationString = static function (mixed $data, string $defaultNone, string $errorFallback = 'Unable to Verify'): string {
        if (is_string($data) && $data !== '') return $data;
        if (is_array($data)) {
            if (($data['status'] ?? '') === 'error' || ($data['status'] ?? '') === 'requiresResearch') return $errorFallback;
            if (!empty($data['matches'][0]['name'])) return (string)$data['matches'][0]['name'];
            if (!empty($data['caseNumber'])) return 'CSP #' . $data['caseNumber'];
            if (($data['determination'] ?? '') === 'no' || ($data['status'] ?? '') === 'noneIdentified') return $defaultNone;
        }
        return $defaultNone;
    };

    $overlayPlan = $formatDesignationString($specialDesignations['zoningOverlays'] ?? null, 'None');

    // DTC Regulating Plan fallback check with explicit character area dictionary
    if ($overlayPlan === 'None') {
        $code = $primaryFeature['zoningCode'] ?? '';
        if (str_starts_with($code, 'DTC-')) {
            $dtcAreas = [
                'DTC-BCORE'          => 'Business Core',
                'DTC-BUSINESS CORE*' => 'Business Core'
            ];

            $codeKey = strtoupper(trim($code));
            $characterArea = $dtcAreas[$codeKey] ?? trim(str_replace(['DTC-', '*'], '', $code));
            $overlayPlan = "Downtown Code - {$characterArea} Regulating Plan";

            if (!is_array($specialDesignations)) {
                $specialDesignations = [
                    'zoningOverlays'        => null,
                    'historicDesignation'   => null,
                    'comprehensiveSignPlan' => null,
                    'isComplete'            => true
                ];
            }
            $specialDesignations['zoningOverlays'] = [
                'determination' => 'yes',
                'status'        => 'identified',
                'matches'       => [['name' => $overlayPlan, 'type' => 'regulatingPlan']],
                'source'        => 'City of Phoenix Zoning GIS — DTC base-zoning classification',
                'checkedAt'     => time()
            ];
        }
    }

    $historicDesignation   = $formatDesignationString($specialDesignations['historicDesignation'] ?? null, 'None');
    $comprehensiveSignPlan = $formatDesignationString($specialDesignations['comprehensiveSignPlan'] ?? null, 'None On Record', 'Unable to Verify');

    // JSON Encoding Diagnostics
    $specialDesignationsJson = null;
    $jsonEncodeError = false;
    if ($specialDesignations !== null) {
        $encoded = json_encode($specialDesignations, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $specialDesignationsJson = $encoded;
        } else {
            $jsonEncodeError = true;
            error_log('[RESOLVE-ZONING] JSON encoding failed: ' . json_last_error_msg());
        }
    }

    // Evaluate Review Routing Rules
    $designationsComplete = $specialDesignations === null || ($specialDesignations['isComplete'] ?? false);
    
    // Explicit structural check for non-informational overlay matches
    $isInformationalRegulatingPlan = 
        (($specialDesignations['zoningOverlays']['matches'][0]['type'] ?? '') === 'regulatingPlan');

    $hasActionableOverlay = 
        (($specialDesignations['zoningOverlays']['determination'] ?? '') === 'yes') && !$isInformationalRegulatingPlan;

    $hasPositiveDesignation = false;
    if (is_array($specialDesignations)) {
        $hasPositiveDesignation = 
            (($specialDesignations['historicDesignation']['determination'] ?? '') === 'yes') ||
            (($specialDesignations['comprehensiveSignPlan']['determination'] ?? '') === 'yes') ||
            $hasActionableOverlay;
    }

    $requiresReview = $baseRequiresReview || !$designationsComplete || $hasPositiveDesignation || $jsonEncodeError;

    $status = 'resolved';
    $reason = null;

    if ($baseRequiresReview) {
        $status = 'review_required';
        $reason = 'multiple_zoning_features';
    } elseif (!$designationsComplete) {
        $status = 'review_required';
        $reason = 'special_designations_incomplete';
    } elseif ($hasPositiveDesignation) {
        $status = 'review_required';
        $reason = 'special_designations_identified';
    } elseif ($jsonEncodeError) {
        $status = 'review_required';
        $reason = 'special_designations_json_encoding_failed';
    }

    return finalizeZoningResult($result, $startedAt, [
        'success'                 => !$requiresReview,
        'status'                  => $status,
        'reason'                  => $reason,
        'message'                 => $requiresReview ? 'Zoning resolved, but human review is required.' : 'Base zoning and special designations resolved successfully.',
        'zoningCode'              => $primaryFeature['zoningCode'],
        'zoningDescription'       => $primaryFeature['zoningDescription'],
        'zoningVerifiedAt'        => time(),
        'overlayPlan'             => $overlayPlan,
        'historicDesignation'     => $historicDesignation,
        'comprehensiveSignPlan'   => $comprehensiveSignPlan,
        'specialDesignations'     => $specialDesignations,
        'specialDesignationsJson' => $specialDesignationsJson,
        'confidence'              => $requiresReview ? 70 : (int)($source['successfulResultConfidence'] ?? 95),
        'requiresReview'          => $requiresReview,
        'candidateCount'          => count($normalizedFeatures),
        'candidates'              => $normalizedFeatures,
        'raw'                     => ['attributes' => $primaryFeature['rawAttributes']],
        'httpCode'                => $queryResult['httpCode'] ?? null,
        'responseTimeMs'          => $queryResult['responseTimeMs'],
        'attempts'                => $queryResult['attempts'] ?? 1
    ]);
}

#endregion

#region SECTION 01 — Configuration & Helpers

function loadJurisdictionZoningConfig(string $jurisdictionName, array $options = []): array {
    $slug = normalizeZoningSlug($jurisdictionName);
    if ($slug === '') {
        return ['success' => false, 'source' => null, 'reason' => 'invalid_jurisdiction_name', 'message' => 'Invalid jurisdiction slug.'];
    }

    if (!empty($options['config']) && is_array($options['config'])) {
        $config = $options['config'];
    } else {
        $root = rtrim((string)($options['jurisdictionsPath'] ?? __DIR__ . '/../../data/authoritative/jurisdictions'), '/\\');
        $configPath = !empty($options['configPath']) ? trim((string)$options['configPath']) : $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'zoning.json';

        if (!is_file($configPath)) {
            return ['success' => false, 'source' => null, 'reason' => 'zoning_config_missing', 'message' => 'No config found.'];
        }

        $json = @file_get_contents($configPath);
        $config = is_string($json) ? json_decode($json, true) : null;
    }

    if (!is_array($config)) {
        return ['success' => false, 'source' => null, 'reason' => 'zoning_config_invalid_json', 'message' => 'Invalid JSON in config.'];
    }

    $service    = is_array($config['service'] ?? null) ? $config['service'] : [];
    $query      = is_array($config['query'] ?? null) ? $config['query'] : [];
    $http       = is_array($config['http'] ?? null) ? $config['http'] : [];
    $mapping    = is_array($config['fieldMapping'] ?? null) ? $config['fieldMapping'] : [];
    $validation = is_array($config['validation'] ?? null) ? $config['validation'] : [];

    $serviceUrl  = rtrim(trim((string)($service['serviceUrl'] ?? '')), '/');
    $layerId     = $service['layerId'] ?? null;
    $serviceType = strtoupper(trim((string)($service['serviceType'] ?? '')));

    if ($serviceUrl === '' || !is_int($layerId) || $layerId < 0) {
        return ['success' => false, 'source' => null, 'reason' => 'zoning_config_invalid', 'message' => 'Invalid URL or Layer ID.'];
    }

    $adapter = str_contains($serviceType, 'MAP') ? 'arcgis_map_service' : (str_contains($serviceType, 'FEATURE') ? 'arcgis_feature_service' : '');

    return [
        'success' => true,
        'source'  => [
            'provider'                   => normalizeZoningText($service['provider'] ?? null),
            'adapter'                    => $adapter,
            'queryMethod'                => strtolower((string)($query['method'] ?? 'point_intersection')),
            'serviceUrl'                 => $serviceUrl . '/' . $layerId,
            'isActive'                   => ($service['status'] ?? null) === 'configured',
            'overlayResolver'            => normalizeZoningText($config['jurisdiction']['overlayResolver'] ?? null),
            'codeFields'                 => $mapping['zoningCode'] ?? [],
            'descriptionFields'          => $mapping['zoningDescription'] ?? [],
            'codedValueMappings'         => normalizeZoningCodedValueMappings($config['codedValueMappings'] ?? []),
            'additionalFields'           => array_values(array_unique(array_merge(
                normalizeZoningFieldList($query['outFields'] ?? []),
                normalizeZoningFieldList($mapping['caseNumber'] ?? []),
                normalizeZoningFieldList($mapping['ordinanceNumber'] ?? [])
            ))),
            'resultRecordCount'          => (int)($query['resultRecordCount'] ?? 10),
            'where'                      => (string)($query['where'] ?? '1=1'),
            'geometryType'               => (string)($query['geometryType'] ?? 'esriGeometryPoint'),
            'spatialRel'                 => (string)($query['spatialRelationship'] ?? 'esriSpatialRelIntersects'),
            'inSR'                       => (int)($query['inputSpatialReference'] ?? 4326),
            'returnGeometry'             => (bool)($query['returnGeometry'] ?? false),
            'successfulResultConfidence' => (int)($validation['successfulResultConfidence'] ?? 95),
            'httpMethod'                 => strtoupper(trim((string)($http['method'] ?? 'GET'))),
            'userAgent'                  => trim((string)($http['userAgent'] ?? 'Skyesoft-ZoningResolver/1.5 (+https://skyesoft.com)')),
            'referer'                    => trim((string)($http['referer'] ?? '')),
            'connectTimeout'             => (int)($http['connectTimeout'] ?? 5),
            'requestTimeout'             => (int)($http['requestTimeout'] ?? 5),
            'maxAttempts'                => max(1, min(5, (int)($http['maxAttempts'] ?? 2))),
            'retryDelayMs'               => max(0, min(5000, (int)($http['retryDelayMs'] ?? 500))),
            'retryOnStatuses'            => array_map('intval', (array)($http['retryOnStatuses'] ?? [408, 429, 500, 502, 503, 504]))
        ],
        'reason' => null,
        'message' => null
    ];
}

function queryArcGisZoningSource(array $source, ?float $latitude, ?float $longitude, ?string $apnRaw, array $options = []): array {
    $startedAt = microtime(true);
    $serviceUrl = rtrim(trim((string)($source['serviceUrl'] ?? '')), '/');

    if ($serviceUrl === '' || $latitude === null || $longitude === null) {
        return buildZoningProviderFailure('unresolved', 'missing_input', 'Missing coordinates or URL.', $startedAt);
    }

    $params = [
        'where'             => '1=1',
        'geometry'          => $longitude . ',' . $latitude,
        'geometryType'      => (string)($source['geometryType'] ?? 'esriGeometryPoint'),
        'spatialRel'        => (string)($source['spatialRel'] ?? 'esriSpatialRelIntersects'),
        'inSR'              => (int)($source['inSR'] ?? 4326),
        'outFields'         => implode(',', collectZoningOutFields($source)),
        'returnGeometry'    => !empty($source['returnGeometry']) ? 'true' : 'false',
        'f'                 => 'json',
        'resultRecordCount' => (int)($source['resultRecordCount'] ?? 10)
    ];

    $maxAttempts = $source['maxAttempts'] ?? 2;
    $baseDelayMs = $source['retryDelayMs'] ?? 500;
    $lastResponse = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($serviceUrl . '/query?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)($source['connectTimeout'] ?? 5)),
            CURLOPT_TIMEOUT        => max(3, (int)($source['requestTimeout'] ?? 5)),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
            CURLOPT_USERAGENT      => $source['userAgent'] ?? 'Skyesoft-ZoningResolver/1.5'
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr     = curl_error($ch);
        curl_close($ch);

        $decoded = ($rawResponse !== false && $httpCode === 200) ? json_decode((string)$rawResponse, true) : null;
        $hasArcGisError = is_array($decoded) && !empty($decoded['error']);

        $lastResponse = [
            'raw'      => $rawResponse,
            'httpCode' => $httpCode,
            'decoded'  => $decoded,
            'attempt'  => $attempt
        ];

        if ($rawResponse !== false && $httpCode === 200 && is_array($decoded) && !$hasArcGisError) {
            break;
        }

        if ($attempt < $maxAttempts) {
            usleep(min($baseDelayMs * (2 ** ($attempt - 1)), 3000) * 1000);
        }
    }

    $elapsed = (int)round((microtime(true) - $startedAt) * 1000);

    if (empty($lastResponse['decoded']) || !empty($lastResponse['decoded']['error'])) {
        return [
            'success'        => false,
            'status'         => 'unavailable',
            'reason'         => 'arcgis_service_error',
            'message'        => 'ArcGIS query failed or returned error.',
            'httpCode'       => $lastResponse['httpCode'],
            'responseTimeMs' => $elapsed,
            'features'       => [],
            'attempts'       => $lastResponse['attempt']
        ];
    }

    return [
        'success'        => true,
        'status'         => 'resolved',
        'reason'         => null,
        'message'        => null,
        'httpCode'       => $lastResponse['httpCode'],
        'responseTimeMs' => $elapsed,
        'features'       => is_array($lastResponse['decoded']['features'] ?? null) ? $lastResponse['decoded']['features'] : [],
        'attempts'       => $lastResponse['attempt']
    ];
}

function collectZoningOutFields(array $source): array {
    $fields = array_merge((array)($source['codeFields'] ?? []), (array)($source['descriptionFields'] ?? []), (array)($source['additionalFields'] ?? []));
    $fields = array_values(array_unique(array_filter(array_map('trim', $fields))));
    return !empty($fields) ? $fields : ['*'];
}

function normalizeZoningFeature(array $attributes, array $source): array {
    $zoningCode = findZoningAttribute($attributes, normalizeZoningFieldList($source['codeFields'] ?? []));
    $zoningDesc = findZoningAttribute($attributes, normalizeZoningFieldList($source['descriptionFields'] ?? []));
    return ['zoningCode' => $zoningCode, 'zoningDescription' => $zoningDesc, 'rawAttributes' => $attributes];
}

function normalizeZoningCodedValueMappings(mixed $mappings): array { return is_array($mappings) ? $mappings : []; }
function normalizeZoningFieldList(mixed $fields): array { return is_array($fields) ? $fields : (is_string($fields) ? [$fields] : []); }
function findZoningAttribute(array $attributes, array $fields): ?string {
    foreach ($fields as $f) {
        if (!empty($attributes[$f])) {
            // Trim whitespace and strip trailing asterisks
            return rtrim(trim((string)$attributes[$f]), '* ');
        }
    }
    return null;
}

function buildZoningResult(array $overrides = []): array {
    return array_merge([
        'success' => false, 'status' => 'pending', 'reason' => null, 'message' => null,
        'jurisdictionName' => null, 'apnRaw' => null, 'zoningCode' => null, 'zoningDescription' => null,
        'zoningSource' => null, 'zoningVerifiedAt' => null, 'specialDesignations' => null, 'specialDesignationsJson' => null,
        'provider' => null, 'queryMethod' => null, 'sourceUrl' => null, 'confidence' => 0, 'requiresReview' => true,
        'candidateCount' => 0, 'candidates' => [], 'raw' => [], 'httpCode' => null, 'responseTimeMs' => null, 'elapsedMs' => null
    ], $overrides);
}

function finalizeZoningResult(array $result, float $startedAt, array $overrides = []): array {
    $result = array_merge($result, $overrides);
    $result['elapsedMs'] = (int)round((microtime(true) - $startedAt) * 1000);
    return $result;
}

function buildZoningProviderFailure(string $status, string $reason, string $message, float $startedAt): array {
    return ['success' => false, 'status' => $status, 'reason' => $reason, 'message' => $message, 'httpCode' => null, 'responseTimeMs' => (int)round((microtime(true) - $startedAt) * 1000), 'features' => []];
}

function normalizeZoningText(mixed $value): ?string { return (is_string($value) && trim($value) !== '') ? trim($value) : null; }
function normalizeZoningApn(mixed $value): ?string { return normalizeZoningText($value) ? strtoupper(preg_replace('/[^A-Z0-9-]/', '', (string)$value)) : null; }
function normalizeZoningKey(string $value): string { return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($value))))); }
function normalizeZoningJurisdictionName(?string $value, array $options = []): ?string {
    $name = normalizeZoningText($value);
    if ($name === null) return null;
    $aliases = $options['aliases'] ?? ['no city town' => 'Maricopa County', 'county' => 'Maricopa County', 'maricopa county' => 'Maricopa County'];
    return $aliases[normalizeZoningKey($name)] ?? $name;
}
function normalizeZoningSlug(string $value): string { return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))); }

#endregion