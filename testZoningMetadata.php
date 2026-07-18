<?php
declare(strict_types=1);

/**
 * Skyesoft — testZoningMetadata.php
 * Jurisdiction Zoning Configuration Validation Harness
 *
 * File Version:     1.0.0
 * Schema Version:   1.0.0
 * Last Updated:     2026-07-18
 */

#region SECTION 00 — Bootstrap
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

require_once __DIR__ . '/api/utils/resolveZoning.php';
#endregion

#region SECTION 01 — Test Selection
$jurisdictionsRoot = __DIR__ . '/data/authoritative/jurisdictions';
$requestedJurisdiction = trim((string)(
    $_GET['jurisdiction']
    ?? $_GET['slug']
    ?? ''
));

$normalizeIdentifier = static function ($value): string {
    return preg_replace(
        '/[^a-z0-9]/',
        '',
        strtolower(trim((string)$value))
    ) ?? '';
};

$configPaths = glob($jurisdictionsRoot . '/*/zoning.json') ?: [];
sort($configPaths, SORT_STRING);

if ($requestedJurisdiction !== '') {
    $requestedNormalized = $normalizeIdentifier($requestedJurisdiction);

    $configPaths = array_values(array_filter(
        $configPaths,
        static function (string $configPath) use (
            $requestedNormalized,
            $normalizeIdentifier
        ): bool {
            $slug = basename(dirname($configPath));
            $rawConfig = file_get_contents($configPath);
            $config = json_decode((string)$rawConfig, true);
            $label = is_array($config)
                ? ($config['jurisdiction']['label'] ?? '')
                : '';

            return in_array(
                $requestedNormalized,
                [
                    $normalizeIdentifier($slug),
                    $normalizeIdentifier($label)
                ],
                true
            );
        }
    ));
}
#endregion

#region SECTION 02 — Configuration Tests
$tests = [];
$passedCount = 0;
$failedCount = 0;
$warningCount = 0;

foreach ($configPaths as $configPath) {
    $relativePath = str_replace(__DIR__ . '/', '', $configPath);
    $rawConfig = file_get_contents($configPath);
    $config = json_decode((string)$rawConfig, true);

    $test = [
        'jurisdiction' => basename(dirname($configPath)),
        'configPath'   => $relativePath,
        'passed'       => false,
        'errors'       => [],
        'warnings'     => [],
        'metadata'     => [],
        'checks'       => [],
        'result'       => null
    ];

    if (!is_array($config)) {
        $test['errors'][] = 'The zoning configuration is not valid JSON.';
        $tests[] = $test;
        $failedCount++;
        continue;
    }

    $schemaName = trim((string)($config['schema']['name'] ?? ''));
    $jurisdictionLabel = trim((string)(
        $config['jurisdiction']['label']
        ?? ''
    ));
    $serviceStatus = trim((string)(
        $config['service']['status']
        ?? ''
    ));
    $metadata = $config['metadata'] ?? [];
    $testCoordinate = is_array($metadata)
        ? ($metadata['testCoordinate'] ?? [])
        : [];

    $latitude = $testCoordinate['latitude'] ?? null;
    $longitude = $testCoordinate['longitude'] ?? null;
    $expectedZoningCode = $testCoordinate['expectedZoningCode'] ?? null;

    $test['jurisdiction'] = $jurisdictionLabel !== ''
        ? $jurisdictionLabel
        : $test['jurisdiction'];

    $test['metadata'] = [
        'verifiedDate'  => $metadata['verifiedDate'] ?? null,
        'verifiedBy'    => $metadata['verifiedBy'] ?? null,
        'testAddress'   => $metadata['testAddress'] ?? null,
        'testCoordinate' => $testCoordinate
    ];

    if ($schemaName !== 'SKYESOFT_JURISDICTION_ZONING_SOURCE') {
        $test['errors'][] = 'Unexpected or missing zoning schema name.';
    }

    if ($jurisdictionLabel === '') {
        $test['errors'][] = 'Missing jurisdiction.label.';
    }

    if ($serviceStatus !== 'configured') {
        $test['errors'][] = 'The zoning service is not configured.';
    }

    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        $test['errors'][] = 'Missing or invalid metadata test coordinate.';
    }

    if ($expectedZoningCode === null || trim((string)$expectedZoningCode) === '') {
        $test['errors'][] = 'Missing expectedZoningCode in test metadata.';
    }

    if (!empty($test['errors'])) {
        $tests[] = $test;
        $failedCount++;
        continue;
    }

    try {
        $result = resolveZoning(
            $jurisdictionLabel,
            (float)$latitude,
            (float)$longitude
        );
    } catch (Throwable $exception) {
        $result = [
            'success' => false,
            'status'  => 'exception',
            'reason'  => 'resolver_exception',
            'message' => $exception->getMessage()
        ];
    }

    if (!is_array($result)) {
        $result = [
            'success' => false,
            'status'  => 'invalid_response',
            'reason'  => 'resolver_contract_violation',
            'message' => 'resolveZoning() did not return an array.'
        ];
    }

    $test['result'] = $result;

    $resolverPassed = (
        ($result['success'] ?? false) === true &&
        ($result['status'] ?? null) === 'resolved'
    );

    $test['checks']['resolverStatus'] = [
        'expected' => 'resolved',
        'actual'   => $result['status'] ?? null,
        'passed'   => $resolverPassed
    ];

    foreach ($testCoordinate as $metadataKey => $expectedValue) {
        if (strpos((string)$metadataKey, 'expected') !== 0) {
            continue;
        }

        $resultKey = lcfirst(substr((string)$metadataKey, 8));

        if (!array_key_exists($resultKey, $result)) {
            $test['checks'][$resultKey] = [
                'expected'  => $expectedValue,
                'actual'    => null,
                'evaluated' => false,
                'passed'    => null
            ];
            $test['warnings'][] =
                "Resolver output does not expose {$resultKey}; expectation not evaluated.";
            continue;
        }

        $actualValue = $result[$resultKey];
        $valuesMatch = trim((string)$actualValue) === trim((string)$expectedValue);

        $test['checks'][$resultKey] = [
            'expected'  => $expectedValue,
            'actual'    => $actualValue,
            'evaluated' => true,
            'passed'    => $valuesMatch
        ];
    }

    $evaluatedChecksPassed = true;

    foreach ($test['checks'] as $check) {
        if (($check['passed'] ?? null) === false) {
            $evaluatedChecksPassed = false;
            break;
        }
    }

    $test['passed'] = (
        empty($test['errors']) &&
        $resolverPassed &&
        $evaluatedChecksPassed
    );

    $warningCount += count($test['warnings']);

    if ($test['passed']) {
        $passedCount++;
    } else {
        $failedCount++;
    }

    $tests[] = $test;
}
#endregion

#region SECTION 03 — Response
$response = [
    'success' => ($failedCount === 0 && count($tests) > 0),
    'status'  => count($tests) === 0
        ? 'no_tests_found'
        : ($failedCount === 0 ? 'passed' : 'failed'),
    'selection' => [
        'requestedJurisdiction' => $requestedJurisdiction !== ''
            ? $requestedJurisdiction
            : null,
        'mode' => $requestedJurisdiction !== ''
            ? 'single'
            : 'all'
    ],
    'summary' => [
        'tests'    => count($tests),
        'passed'   => $passedCount,
        'failed'   => $failedCount,
        'warnings' => $warningCount
    ],
    'tests' => $tests
];

echo json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
#endregion
