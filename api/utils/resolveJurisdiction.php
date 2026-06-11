<?php
declare(strict_types=1);

/**
 * ======================================================================
 * Skyesoft — Jurisdiction Resolver
 * Version: 1.3.0
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

    // Special cases
    if (in_array($searchValue, ['NO CITY/TOWN', 'UNINCORPORATED', 'UNINCORPORATED AREA'], true)) {
        return [
            'found'            => true,
            'jurisdictionKey'  => 'maricopaCounty',
            'label'            => 'Maricopa County',
            'jurisdictionType' => 'County'
        ];
    }

    // Load registry
    $registryFile = dirname(__DIR__) . '/config/jurisdictionRegistry.json';
    if (!file_exists($registryFile)) {
        error_log('[RESOLVE-JURISDICTION] Registry not found: ' . $registryFile);
        return $result;
    }

    $registryContent = file_get_contents($registryFile);
    $registry = json_decode($registryContent, true);

    if (!is_array($registry) || empty($registry)) {
        error_log('[RESOLVE-JURISDICTION] Invalid registry. Preview: ' . substr($registryContent, 0, 500));
        return $result;
    }

    // Search
    foreach ($registry as $jurisdictionKey => $record) {
        if (!is_array($record)) continue;

        // Flexible type key (handles casing issues)
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

    // Fallback
    return [
        'found'            => false,
        'jurisdictionKey'  => null,
        'label'            => ucwords(strtolower($jurisdictionName)),
        'jurisdictionType' => 'Unknown'
    ];
}