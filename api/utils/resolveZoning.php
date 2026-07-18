<?php
declare(strict_types=1);

/**
 * Skyesoft — Jurisdictional Zoning Resolution Utility
 *
 * File Version:     1.2.0
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-18
 *
 * Resolves base zoning through each jurisdiction's verified zoning.json.
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
 * @param array       $options          Optional config/path/timeout overrides.
 */
function resolveZoning(
    ?string $jurisdictionName,
    ?float $latitude,
    ?float $longitude,
    ?string $apnRaw = null,
    array $options = []
): array {
    $startedAt = microtime(true);
    $result = buildZoningResult([
        'jurisdictionName' => normalizeZoningText($jurisdictionName),
        'apnRaw'           => normalizeZoningApn($apnRaw)
    ]);

    if ($result['jurisdictionName'] === null) {
        return finalizeZoningResult($result, $startedAt, [
            'status'  => 'unresolved',
            'reason'  => 'missing_jurisdiction',
            'message' => 'Zoning could not be resolved without a governing jurisdiction.'
        ]);
    }

    $registryResult = loadJurisdictionZoningConfig(
        $result['jurisdictionName'],
        $options
    );

    if (!$registryResult['success']) {
        $configStatus = $registryResult['reason'] === 'zoning_config_missing'
            ? 'not_configured'
            : 'unavailable';

        return finalizeZoningResult($result, $startedAt, [
            'status'  => $configStatus,
            'reason'  => $registryResult['reason'],
            'message' => $registryResult['message']
        ]);
    }

    $source = $registryResult['source'];

    $result['provider'] = $source['provider'] ?? null;
    $result['queryMethod'] = $source['queryMethod'] ?? null;
    $result['zoningSource'] = $source['provider'] ?? null;
    $result['sourceUrl'] = $source['serviceUrl'] ?? null;

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
            'reason'       => 'no_zoning_feature_at_coordinate',
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
        'zoningVerifiedAt'  => time(),
        'confidence'        => $requiresReview
            ? 70
            : (int)($source['successfulResultConfidence'] ?? 95),
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

#region SECTION 01 — Jurisdiction Configuration Loading

/**
 * Load and normalize one jurisdiction's verified zoning.json.
 */
function loadJurisdictionZoningConfig(
    string $jurisdictionName,
    array $options = []
): array {
    $slug = normalizeZoningSlug($jurisdictionName);

    if ($slug === '') {
        return [
            'success' => false,
            'source'  => null,
            'reason'  => 'invalid_jurisdiction_name',
            'message' => 'The jurisdiction name could not be converted to a safe configuration path.'
        ];
    }

    if (!empty($options['config']) && is_array($options['config'])) {
        $config = $options['config'];
    } else {
        $root = rtrim((string)(
            $options['jurisdictionsPath']
            ?? __DIR__ . '/../../data/authoritative/jurisdictions'
        ), '/\\');
        $configPath = !empty($options['configPath'])
            ? trim((string)$options['configPath'])
            : $root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'zoning.json';

        if ($configPath === '' || !is_file($configPath)) {
            return [
                'success' => false,
                'source'  => null,
                'reason'  => 'zoning_config_missing',
                'message' => 'No zoning configuration is available for this jurisdiction.'
            ];
        }

        $registryJson = @file_get_contents($configPath);

        if (!is_string($registryJson) || trim($registryJson) === '') {
            return [
                'success' => false,
                'source'  => null,
                'reason'  => 'zoning_config_unreadable',
                'message' => 'The jurisdiction zoning configuration could not be read.'
            ];
        }

        $config = json_decode($registryJson, true);
    }

    if (!is_array($config)) {
        return [
            'success' => false,
            'source'  => null,
            'reason'  => 'zoning_config_invalid_json',
            'message' => 'The jurisdiction zoning configuration contains invalid JSON.'
        ];
    }

    $configuredSlug = normalizeZoningSlug((string)(
        $config['jurisdiction']['slug']
        ?? $config['jurisdiction']['label']
        ?? ''
    ));

    if ($configuredSlug === '' || $configuredSlug !== $slug) {
        return [
            'success' => false,
            'source'  => null,
            'reason'  => 'zoning_config_jurisdiction_mismatch',
            'message' => 'The zoning configuration does not match the requested jurisdiction.'
        ];
    }

    $service = is_array($config['service'] ?? null) ? $config['service'] : [];
    $query = is_array($config['query'] ?? null) ? $config['query'] : [];
    $mapping = is_array($config['fieldMapping'] ?? null)
        ? $config['fieldMapping']
        : [];
    $validation = is_array($config['validation'] ?? null)
        ? $config['validation']
        : [];
    $codedValueMappings = normalizeZoningCodedValueMappings(
        $config['codedValueMappings'] ?? []
    );

    $serviceUrl = rtrim(trim((string)($service['serviceUrl'] ?? '')), '/');
    $layerId = $service['layerId'] ?? null;
    $serviceType = strtoupper(trim((string)($service['serviceType'] ?? '')));

    if ($serviceUrl === '' || !is_int($layerId) || $layerId < 0) {
        return [
            'success' => false,
            'source'  => null,
            'reason'  => 'zoning_config_invalid',
            'message' => 'The zoning configuration is missing a valid service URL or layer ID.'
        ];
    }

    $adapter = strpos($serviceType, 'MAP') !== false
        ? 'arcgis_map_service'
        : (strpos($serviceType, 'FEATURE') !== false
            ? 'arcgis_feature_service'
            : '');

    $source = [
        'provider'                    => normalizeZoningText($service['provider'] ?? null),
        'adapter'                     => $adapter,
        'queryMethod'                 => strtolower((string)($query['method'] ?? 'point_intersection')),
        'serviceUrl'                  => $serviceUrl . '/' . $layerId,
        'isActive'                    => ($service['status'] ?? null) === 'configured',
        'codeFields'                  => $mapping['zoningCode'] ?? [],
        'descriptionFields'           => $mapping['zoningDescription'] ?? [],
        'codedValueMappings'          => $codedValueMappings,
        'additionalFields'            => array_values(array_unique(array_merge(
            normalizeZoningFieldList($query['outFields'] ?? []),
            normalizeZoningFieldList($mapping['caseNumber'] ?? []),
            normalizeZoningFieldList($mapping['ordinanceNumber'] ?? []),
            normalizeZoningFieldList($mapping['historic'] ?? []),
            normalizeZoningFieldList($mapping['transitOrientedDevelopment'] ?? [])
        ))),
        'resultRecordCount'           => (int)($query['resultRecordCount'] ?? 10),
        'requestTimeout'              => (int)($query['timeoutSeconds'] ?? 5),
        'where'                       => (string)($query['where'] ?? '1=1'),
        'geometryType'                => (string)($query['geometryType'] ?? 'esriGeometryPoint'),
        'spatialRel'                  => (string)($query['spatialRelationship'] ?? 'esriSpatialRelIntersects'),
        'inSR'                        => (int)($query['inputSpatialReference'] ?? 4326),
        'returnGeometry'              => (bool)($query['returnGeometry'] ?? false),
        'successfulResultConfidence' => (int)($validation['successfulResultConfidence'] ?? 95)
    ];

    return ['success' => true, 'source' => $source, 'reason' => null, 'message' => null];
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
        'where'          => (string)($source['where'] ?? '1=1'),
        'outFields'      => implode(',', $fieldNames),
        'returnGeometry' => !empty($source['returnGeometry']) ? 'true' : 'false',
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
        $params['geometryType'] = (string)(
            $source['geometryType'] ?? 'esriGeometryPoint'
        );
        $params['spatialRel'] = (string)(
            $source['spatialRel'] ?? 'esriSpatialRelIntersects'
        );
        $params['inSR'] = (int)($source['inSR'] ?? 4326);
    }

    $queryUrl = $serviceUrl . '/query?' . http_build_query(
        $params,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $connectTimeout = max(1, min(10, (int)(
        $options['connectTimeout']
        ?? $source['connectTimeout']
        ?? 2
    )));
    $requestTimeout = max(2, min(60, (int)(
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

    $zoningCode = findZoningAttribute($attributes, $codeFields);
    $zoningDescription = findZoningAttribute(
        $attributes,
        $descriptionFields
    );
    $codedValue = findZoningCodedValueMapping(
        $attributes,
        $source['codedValueMappings'] ?? []
    );

    if ($codedValue !== null) {
        $zoningCode = $codedValue['zoningCode'] ?? $zoningCode;
        $zoningDescription = $codedValue['zoningDescription']
            ?? $zoningDescription;
    }

    return [
        'zoningCode'        => $zoningCode,
        'zoningDescription' => $zoningDescription,
        'rawAttributes'     => $attributes
    ];
}

/**
 * Normalize config-driven ArcGIS coded-value translations.
 *
 * Expected JSON shape:
 * codedValueMappings.FIELD.RAW_VALUE = {
 *   "zoningCode": "...",
 *   "zoningDescription": "..."
 * }
 */
function normalizeZoningCodedValueMappings(mixed $mappings): array {
    if (!is_array($mappings)) {
        return [];
    }

    $normalized = [];

    foreach ($mappings as $field => $values) {
        $field = trim((string)$field);

        if ($field === '' || !is_array($values)) {
            continue;
        }

        foreach ($values as $rawValue => $translation) {
            if (!is_array($translation)) {
                continue;
            }

            $zoningCode = normalizeZoningText(
                $translation['zoningCode'] ?? null
            );
            $zoningDescription = normalizeZoningText(
                $translation['zoningDescription'] ?? null
            );

            if ($zoningCode === null && $zoningDescription === null) {
                continue;
            }

            $normalized[$field][trim((string)$rawValue)] = [
                'zoningCode'        => $zoningCode,
                'zoningDescription' => $zoningDescription
            ];
        }
    }

    return $normalized;
}

/**
 * Resolve a configured raw ArcGIS value to its zoning code and description.
 */
function findZoningCodedValueMapping(
    array $attributes,
    array $mappings
): ?array {
    foreach ($mappings as $field => $values) {
        if (
            !array_key_exists($field, $attributes) ||
            $attributes[$field] === null ||
            !is_array($values)
        ) {
            continue;
        }

        $rawValue = trim((string)$attributes[$field]);

        if ($rawValue !== '' && isset($values[$rawValue])) {
            return $values[$rawValue];
        }
    }

    return null;
}

function normalizeZoningFieldList(mixed $fields): array {
    if (is_string($fields)) {
        $fields = [$fields];
    }

    if (!is_array($fields)) {
        return [];
    }

    return array_values(array_filter(array_map(
        function(mixed $field): string {
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

function normalizeZoningText(mixed $value): ?string {
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $value = trim((string)$value);

    return $value !== '' ? $value : null;
}

function normalizeZoningApn(mixed $value): ?string {
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

/**
 * Convert a jurisdiction label to its safe on-disk directory name.
 */
function normalizeZoningSlug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '', $value);

    return is_string($value) ? $value : '';
}

#endregion