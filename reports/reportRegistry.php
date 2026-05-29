<?php

function getReportHandler(string $reportType): ?array
{
    $registry = [
        'contact_proposal' => [
            'file'        => __DIR__ . '/contactProposalReport.php',
            'class'       => 'ContactProposalReport', // future-proofing
            'description' => 'Proposed Contact Report'
        ],
        // Future reports:
        // 'zoning'           => [...],
        // 'permit_status'    => [...],
        // 'sign_ordinance'   => [...],
    ];

    return $registry[$reportType] ?? null;
}