<?php
//  Skyesoft™ Core Codex Body Builder v1.0  |  PHP 5.6-Safe

function renderBodyFromCodex($slug) {
    $codexPath = __DIR__ . '/../../assets/data/codex.json';
    if (!file_exists($codexPath)) {
        return '<p><em>Codex file missing.</em></p>';
    }
    $codex = json_decode(file_get_contents($codexPath), true);
    if (!isset($codex[$slug])) {
        return '<p><em>Module not found in Codex.</em></p>';
    }
    $module = $codex[$slug];

    $html = '';
    foreach ($module as $key => $val) {
        if (in_array($key, array('title','meta','codexMeta'))) continue;
        $label = ucwords(str_replace('_',' ',$key));
        $html .= '<h2 style="font-size:12.5pt;font-weight:bold;color:#003366;margin-top:10pt;">'
               . $label . '</h2>'
               . '<div style="height:0.8pt;background-color:#555;margin-bottom:6pt;"></div>';
        if (is_string($val)) {
            $html .= '<p>' . htmlspecialchars($val) . '</p>';
        } elseif (is_array($val)) {
            $html .= '<pre style="font-size:9pt;background:#f8f8f8;padding:6pt;">'
                   . htmlspecialchars(json_encode($val, JSON_PRETTY_PRINT))
                   . '</pre>';
        }
    }
    return $html;
}