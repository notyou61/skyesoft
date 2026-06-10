<?php
declare(strict_types=1);

/**
 * ======================================================================
 * Skyesoft — Jurisdiction Resolver
 * Version: 1.1.0
 *
 * Purpose:
 *   Resolve a jurisdiction name using the Skyesoft
 *   Jurisdiction Registry.
 *
 * Returns:
 *   label
 *   jurisdictionType
 *   jurisdictionKey
 *   found
 *
 * Special Handling:
 *   • NO CITY/TOWN → Maricopa County
 *   • Unincorporated County Areas
 *
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

    if (
        in_array(
            $searchValue,
            [
                'NO CITY/TOWN',
                'UNINCORPORATED',
                'UNINCORPORATED AREA'
            ],
            true
        )
    ) {

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

    $registryFile =
        dirname(__DIR__) .
        '/config/jurisdictionRegistry.json';

    if (!file_exists($registryFile)) {

        error_log(
            '[RESOLVE-JURISDICTION] Registry not found: ' .
            $registryFile
        );

        return $result;
    }

    $registry =
        json_decode(
            file_get_contents($registryFile),
            true
        );

    if (!is_array($registry)) {

        error_log(
            '[RESOLVE-JURISDICTION] Invalid registry.'
        );

        return $result;
    }

    // =====================================================
    // SEARCH REGISTRY
    // =====================================================

    foreach ($registry as $jurisdictionKey => $record) {

        // -------------------------------------------------
        // LABEL MATCH
        // -------------------------------------------------

        $label =
            strtoupper(
                trim(
                    $record['label'] ?? ''
                )
            );

        if ($label === $searchValue) {

            return [
                'found'            => true,
                'jurisdictionKey'  => $jurisdictionKey,
                'label'            => $record['label'] ?? null,
                'jurisdictionType' => $record['jurisdictionType'] ?? null
            ];
        }

        // -------------------------------------------------
        // ALIAS MATCH
        // -------------------------------------------------

        foreach (($record['aliases'] ?? []) as $alias) {

            if (
                strtoupper(trim($alias))
                ===
                $searchValue
            ) {

                return [
                    'found'            => true,
                    'jurisdictionKey'  => $jurisdictionKey,
                    'label'            => $record['label'] ?? null,
                    'jurisdictionType' => $record['jurisdictionType'] ?? null
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
        'label'            => ucwords(
            strtolower(
                $jurisdictionName
            )
        ),
        'jurisdictionType' => null
    ];
}