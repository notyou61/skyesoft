<?php
declare(strict_types=1);

/**
 * ======================================================================
 * Skyesoft — Jurisdiction Resolver
 * Version: 1.3.0
 *
 * Improvements:
 *   • More flexible key lookup (jurisdictionType, jurisdictiontype, jurisdiction_type)
 *   • Better logging for debugging
 *   • Early registry structure validation
 *   • Stricter fallback handling
 * ======================================================================
 */

function resolveJurisdiction(?string $jurisdictionName): array
{
    // =====================================================
    // DEFAULT RESPONSE
    // =====================================================

    $result = [
        'found'            => false,
        'jurisdictionKey'  => null,
        'label'            => null,
        'jurisdictionType' => null
    ];

    // =====================================================
    // INPUT VALIDATION
    // =====================================================

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
    // LOAD REGISTRY
    // =====================================================

    $registryFile = dirname(__DIR__) . '/config/jurisdictionRegistry.json';

    if (!file_exists($registryFile)) {
        error_log('[RESOLVE-JURISDICTION] Registry not found: ' . $registryFile);
        return $result;
    }

    $registryContent = file_get_contents($registryFile);
    $registry = json_decode($registryContent, true);

    if (!is_array($registry) || empty($registry)) {
        error_log('[RESOLVE-JURISDICTION] Invalid or empty registry. Content preview: ' . substr($registryContent, 0, 300));
        return $result;
    }

    // =====================================================
    // SEARCH REGISTRY
    // =====================================================

    foreach ($registry as $jurisdictionKey => $record) {
        if (!is_array($record)) {
            continue;
        }

        // Flexible type key lookup
        $type = $record['jurisdictionType'] 
            ?? $record['jurisdictiontype'] 
            ?? $record['jurisdiction_type'] 
            ?? null;

        // -------------------------------------------------
        // LABEL MATCH
        // -------------------------------------------------
        $label = strtoupper(trim($record['label'] ?? ''));
        if ($label === $searchValue) {
            if (empty($type)) {
                $type = 'City';
                error_log("[RESOLVE-JURISDICTION] Missing jurisdictionType for {$jurisdictionKey} → defaulted to City");
            }

            return [
                'found'            => true,
                'jurisdictionKey'  => $jurisdictionKey,
                'label'            => $record['label'] ?? $jurisdictionName,
                'jurisdictionType' => $type
            ];
        }

        // -------------------------------------------------
        // ALIAS MATCH
        // -------------------------------------------------
        foreach (($record['aliases'] ?? []) as $alias) {
            if (strtoupper(trim($alias)) === $searchValue) {
                if (empty($type)) {
                    $type = 'City';
                    error_log("[RESOLVE-JURISDICTION] Missing jurisdictionType for alias match on {$jurisdictionKey} → defaulted to City");
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