<?php

require_once __DIR__ . "/sentinelLoader.php"; // wherever loadUnresolvedStructuralViolations lives

$violations = loadUnresolvedStructuralViolations();

echo "<h2>Structural Violations</h2>";

if (!$violations) {
    echo "<p>No structural deviations detected.</p>";
    exit;
}

if (!empty($violations["merkleIntegrity"])) {
    echo "<p><strong>Merkle Integrity Deviation Detected</strong></p>";
}

if (!empty($violations["repositoryInventory"])) {

    echo "<h3>Repository Inventory Issues:</h3>";
    echo "<ul>";

    foreach ($violations["repositoryInventory"] as $item) {
        echo "<li>{$item}</li>";
    }

    echo "</ul>";
}

echo "<p>No automatic actions performed.</p>";