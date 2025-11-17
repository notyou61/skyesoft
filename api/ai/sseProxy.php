<?php
// 📘 File: api/ai/sSEProxy.php
// Purpose: Provide cached access to the dynamic SSE stream for PolicyEngine
// Compatible with PHP 5.6+

function fetchSSESnapshot() {
    $cachePath = __DIR__ . '/../../assets/data/dynamicDataCache.json';
    $fallbackPath = __DIR__ . '/../../assets/data/fallbackDynamicData.json';

    $data = array();
    $pathUsed = '';

    // Try primary cache first !
    if (file_exists($cachePath)) {
        $raw = @file_get_contents($cachePath);
        $data = json_decode($raw, true);
        $pathUsed = 'cache';
    }

    // If invalid or empty, fall back to backup snapshot
    if (!is_array($data) || empty($data)) {
        if (file_exists($fallbackPath)) {
            $raw = @file_get_contents($fallbackPath);
            $data = json_decode($raw, true);
            $pathUsed = 'fallback';
        } else {
            $data = array('error' => 'No SSE cache available');
            $pathUsed = 'none';
        }
    }

    // Add metadata for debugging
    $data['source'] = 'fetchSSESnapshot';
    $data['pathUsed'] = $pathUsed;
    $data['timestamp'] = date('Y-m-d H:i:s');

    error_log("📡 fetchSSESnapshot(): Using {$pathUsed} data source.");
    return $data;
}