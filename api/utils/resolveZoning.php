<?php
declare(strict_types=1);

/**
 * Skyesoft — Jurisdictional Zoning Resolution Utility
 *
 * File Version:     1.0.0
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-17
 *
 * Resolves base zoning through a verified jurisdiction source registry.
 * Public ArcGIS FeatureServer and MapServer query layers are supported.
 */

#region SECTION 00 — Public Resolver

/**
 * Resolve base zoning for one parcel/location.
 *
 * @param string|null $jurisdictionName Governing permit jurisdiction.
 * @param float|null  $latitude         WGS84 latitude.
 * @param float|null  $longitude        WGS84 longitude.
 * @param string|null $apnRaw           Assessor parcel number.
 * @param array       $options          Optional registry/path/timeout overrides.
 */
function resolveZoning(
    ?string $jurisdictionName,
    ?float $latitude,
    ?float $longitude,
    ?string $apnRaw = null,
    array $options = []
): array {
    $startedAt = microtime(true);
    $verifiedAt = time();

    $result = buildZoningResult([
        'jurisdictionName' => normalizeZoningText($jurisdictionName),
        'apnRaw'           => normalizeZoningApn($apnRaw),
        'zoningVerifiedAt' => $verifiedAt
    ]);

    if ($result['jurisdictionName'] === null) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unresolved',
            'reason'  => 'missing_jurisdiction',
            'message' => 'Zoning could not be resolved without a governing jurisdiction.'
        ]);
    }

    $registryResult = loadZoningSourceRegistry($options);

    if (!$registryResult['success']) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unavailable',
            'reason'  => $registryResult['reason'],
            'message' => $registryResult['message']
        ]);
    }

    $source = findZoningSource(
        $registryResult['sources'],
        $result['jurisdictionName']
    );

    if ($source === null) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'not_configured',
            'reason'  => 'jurisdiction_source_not_configured',
            'message' => 'No verified zoning source is configured for this jurisdiction.'
        ]);
    }

    $result['provider'] = $source['provider'] ?? null;
    $result['queryMethod'] = $source['queryMethod'] ?? null;
    $result['zoningSource'] = $source['sourceCode'] ?? null;
    $result['sourceUrl'] = $source['serviceUrl'] ?? null;

    if (($source['isActive'] ?? false) !== true) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'not_configured',
            'reason'  => 'jurisdiction_source_inactive',
            'message' => 'The configured zoning source is not active.'
        ]);
    }

    $provider = strtolower(trim((string)($source['provider'] ?? '')));

    if (!in_array($provider, ['arcgis_feature_service', 'arcgis_map_service'], true)) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unsupported',
            'reason'  => 'unsupported_zoning_provider',
            'message' => 'The configured zoning provider is not currently supported.'
        ]);
    }

    $queryResult = queryArcGisZoningSource(
        $source,
        $latitude,
        $longitude,
        $result['apnRaw'],
        $options
    );

    if (!$queryResult['success']) {
        return finalizeZoningResult($result, $startedAt, [
            'status'       => $queryResult['status'],
            'reason'       => $queryResult['reason'],
            'message'      => $queryResult['message'],
            'httpCode'     => $queryResult['httpCode'] ?? null,
            'responseTime' => $queryResult['responseTimeMs'] ?? null
        ]);
    }

    $features = $queryResult['features'];
    $featureCount = count($features);

    if ($featureCount === 0) {
        return finalizeZoningResult($result, $startedAt, [
            'status'       => 'unresolved',
            'reason'       => 'no_zoning_feature_found',
            'message'      => 'The official zoning source returned no zoning feature.',
            'responseTime' => $queryResult['responseTimeMs']
        ]);
    }

    $normalizedFeatures = [];

    foreach ($features as $feature) {
        $attributes = is_array($feature['attributes'] ?? null)
            ? $feature['attributes']
            : [];

        $normalizedFeatures[] = normalizeZoningFeature($attributes, $source);
    }

    $normalizedFeatures = array_values(array_filter(
        $normalizedFeatures,
        function(array $feature): bool {
            return $feature['zoningCode'] !== null ||
                $feature['zoningDescription'] !== null;
        }
    ));

    if (empty($normalizedFeatures)) {
        return finalizeZoningResult($result, $startedAt, [
            'status'       => 'unresolved',
            'reason'       => 'zoning_fields_empty',
            'message'      => 'A zoning feature was found, but its configured zoning fields were empty.',
            'responseTime' => $queryResult['responseTimeMs']
        ]);
    }

    $primaryFeature = $normalizedFeatures[0];
    $requiresReview = count($normalizedFeatures) > 1;

    return finalizeZoningResult($result, $startedAt, [
        'success'           => !$requiresReview,
        'status'            => $requiresReview ? 'review_required' : 'resolved',
        'reason'            => $requiresReview ? 'multiple_zoning_features' : null,
        'message'           => $requiresReview
            ? 'Multiple zoning features intersect the submitted location.'
            : 'Base zoning resolved from the official jurisdiction source.',
        'zoningCode'        => $primaryFeature['zoningCode'],
        'zoningDescription' => $primaryFeature['zoningDescription'],
        'confidence'        => $requiresReview ? 70 : 95,
        'requiresReview'    => $requiresReview,
        'candidateCount'    => count($normalizedFeatures),
        'candidates'        => $normalizedFeatures,
        'raw'               => [
            'attributes' => $primaryFeature['rawAttributes']
        ],
        'responseTime'      => $queryResult['responseTimeMs']
    ]);
}

#endregion

#region SECTION 01 — Registry Loading + Jurisdiction Matching

/**
 * Load verified zoning sources.
 *
 * Registry default:
 * data/authoritative/zoningSourceRegistry.json
 */
function loadZoningSourceRegistry(array $options = []): array {
    if (!empty($options['registry']) && is_array($options['registry'])) {
        return [
            'success' => true,
            'sources' => $options['registry'],
            'reason'  => null,
            'message' => null
        ];
    }

    $registryPath = trim((string)(
        $options['registryPath']
        ?? __DIR__ . '/../../data/authoritative/zoningSourceRegistry.json'
    ));

    if ($registryPath === '' || !is_file($registryPath)) {
        return [
            'success' => false,
            'sources' => [],
            'reason'  => 'zoning_registry_missing',
            'message' => 'The authoritative zoning source registry is unavailable.'
        ];
    }

    $registryJson = @file_get_contents($registryPath);

    if (!is_string($registryJson) || trim($registryJson) === '') {
        return [
            'success' => false,
            'sources' => [],
            'reason'  => 'zoning_registry_unreadable',
            'message' => 'The authoritative zoning source registry could not be read.'
        ];
    }

    $registry = json_decode($registryJson, true);

    if (!is_array($registry)) {
        return [
            'success' => false,
            'sources' => [],
            'reason'  => 'zoning_registry_invalid_json',
            'message' => 'The authoritative zoning source registry contains invalid JSON.'
        ];
    }

    $sources = $registry['sources'] ?? $registry;

    if (!is_array($sources)) {
        return [
            'success' => false,
            'sources' => [],
            'reason'  => 'zoning_registry_invalid_shape',
            'message' => 'The authoritative zoning source registry has an invalid structure.'
        ];
    }

    return [
        'success' => true,
        'sources' => $sources,
        'reason'  => null,
        'message' => null
    ];
}

/**
 * Match a jurisdiction using its primary name or aliases.
 */
function findZoningSource(array $sources, string $jurisdictionName): ?array {
    $target = normalizeZoningKey($jurisdictionName);

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $names = array_merge(
            [(string)($source['jurisdictionName'] ?? '')],
            is_array($source['aliases'] ?? null)
                ? $source['aliases']
                : []
        );

        foreach ($names as $name) {
            if (normalizeZoningKey((string)$name) === $target) {
                return $source;
            }
        }
    }

    return null;
}

#endregion

#region SECTION 02 — ArcGIS Query Provider

/**
 * Query one configured public ArcGIS zoning layer.
 */
function queryArcGisZoningSource(
    array $source,
    ?float $latitude,
    ?float $longitude,
    ?string $apnRaw,
    array $options = []
): array {
    $startedAt = microtime(true);
    $serviceUrl = rtrim(trim((string)($source['serviceUrl'] ?? '')), '/');
    $queryMethod = strtolower(trim((string)(
        $source['queryMethod']
        ?? 'point_intersection'
    )));

    if ($serviceUrl === '') {
        return buildZoningProviderFailure(
            'unavailable',
            'missing_service_url',
            'The configured zoning source does not include a service URL.',
            $startedAt
        );
    }

    $fieldNames = collectZoningOutFields($source);

    $params = [
        'where'          => '1=1',
        'outFields'      => implode(',', $fieldNames),
        'returnGeometry' => 'false',
        'f'              => 'json',
        'resultRecordCount' => (int)($source['resultRecordCount'] ?? 10)
    ];

    if ($queryMethod === 'apn') {
        $apnField = trim((string)($source['apnField'] ?? ''));

        if ($apnField === '' || $apnRaw === null) {
            return buildZoningProviderFailure(
                'unresolved',
                'missing_apn_query_input',
                'The zoning source requires an APN and configured APN field.',
                $startedAt
            );
        }

        $escapedApn = str_replace("'", "''", $apnRaw);
        $params['where'] = $apnField . "='" . $escapedApn . "'";
    } else {
        if ($latitude === null || $longitude === null) {
            return buildZoningProviderFailure(
                'unresolved',
                'missing_coordinate_query_input',
                'The zoning source requires latitude and longitude.',
                $startedAt
            );
        }

        $params['geometry'] = $longitude . ',' . $latitude;
        $params['geometryType'] = 'esriGeometryPoint';
        $params['spatialRel'] = 'esriSpatialRelIntersects';
        $params['inSR'] = 4326;
        $params['outSR'] = 4326;
    }

    $queryUrl = $serviceUrl . '/query?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $connectTimeout = max(1, min(5, (int)(
        $options['connectTimeout']
        ?? $source['connectTimeout']
        ?? 2
    )));
    $requestTimeout = max(2, min(10, (int)(
        $options['requestTimeout']
        ?? $source['requestTimeout']
        ?? 5
    )));

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $queryUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $requestTimeout,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Cache-Control: no-cache'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $responseTimeMs = (int)round((microtime(true) - $startedAt) * 1000);

    if ($response === false || $curlError !== '') {
        error_log(
            '[RESOLVE-ZONING] ArcGIS request failed: ' .
            ($curlError !== '' ? $curlError : 'Unknown cURL error')
        );

        return [
            'success'       => false,
            'status'        => 'unavailable',
            'reason'        => 'arcgis_request_failed',
            'message'       => 'The official zoning service could not be reached.',
            'httpCode'      => $httpCode,
            'responseTimeMs'=> $responseTimeMs,
            'features'      => []
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success'       => false,
            'status'        => 'unavailable',
            'reason'        => 'arcgis_http_error',
            'message'       => 'The official zoning service returned an HTTP error.',
            'httpCode'      => $httpCode,
            'responseTimeMs'=> $responseTimeMs,
            'features'      => []
        ];
    }

    $decoded = json_decode((string)$response, true);

    if (!is_array($decoded)) {
        return [
            'success'       => false,
            'status'        => 'unavailable',
            'reason'        => 'arcgis_invalid_json',
            'message'       => 'The official zoning service returned invalid JSON.',
            'httpCode'      => $httpCode,
            'responseTimeMs'=> $responseTimeMs,
            'features'      => []
        ];
    }

    if (!empty($decoded['error'])) {
        error_log(
            '[RESOLVE-ZONING] ArcGIS error response: ' .
            json_encode($decoded['error'], JSON_UNESCAPED_SLASHES)
        );

        return [
            'success'       => false,
            'status'        => 'unavailable',
            'reason'        => 'arcgis_service_error',
            'message'       => 'The official zoning service rejected the query.',
            'httpCode'      => $httpCode,
            'responseTimeMs'=> $responseTimeMs,
            'features'      => []
        ];
    }

    return [
        'success'        => true,
        'status'         => 'resolved',
        'reason'         => null,
        'message'        => null,
        'httpCode'       => $httpCode,
        'responseTimeMs' => $responseTimeMs,
        'features'       => is_array($decoded['features'] ?? null)
            ? $decoded['features']
            : []
    ];
}

/**
 * Limit ArcGIS output to configured attributes.
 */
function collectZoningOutFields(array $source): array {
    $fields = [];

    foreach (['codeFields', 'descriptionFields', 'additionalFields'] as $key) {
        $configuredFields = $source[$key] ?? [];

        if (is_string($configuredFields)) {
            $configuredFields = [$configuredFields];
        }

        if (!is_array($configuredFields)) {
            continue;
        }

        foreach ($configuredFields as $field) {
            $field = trim((string)$field);

            if ($field !== '') {
                $fields[] = $field;
            }
        }
    }

    if (($source['queryMethod'] ?? null) === 'apn') {
        $apnField = trim((string)($source['apnField'] ?? ''));

        if ($apnField !== '') {
            $fields[] = $apnField;
        }
    }

    $fields = array_values(array_unique($fields));

    return !empty($fields) ? $fields : ['*'];
}

#endregion

#region SECTION 03 — Feature Normalization

/**
 * Normalize jurisdiction-specific fields into the Skyesoft contract.
 */
function normalizeZoningFeature(array $attributes, array $source): array {
    $codeFields = normalizeZoningFieldList($source['codeFields'] ?? []);
    $descriptionFields = normalizeZoningFieldList(
        $source['descriptionFields'] ?? []
    );

    return [
        'zoningCode'        => findZoningAttribute($attributes, $codeFields),
        'zoningDescription' => findZoningAttribute(
            $attributes,
            $descriptionFields
        ),
        'rawAttributes'     => $attributes
    ];
}

function normalizeZoningFieldList($fields): array {
    if (is_string($fields)) {
        $fields = [$fields];
    }

    if (!is_array($fields)) {
        return [];
    }

    return array_values(array_filter(array_map(
        function($field): string {
            return trim((string)$field);
        },
        $fields
    )));
}

function findZoningAttribute(array $attributes, array $possibleFields): ?string {
    foreach ($possibleFields as $field) {
        if (
            array_key_exists($field, $attributes) &&
            $attributes[$field] !== null &&
            trim((string)$attributes[$field]) !== ''
        ) {
            return trim((string)$attributes[$field]);
        }
    }

    return null;
}

#endregion

#region SECTION 04 — Result + Value Helpers

function buildZoningResult(array $overrides = []): array {
    return array_merge([
        'success'           => false,
        'status'            => 'pending',
        'reason'            => null,
        'message'           => null,
        'jurisdictionName'  => null,
        'apnRaw'            => null,
        'zoningCode'        => null,
        'zoningDescription' => null,
        'zoningSource'      => null,
        'zoningVerifiedAt'  => null,
        'provider'           => null,
        'queryMethod'        => null,
        'sourceUrl'          => null,
        'confidence'         => 0,
        'requiresReview'     => true,
        'candidateCount'     => 0,
        'candidates'         => [],
        'raw'                => [],
        'httpCode'           => null,
        'responseTimeMs'     => null,
        'elapsedMs'          => null
    ], $overrides);
}

function finalizeZoningResult(
    array $result,
    float $startedAt,
    array $overrides = []
): array {
    $result = array_merge($result, $overrides);
    $result['success'] = (bool)($result['success'] ?? false);
    $result['elapsedMs'] = (int)round(
        (microtime(true) - $startedAt) * 1000
    );

    error_log(
        '[RESOLVE-ZONING] Jurisdiction=' .
        ($result['jurisdictionName'] ?? 'NULL') .
        ' | APN=' . ($result['apnRaw'] ?? 'NULL') .
        ' | Status=' . ($result['status'] ?? 'unknown') .
        ' | Code=' . ($result['zoningCode'] ?? 'NULL') .
        ' | ElapsedMs=' . $result['elapsedMs']
    );

    return $result;
}

function buildZoningProviderFailure(
    string $status,
    string $reason,
    string $message,
    float $startedAt
): array {
    return [
        'success'        => false,
        'status'         => $status,
        'reason'         => $reason,
        'message'        => $message,
        'httpCode'       => null,
        'responseTimeMs' => (int)round(
            (microtime(true) - $startedAt) * 1000
        ),
        'features'       => []
    ];
}

function normalizeZoningText($value): ?string {
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $value = trim((string)$value);

    return $value !== '' ? $value : null;
}

function normalizeZoningApn($value): ?string {
    $value = normalizeZoningText($value);

    if ($value === null) {
        return null;
    }

    $value = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $value));

    return $value !== '' ? $value : null;
}

function normalizeZoningKey(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

    return trim(preg_replace('/\s+/', ' ', $value));
}

#endregion