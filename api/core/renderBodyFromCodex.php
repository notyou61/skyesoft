<?php
// =====================================================================
//  Skyesoft™ Core Codex Body Builder v3.9  |  PHP 5.6-Safe
//  Polish: Zero-gap bullets/lists; white-space fix for wraps
// =====================================================================

#region Utilities
function _ss_human_label($k) {
    $k = preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $k);
    $k = str_replace('_',' ',$k);
    return ucwords($k);
}
#endregion

// Header 2 renderer
function _ss_h2($label) {
    return '
    <div style="margin:0;padding:0;line-height:1;">
        <div style="
            font-size:12.5pt;
            font-weight:bold;
            color:#003366;
            margin:0;
            padding:0;
        ">' . htmlspecialchars($label) . '</div>
        <div style="
            height:0;
            line-height:0;
            margin:0;
            padding:0;
            border-bottom:1pt solid #555;
            margin-top:-1.2pt;
        "></div>
    </div>';
}
// Text renderer
function _ss_render_text($node, $fallback='') {
    $txt = isset($node['text']) ? $node['text'] : $fallback;
    return '<div style="
        font-size:11pt;
        line-height:1.1;
        margin:0;
        margin-top:-1.5pt;  /* pulls paragraph closer to divider */
        padding:0;
    ">' . htmlspecialchars($txt) . '</div>';
}

// Wrapper: Anti-bloat
function _ss_wrap_content($innerHtml) {
    return '<div style="margin:0;padding:0;line-height:1.0;white-space:normal;">' . $innerHtml . '</div>';
}

#region Table renderers (unchanged)
function _ss_render_table($rows) {
    if (!is_array($rows) || empty($rows)) return _ss_wrap_content('<div><em>No table data.</em></div>');
    $headers = array_keys($rows[0]);

    $html  = '<table style="width:100%;border-collapse:collapse;font-size:10pt;margin:0;">';
    $html .= '<tr style="background-color:#003366;color:#ffffff;border:0.5pt solid #ccc;">';
    foreach ($headers as $h) {
        $html .= '<th style="text-align:left;padding:6pt 4pt;border:0.5pt solid #ccc;">'
              . htmlspecialchars(_ss_human_label($h)) . '</th>';
    }
    $html .= '</tr>';

    $alt = false;
    foreach ($rows as $r) {
        $bg = $alt ? '#f3f3f3' : '#ffffff';
        $html .= '<tr style="background-color:' . $bg . ';border:0.5pt solid #ccc;">';
        foreach ($headers as $h) {
            $val = isset($r[$h]) ? $r[$h] : '';
            if (is_array($val)) $val = implode(', ', $val);
            $html .= '<td style="padding:6pt 4pt;border:0.5pt solid #ccc;white-space:normal;">'
                  . htmlspecialchars($val) . '</td>';
        }
        $html .= '</tr>';
        $alt = !$alt;
    }
    return _ss_wrap_content($html . '</table>');
}

function _ss_render_kv_table($assoc) {
    if (!is_array($assoc) || empty($assoc)) return _ss_wrap_content('<div><em>No data.</em></div>');
    $rows = array();
    foreach ($assoc as $k=>$v)
        $rows[] = array('Key' => _ss_human_label($k), 'Value' => is_array($v) ? implode(', ', $v) : $v);
    return _ss_render_table($rows);
}
#endregion

#region Section renderers
function _ss_render_dynamic($node) {
    $desc = isset($node['description']) ? $node['description'] : '(Dynamic section)';
    return _ss_wrap_content('<div style="font-size:11pt;margin:0;line-height:1.0;"><em>' . htmlspecialchars($desc) . '</em></div>');
}

// Lists: Zero-top margin for header hug
function _ss_render_list($node) {
    $items = isset($node['items']) && is_array($node['items']) ? $node['items'] : array();
    if (!count($items)) return '';  // Skip empty (no "Notes" blank)
    $html = '<ul style="margin:0 0 0 16pt;padding:0;line-height:1.0;">';
    foreach ($items as $i)
        $html .= '<li style="margin:0 0 2pt 0;padding:0;line-height:1.0;white-space:normal;">' . htmlspecialchars(is_string($i)?$i:json_encode($i)) . '</li>';
    return _ss_wrap_content($html . '</ul>');
}

function _ss_render_table_node($node) {
    $rows = array();
    if (isset($node['items']) && is_array($node['items']))      $rows = $node['items'];
    elseif (isset($node['data']) && is_array($node['data']))    $rows = $node['data'];
    elseif (isset($node['holidays']) && is_array($node['holidays'])) $rows = $node['holidays'];
    return _ss_render_table($rows);
}

function _ss_render_relationships($node) {
    $out = array();
    if (isset($node['isA']))       $out[] = array('Field'=>'Is A','Value'=>$node['isA']);
    if (isset($node['partOf']))    $out[] = array('Field'=>'Part Of','Value'=>$node['partOf']);
    if (isset($node['governs']))   $out[] = array('Field'=>'Governs','Value'=> is_array($node['governs']) ? implode(', ', $node['governs']) : $node['governs']);
    if (isset($node['dependsOn'])) $out[] = array('Field'=>'Depends On','Value'=> is_array($node['dependsOn']) ? implode(', ', $node['dependsOn']) : $node['dependsOn']);
    if (isset($node['aliases']))   $out[] = array('Field'=>'Aliases','Value'=> is_array($node['aliases']) ? implode(', ', $node['aliases']) : $node['aliases']);
    return _ss_render_table($out);
}

function _ss_render_holiday_registry($node) {
    $html = '';
    if (isset($node['description']) && $node['description'] !== '') {
        $html = _ss_render_text(array('text' => $node['description']));  // Reuse text renderer for tight desc
    }
    if (isset($node['categories']) && is_array($node['categories'])) {
        $catRows = array();
        foreach ($node['categories'] as $name => $cfg)
            $catRows[] = array(
                'Category' => _ss_human_label($name),
                'Description' => isset($cfg['description']) ? $cfg['description'] : '',
                'Workday Impact' => isset($cfg['workdayImpact']) ? $cfg['workdayImpact'] : '',
                'Exclude From Scheduling' => isset($cfg['excludeFromScheduling']) ? ($cfg['excludeFromScheduling'] ? 'Yes' : 'No') : ''
            );
        $html .= '<div style="font-weight:bold;margin:6pt 0 0 0;line-height:1.0;">Categories</div>';  // Zero bottom
        $html .= _ss_render_table($catRows);
    }

    if (isset($node['holidays']) && is_array($node['holidays'])) {
        $hRows = array();
        foreach ($node['holidays'] as $h)
            $hRows[] = array(
                'Name' => isset($h['name']) ? $h['name'] : '',
                'Rule' => isset($h['rule']) ? $h['rule'] : '',
                'Categories' => isset($h['categories']) ? (is_array($h['categories']) ? implode(', ', $h['categories']) : $h['categories']) : ''
            );
        $html .= '<div style="font-weight:bold;margin:8pt 0 0 0;line-height:1.0;">Holidays</div>';  // Zero bottom
        $html .= _ss_render_table($hRows);
    }
    return _ss_wrap_content($html !== '' ? $html : '');
}
#endregion

#region Core
function renderBodyFromCodex($slug) {
    $codexPath = __DIR__ . '/../../assets/data/codex.json';
    if (!file_exists($codexPath)) return '<div><em>Codex file missing.</em></div>';
    $codex = json_decode(file_get_contents($codexPath), true);
    if (!isset($codex[$slug]))     return '<div><em>Module not found in Codex.</em></div>';
    $m = $codex[$slug];

    $order = isset($codex['displayOrder']) && is_array($codex['displayOrder'])
        ? $codex['displayOrder']
        : array('purpose','dayTypes','segmentsOffice','segmentsShop','holidays','exclusions','holidayRegistry','holidayFallbackRules','relationships');

    $suppress = array('title','category','type','subtypes','actions','meta','codexMeta','enrichment','icon','format','source','revision');
    $html = '<div style="line-height:1.0;margin:0;padding:0;white-space:normal;">';  // Root with wrap fix

    foreach ($order as $key) {
        if (!isset($m[$key])) continue;
        $node  = $m[$key];
        $label = _ss_human_label($key);
        $html .= _ss_h2($label);

        $fmt = isset($node['format']) ? strtolower($node['format']) : null;

        if ($key === 'relationships') { $html .= _ss_render_relationships($node); continue; }
        if ($key === 'holidayRegistry'){ $html .= _ss_render_holiday_registry($node); continue; }

        switch ($fmt) {
            case 'text':     $html .= _ss_render_text($node); break;
            case 'dynamic':  $html .= _ss_render_dynamic($node); break;
            case 'list':     $html .= _ss_render_list($node); break;
            case 'table':    $html .= _ss_render_table_node($node); break;
            case 'object':
                $data = isset($node['data']) ? $node['data'] : $node;
                $html .= _ss_render_kv_table($data);
                break;
            default:
                if (is_string($node)) {
                    $html .= _ss_wrap_content('<div style="
                        font-size:11pt;
                        margin:0;
                        line-height:1.0;
                        white-space:normal;
                        margin-top:-1.2pt; /* pulls text closer to divider */
                    ">' . htmlspecialchars($node) . '</div>');
                }
                // Break
                break;
        }
    }

    foreach ($m as $k=>$v) {
        if (in_array($k,$suppress)||in_array($k,$order)) continue;
        $html .= _ss_h2(_ss_human_label($k));
        if (is_string($v)) {
            $html .= _ss_wrap_content('<div style="font-size:11pt;margin:0;line-height:1.0;white-space:normal;">' . htmlspecialchars($v) . '</div>');
        } else {
            $html .= _ss_wrap_content('<pre style="font-size:9pt;background:#f8f8f8;padding:6pt;margin:0;line-height:1.0;white-space:pre-wrap;">'
                  . htmlspecialchars(json_encode($v, JSON_PRETTY_PRINT))
                  . '</pre>');
        }
    }
    $html .= '</div>';
    return $html;
}
#endregion