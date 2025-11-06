<?php
/**
 * ============================================================
 *  FILE: renderDocumentFromCodex.php
 *  PURPOSE: Generate any Skyesoft™ Codex document dynamically
 *  ARCHITECTURE: Codex-driven • HTML-first • TCPDF output
 *  PHP VERSION: 5.6-compatible
 *  ============================================================
 */

/* ============================================================
   REGION: DEPENDENCIES & EXTENDED TCPDF CLASS
   ============================================================ */

require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');

class SkyesoftPDF extends TCPDF {

    public $docTitle     = '';
    public $docType      = 'Skyesoft™ Document';
    public $generatedAt  = '';

    // ----- Header -----
    public function Header() {

        $logo        = __DIR__ . '/../../assets/images/christyLogo.png';
        $iconMapPath = __DIR__ . '/../../assets/data/iconMap.json';
        $iconBase    = __DIR__ . '/../../assets/images/icons/';

        $this->SetY(10);

        // Left logo
        if (file_exists($logo)) {
            $this->Image($logo, 12, 10, 40, 0, 'PNG');
        }

        // Extract emoji + readable title
        $emoji = null;
        $cleanTitle = $this->docTitle;
        if (preg_match('/^([\p{So}\p{Sk}\x{FE0F}\x{1F300}-\x{1FAFF}]+)\s*(.*)$/u', $this->docTitle, $m)) {
            $emoji      = trim($m[1]);
            $cleanTitle = trim($m[2]);
        }
        if (preg_match('/^[a-z]+([A-Z][a-z]+)/', $cleanTitle)) {
            $cleanTitle = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $cleanTitle));
        }

        // Match emoji to iconMap entry
        $iconFile = null;
        if ($emoji && file_exists($iconMapPath)) {
            $map = json_decode(file_get_contents($iconMapPath), true);
            foreach ($map as $node) {
                if (isset($node['icon']) && $node['icon'] === $emoji) {
                    $candidate = $iconBase . $node['file'];
                    if (file_exists($candidate)) {
                        $iconFile = $candidate;
                        break;
                    }
                }
            }
        }

        // Title and icon
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        if ($iconFile) {
            $this->Image($iconFile, 60, 11.5, 8, 8, '', '', '', false, 300);
            $this->SetXY(70, 12);
        } else {
            $this->SetXY(60, 12);
        }
        $this->Cell(0, 7, $cleanTitle, 0, 1, 'L');

        // Sub-label
        $this->SetFont('helvetica', '', 9.5);
        $this->SetTextColor(90, 90, 90);
        $this->SetXY(60, 18);
        $this->Cell(0, 6, $this->docType, 0, 1, 'L');

        // Timestamp
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->SetXY(60, 23);
        $this->Cell(0, 5,
            'Generated ' . date('F j, Y • g:i A', strtotime($this->generatedAt)),
            0, 1, 'L');

        // Divider
        $this->SetLineWidth(0.4);
        $this->Line(10, 30, 205, 30);
        $this->Ln(2);
    }

    // ----- Footer -----
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Line(10, $this->GetY(), 205, $this->GetY());
        $this->Ln(2);
        $footerText =
            '© Christy Signs / Skyesoft, All Rights Reserved | ' .
            '3145 N 33rd Ave, Phoenix AZ 85017 | ' .
            '(602) 242-4488 | christysigns.com | ' .
            'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();
        $this->Cell(0, 6, $footerText, 0, 0, 'C');
    }
}

/* ============================================================
   REGION: INITIALIZATION & INPUT VALIDATION
   ============================================================ */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') {
    die('❌ Missing "slug" parameter. Example: ?slug=timeIntervalStandards');
}

function load_json_file($path) {
    if (!file_exists($path)) return null;
    $data = file_get_contents($path);
    return json_decode($data, true);
}

$codexPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/data/codex.json';
$ssePath   = 'http://' . $_SERVER['HTTP_HOST'] . '/api/getDynamicData.php';

/* ============================================================
   REGION: LOAD CODEX & SSE DATA
   ============================================================ */

$codex = load_json_file($codexPath);
if (!$codex) die('❌ Unable to load Codex data.');

$module = isset($codex[$slug]) ? $codex[$slug] : null;
if (!$module) die('❌ Codex object not found for slug: ' . htmlspecialchars($slug));

$title       = isset($module['title']) ? $module['title'] : ucfirst($slug);
$purpose     = isset($module['purpose']['text']) ? $module['purpose']['text'] : '';
$enrichment  = isset($module['enrichment']) ? $module['enrichment'] : 'light';
$sections    = $module;

$sseData = @file_get_contents($ssePath);
$sseTime = '';
if ($sseData) {
    $json = @json_decode($sseData, true);
    if (isset($json['timeDateArray']['localTime'])) {
        $sseTime = $json['timeDateArray']['localTime'];
    }
}
if ($sseTime === '') $sseTime = date('F j, Y • g:i A');

/* ============================================================
   REGION: PDF SETUP
   ============================================================ */

$pdf = new SkyesoftPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pdf->docTitle    = $title;
$pdf->docType     = 'Skyesoft™ Document';
$pdf->generatedAt = $sseTime;
$pdf->SetCreator('Skyesoft™ Codex Renderer');
$pdf->SetAuthor('Christy Signs / Skyesoft');
$pdf->SetTitle($title);
$pdf->SetMargins(18, 35, 18);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10.5);

/* ============================================================
   REGION: BODY RENDERING
   ============================================================ */

function renderSection($header, $content, $format = 'text') {
    $out  = '<h4 style="margin-top:10px;margin-bottom:4px;">' . htmlspecialchars($header) . '</h4>';
    $out .= '<hr style="border:0;border-top:1px solid #000;margin-top:2px;margin-bottom:6px;">';
    if ($format === 'list' && is_array($content)) {
        $out .= '<ul style="margin:0 0 8px 18px;">';
        foreach ($content as $item) $out .= '<li>' . htmlspecialchars($item) . '</li>';
        $out .= '</ul>';
    } elseif ($format === 'table' && is_array($content)) {
        $out .= '<table border="1" cellpadding="4" cellspacing="0" width="100%" style="font-size:9px;margin-bottom:8px;">';
        $keys = array_keys($content[0]);
        $out .= '<tr style="background:#f0f0f0;">';
        foreach ($keys as $k) $out .= '<th>' . htmlspecialchars($k) . '</th>';
        $out .= '</tr>';
        foreach ($content as $row) {
            $out .= '<tr>';
            foreach ($row as $cell) $out .= '<td>' . htmlspecialchars($cell) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</table>';
    } else {
        $out .= '<p style="margin-bottom:8px;">' . nl2br(htmlspecialchars($content)) . '</p>';
    }
    return $out;
}

$html = '';
if ($purpose) $html .= renderSection('Purpose', $purpose, 'text');

$skipKeys = array('title','purpose','category','enrichment','tags','version','updated');
foreach ($sections as $key => $val) {
    if (in_array($key, $skipKeys)) continue;
    if (!is_array($val)) continue;

    $header = ucwords(str_replace('_',' ',$key));
    if (isset($val['format'])) {
        $fmt = $val['format'];
        if ($fmt === 'list' && isset($val['items'])) {
            $html .= renderSection($header, $val['items'], 'list');
        } elseif ($fmt === 'table' && isset($val['items'])) {
            $html .= renderSection($header, $val['items'], 'table');
        } elseif ($fmt === 'text' && isset($val['text'])) {
            $html .= renderSection($header, $val['text'], 'text');
        }
    }
}

/* ============================================================
   REGION: OUTPUT
   ============================================================ */

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output(str_replace(' ', '_', $title) . '.pdf', 'I');
exit;