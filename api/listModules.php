<?php
// api/listModules.php

define('CODEX_PATH', __DIR__ . '/../assets/data/codex.json');

if (!file_exists(CODEX_PATH)) {
    die("Codex file not found at " . CODEX_PATH);
}

$codex = json_decode(file_get_contents(CODEX_PATH), true);
if (!$codex) {
    die("Failed to decode codex.json");
}

// Extract modules (handles both wrapped and direct structures)
$modules = isset($codex['modules']) ? $codex['modules'] : $codex;

foreach ($modules as $key => $entry) {
    $title = isset($entry['title']) ? $entry['title'] : '[no title]';
    echo $key . " => " . $title . "\n";
}
?>
