<?php
declare(strict_types=1);

/**
 * ======================================================================
 * Skyesoft — Jurisdiction Resolver
 * Version: 1.4.0
 * 
 * Updates (RWU):
 *   • Robust multi-path registry loading (matches your actual file location)
 *   • Better logging for loaded registry
 *   • Full support for your authoritative registry structure
 * ======================================================================
 */

function resolveJurisdiction(?string $jurisdictionName): array
{
    $result = [
        'found'            => false,
        'jurisdictionKey'  => null,
        'label'            => null,
        'jurisdictionType' => null
    ];

    $jurisdictionName = trim((string)$jurisdictionName);
    if ($jurisdictionName === '') {
        return $result;
    }

    $searchValue = strtoupper($jurisdictionName);

    // =====================================================
    // SPECIAL CASES
    // =====================================================
    if (in_array($searchValue, ['NO CITY/TOWN', 'UNINCORPORATED', 'UNINCORPORATED AREA'], true)) {
        return [
            'found'            => true,
            'jurisdictionKey'  => 'maricopaCounty',
            'label'            => 'Maricopa County',
            'jurisdictionType' => 'County'
        ];
    }

    // =====================================================
    // LOAD REGISTRY (Robust Multi-Path)
    // =====================================================
    $possiblePaths = [
        dirname(__DIR__) . '/config/jurisdictionRegistry.json',
        __DIR__ . '/../data/authoritative/jurisdictionRegistry.json',
        __DIR__ . '/../../data/authoritative/jurisdictionRegistry.json',
        dirname(dirname(__DIR__)) . '/data/authoritative/jurisdictionRegistry.json',
        '/home/notyou64/public_html/skyesoft/data/authoritative/jurisdictionRegistry.json'
    ];

    $registryFile = null;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $registryFile = $path;
            break;
        }
    }

    if (!$registryFile) {
        error_log('[RESOLVE-JURISDICTION] Registry not found in any expected path. Last tried: ' . $possiblePaths[0]);
        return $result;
    }

    $registryContent = file_get_contents($registryFile);
    $registry = json_decode($registryContent, true);

    error_log('[RESOLVE-JURISDICTION] ✅ Registry loaded successfully from: ' . $registryFile);

    if (!is_array($registry) || empty($registry)) {
        error_log('[RESOLVE-JURISDICTION] Invalid registry. Preview: ' . substr($registryContent, 0, 500));
        return $result;
    }

    // =====================================================
    // SEARCH REGISTRY
    // =====================================================
    foreach ($registry as $jurisdictionKey => $record) {
        if (!is_array($record)) {
            continue;
        }

        // Flexible type key (supports your registry format)
        $type = $record['jurisdictionType'] 
             ?? $record['jurisdictiontype'] 
             ?? $record['jurisdiction_type'] 
             ?? null;

        // Label match
        $label = strtoupper(trim($record['label'] ?? ''));
        if ($label === $searchValue) {
            if (empty($type)) {
                $type = 'City';
                error_log("[RESOLVE-JURISDICTION] Missing type for {$jurisdictionKey} → default City");
            }
            return [
                'found'            => true,
                'jurisdictionKey'  => $jurisdictionKey,
                'label'            => $record['label'] ?? $jurisdictionName,
                'jurisdictionType' => $type
            ];
        }

        // Alias match
        foreach (($record['aliases'] ?? []) as $alias) {
            if (strtoupper(trim($alias)) === $searchValue) {
                if (empty($type)) {
                    $type = 'City';
                    error_log("[RESOLVE-JURISDICTION] Missing type for alias {$jurisdictionKey} → default City");
                }
                return [
                    'found'            => true,
                    'jurisdictionKey'  => $jurisdictionKey,
                    'label'            => $record['label'] ?? $jurisdictionName,
                    'jurisdictionType' => $type
                ];
            }
        }
    }

    // =====================================================
    // FALLBACK
    // =====================================================
    return [
        'found'            => false,
        'jurisdictionKey'  => null,
        'label'            => ucwords(strtolower($jurisdictionName)),
        'jurisdictionType' => 'Unknown'
    ];
}