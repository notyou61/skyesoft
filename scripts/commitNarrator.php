<?php
declare(strict_types=1);

// Clean input
$diff = $argv[1] ?? '';

if (trim($diff) === '') {
    echo "Chore: no effective changes detected";
    exit;
}

// Heuristic classification
$type = 'Update';
if (str_contains($diff, 'deploy.ps1')) {
    $type = 'Infra';
} elseif (str_contains($diff, 'repositoryInventory')) {
    $type = 'Governance';
} elseif (str_contains($diff, 'tasks.json')) {
    $type = 'Tooling';
}

// Summarize touched areas
$lines = explode("\n", trim($diff));
$summary = [];

foreach ($lines as $line) {
    if (preg_match('/^\s*(.+)\s+\|\s+/', $line, $m)) {
        $summary[] = $m[1];
    }
}

$summaryText = implode(', ', array_slice($summary, 0, 4));

echo "{$type}: update {$summaryText}";
