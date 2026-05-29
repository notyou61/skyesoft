<?php
function getReportHandler(string $reportType): ?array
{
    $registry = [
        'contact_proposal' => [
            'file'        => __DIR__ . '/contactProposalReport.php',
            'generator'   => 'generateContactProposalReport',
            'description' => 'Proposed Contact Report'
        ]
    ];

    return $registry[$reportType] ?? null;
}