<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — reportRegistry.php
//  Report Type Registry
//  Version: 1.1.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Report Registry

/**
 * Returns the handler configuration for a given report type.
 * Made case-insensitive for robustness.
 */
function getReportHandler(string $reportType): ?array
{
    // Normalize input (remove whitespace and convert to lowercase)
    $normalizedType = strtolower(trim($reportType));

    $registry = [
        'contact_proposal' => [
            'file'        => __DIR__ . '/contactProposalReport.php',
            'generator'   => 'generateContactProposalReport',
            'description' => 'Proposed Contact Report'
        ]
    ];

    return $registry[$normalizedType] ?? null;
}

#endregion