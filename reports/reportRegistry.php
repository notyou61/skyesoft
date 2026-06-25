<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — reportRegistry.php
//  Report Type Registry
//  Version: 1.2.0
//  Last Updated: 2026-06-20
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
        ],

        // NEW: Location / Parcel Review Report
        'location_review' => [
            'file'        => __DIR__ . '/locationReviewReport.php',   // We'll create this next
            'generator'   => 'generateLocationReviewReport',
            'description' => 'Location Review Report'
        ],

        'property' => [
            'file'        => __DIR__ . '/propertyReviewReport.php',
            'generator'   => 'generatePropertyReviewReport',
            'description' => 'Property Review Report'
        ]
    ];

    return $registry[$normalizedType] ?? null;
}

#endregion