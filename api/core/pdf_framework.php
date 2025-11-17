<?php
// ======================================================================
//  FILE: pdf_framework.php
//  PURPOSE: Skyesoft™ Document Framework (Header, Body, Footer)
//  VERSION: v2.4 (Regionalized Codex Edition)
//  AUTHOR: CPAP-01 Parliamentarian Integration
//  PHP 5.6 Compatible
// ======================================================================

#region ERROR-REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);
#endregion

#region DEPENDENCIES
require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');
#endregion

// ======================================================================
//  CLASS DEFINITION
// ======================================================================

#region CLASS: SkyesoftPDF
class SkyesoftPDF extends TCPDF {

    #region PROPERTIES
    public $docTitle    = '📄 Untitled Document';
    public $docType     = 'Skyesoft™ Information Sheet';
    public $generatedAt = '';
    public $metaFooter  = '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com';
    public $codexMeta   = array();
    private $root       = '';
    #endregion

    #region CONSTRUCTOR
    public function __construct($orientation='P',$unit='mm',$format='Letter',$unicode=true,$encoding='UTF-8',$diskcache=false){
        parent::__construct($orientation,$unit,$format,$unicode,$encoding,$diskcache);
        $this->root = dirname(__DIR__,2);
        parent::setPrintFooter(false); // disable TCPDF default footer
    }
    #endregion

    #region CONTEXT-BINDER
    public function bindContext($ctx=array()){
        foreach($ctx as $k=>$v){
            if(property_exists($this,$k)) $this->$k=$v;
        }
    }
    #endregion

    #region HEADER
    public function Header() {
        $logoPath = $this->root . '/assets/images/christyLogo.png';

        // --- Layout geometry ---
        $yBase   = 8;     // top offset
        $logoW   = 36;    // logo width
        $gap     = 3;     // gap between logo and text
        $xOffset = $this->lMargin;

        // --- Christy Logo ---
        if (file_exists($logoPath)) {
            $this->Image($logoPath, $xOffset, $yBase, $logoW, 0, 'PNG');
        }

        // --- Compute vertical centering relative to logo ---
        // Estimate logo height proportionally (~1:3.5 ratio)
        $logoH   = $logoW / 3.5;
        // Center text block vertically on logo midline
        $textY   = $yBase + ($logoH / 2) - 4.0; // subtract ~5.5mm to visually center

        $textX = $xOffset + $logoW + $gap;

        // --- Clean Title ---
        $rawTitle = $this->docTitle;
        $cleanTitle = preg_replace('/^[^\p{L}\p{N}\(]+/u', '', $rawTitle);
        $cleanTitle = str_replace("\xEF\xBF\xBD", '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);

        // --- Title ---
        $this->SetFont('helvetica', 'B', 12.5);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($textX, $textY);
        $this->Cell(0, 5.5, $cleanTitle, 0, 1, 'L');

        // --- Document Type ---
        $this->SetFont('helvetica', '', 9.2);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($textX, $textY + 5.0);
        $this->Cell(0, 4.5, $this->docType, 0, 1, 'L');

        // --- Timestamp ---
        $this->SetFont('helvetica', '', 7.8);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($textX, $textY + 10.0);
        $this->Cell(0, 4, 'Generated ' . $this->generatedAt, 0, 1, 'L');

        // --- Divider ---
        $this->SetLineWidth(0.4);

        // Move divider line lower for cleaner separation
        $dividerY = $yBase + $logoH + 8; // was +6 — increase for more space
        $this->Line($this->lMargin, $dividerY, $this->getPageWidth() - $this->rMargin, $dividerY);
    }
    #endregion

    #region FOOTER
    public function Footer() {
        $this->SetY(-15.5);
        $this->SetFont('helvetica','',8);
        $this->SetTextColor(80,80,80);

        // --- Divider line ---
        $left  = $this->lMargin;
        $right = $this->getPageWidth() - $this->rMargin;
        $this->Line($left, $this->GetY(), $right, $this->GetY());

        // Increase vertical spacing below divider for symmetry
        $this->Ln(2.0); // was 2.2 — gives a balanced gap like header divider

        // --- Footer text ---
        $footerText =
            '© Christy Signs / Skyesoft, All Rights Reserved | ' .
            '3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com' .
            ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

        $printableWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $this->SetX($this->lMargin + 6);
        $this->Cell($printableWidth, 6, $footerText, 0, 0, 'C');
    }
    #endregion

}
#endregion

// ======================================================================
//  REGION: GENERATED-AT RESOLVER (SSE-AWARE)
// ======================================================================

#region FUNCTION: resolveGeneratedAtFromSSE
function resolveGeneratedAtFromSSE($root) {
    // Candidate SSE snapshot paths
    $candidates = array(
        $root . '/assets/data/sse.json',
        $root . '/assets/data/sseStream.json',
        $root . '/assets/data/sse_snapshot.json'
    );

    foreach ($candidates as $path) {
        if (!file_exists($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['timeDateArray'])) {
            continue;
        }

        $t = $data['timeDateArray'];

        // ✅ Primary: use SSE unix time + timezone when available
        if (isset($t['currentUnixTime'])) {
            $ts = (int)$t['currentUnixTime'];

            // Prefer explicit SSE timeZone, else fall back to Codex/Phoenix
            $tz = 'America/Phoenix';
            if (!empty($t['timeZone'])) {
                $tz = $t['timeZone'];
            } else {
                $codexPath = $root . '/assets/data/codex.json';
                if (file_exists($codexPath)) {
                    $codex = json_decode(file_get_contents($codexPath), true);
                    if (isset($codex['weatherData']['timeZone'])) {
                        $tz = $codex['weatherData']['timeZone'];
                    }
                }
            }

            if (function_exists('date_default_timezone_set')) {
                $oldTz = @date_default_timezone_get();
                @date_default_timezone_set($tz);
                $formatted = date('F j, Y • g:i A', $ts);
                @date_default_timezone_set($oldTz ?: 'UTC');
                return $formatted;
            }

            // If we can't change TZ safely, still format using server TZ
            return date('F j, Y • g:i A', $ts);
        }

        // ✅ Secondary: compose from SSE human fields
        if (isset($t['currentDate']) && isset($t['currentLocalTime'])) {
            return $t['currentDate'] . ' • ' . $t['currentLocalTime'];
        }
    }

    // ✅ Fallback: use Codex / Phoenix as doctrinal default
    $tz = 'America/Phoenix';
    $codexPath = $root . '/assets/data/codex.json';
    if (file_exists($codexPath)) {
        $codex = json_decode(file_get_contents($codexPath), true);
        if (isset($codex['weatherData']['timeZone'])) {
            $tz = $codex['weatherData']['timeZone'];
        }
    }

    if (function_exists('date_default_timezone_set')) {
        $oldTz = @date_default_timezone_get();
        @date_default_timezone_set($tz);
        $formatted = date('F j, Y • g:i A');
        @date_default_timezone_set($oldTz ?: 'UTC');
        return $formatted;
    }

    return date('F j, Y • g:i A');
}
#endregion

// ======================================================================
//  MAIN SCRIPT
// ======================================================================

#region SLUG-DETECTION
$slug = null;
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, 'slug=') === 0) $slug = trim(substr($arg, 5));
    }
} elseif (isset($_GET['slug'])) {
    $slug = $_GET['slug'];
}
if (!$slug) {
    echo "❌ Missing slug parameter. Example: php -f api/core/pdf_framework.php slug=documentStandards\n";
    exit;
}
#endregion

#region LOAD-CODEX
$root = dirname(__DIR__, 2);
$codexPath = $root . '/assets/data/codex.json';
if (!file_exists($codexPath)) { 
    echo "❌ Codex not found: $codexPath\n"; 
    exit; 
}

$codex = json_decode(file_get_contents($codexPath), true);
if (!isset($codex[$slug])) { 
    echo "❌ Module '$slug' not found in Codex.\n"; 
    exit; 
}

$module = $codex[$slug];

// --------------------------------------------------
//  TITLE: Clean emoji and format slug in Title Case
// --------------------------------------------------
$docTitle = isset($module['title'])
    ? trim(preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}]\s*/u', '', $module['title']))
    : trim(ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $slug)));

// --------------------------------------------------
//  DOC TYPE: Derived dynamically from Codex registry
// --------------------------------------------------
$typeKey   = isset($module['type']) ? strtolower(trim($module['type'])) : 'general';
$familyKey = isset($module['family']) ? strtolower(trim($module['family'])) : '';
$doctrine  = isset($module['doctrine']) ? strtolower(trim($module['doctrine'])) : '';

// Locate the registry block
$registryItems = array();
if (isset($codex['documentStandards']['documentTypesRegistry']['items'])) {
    $registryItems = $codex['documentStandards']['documentTypesRegistry']['items'];
}

// Default
$displayLabel = 'Document';

// Search through the registry table for a key match
foreach ($registryItems as $entry) {
    if (isset($entry['key']) && strtolower($entry['key']) === $typeKey) {
        if (isset($entry['type'])) {
            $displayLabel = $entry['type'];
            break;
        }
    }
}

// Fallback: if no match by type, try doctrine
if ($displayLabel === 'Document' && $doctrine !== '') {
    foreach ($registryItems as $entry) {
        if (isset($entry['key']) && strtolower($entry['key']) === $doctrine) {
            if (isset($entry['type'])) {
                $displayLabel = $entry['type'];
                break;
            }
        }
    }
}

// Combine into Skyesoft format
$docType = 'Skyesoft™ ' . ucfirst($displayLabel);

// --------------------------------------------------
//  FAMILY + METADATA
// --------------------------------------------------
$family = isset($module['family']) ? $module['family'] : 'general';
$codexMeta = array(
    'version'    => isset($codex['documentStandards']['documentTypesRegistry']['effectiveVersion'])
                    ? $codex['documentStandards']['documentTypesRegistry']['effectiveVersion']
                    : 'vUnknown',
    'reviewedBy' => isset($codex['documentStandards']['documentTypesRegistry']['issuedBy'])
                    ? $codex['documentStandards']['documentTypesRegistry']['issuedBy']
                    : 'Unverified'
);
#endregion

#region PDF-INITIALIZATION
$pdf = new SkyesoftPDF('P', 'mm', 'Letter', true, 'UTF-8', false);

// Core print settings
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->setFooterData(array(0, 0, 0), array(0, 0, 0));
$pdf->setFooterFont(array('helvetica', '', 8));
$pdf->SetMargins(15, 38, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true, 20);

// Metadata
$pdf->SetCreator('Skyesoft™ Codex Engine');
$pdf->SetAuthor('Skyebot™ System Layer');
$pdf->SetTitle($docTitle);

// Context bind
$pdf->bindContext(array(
    'docTitle'    => $docTitle,
    'docType'     => ucfirst($docType), // ensures “Directive” not “directive”
    'generatedAt' => resolveGeneratedAtFromSSE($root),
    'codexMeta'   => $codexMeta
));
#endregion

// ======================================================================
//  REGION: ENRICHMENT + BODY BUILDER + OUTPUT RESOLVER
// ======================================================================

#region FUNCTION: aiEnrichSection
function aiEnrichSection($module, $enrichment = 'low', $root = '') {
    if (!in_array($enrichment, array('medium', 'high'))) return '';

    $title    = isset($module['title']) ? $module['title'] : 'Unnamed Module';
    $type     = isset($module['type']) ? ucfirst($module['type']) : 'Document';
    $purpose  = isset($module['purpose']['text']) ? $module['purpose']['text'] : '';
    $category = isset($module['category']) ? $module['category'] : 'Unspecified Layer';

    $prompt = "You are the Skyesoft Codex Parliamentarian AI. "
            . "Generate a narrative summary for the {$type} titled '{$title}'. "
            . "Category: {$category}. Purpose: {$purpose}. ";

    $prompt .= ($enrichment === 'high')
        ? "Include doctrinal relationships, dependencies, and practical implications using professional, structured language suitable for Codex-class documents."
        : "Limit to one concise paragraph summarizing context and intent.";

    $apiPath = $root . '/api/askOpenAI.php';
    if (!file_exists($apiPath)) return '';

    $opts = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode(array('prompt' => $prompt)),
            'timeout' => 15
        )
    );

    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($apiPath, false, $ctx);

    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['response'])) {
            return "<div class='ai-enrichment'><h2>Doctrinal Commentary</h2><p>"
                 . htmlspecialchars(trim($data['response'])) . "</p></div>";
        }
    }
    return '';
}
#endregion

#region FUNCTION: buildBodyFromCodex
function buildBodyFromCodex($module, $codex = array(), $root = '') {
    $html = "";

    $enrichment = isset($module['enrichment'])
        ? strtolower(trim($module['enrichment']))
        : 'low';

    $html .= aiEnrichSection($module, $enrichment, $root);

    if (isset($module['purpose']['text']))
        $html .= "<h2>Purpose</h2><p>{$module['purpose']['text']}</p>";

    if (isset($module['description']))
        $html .= "<h2>Description</h2><p>{$module['description']}</p>";

    if (isset($module['format']) && $module['format'] === 'table' && isset($module['items'])) {
        $html .= "<h2>Defined Standards</h2><table border='1' cellpadding='5' cellspacing='0' width='100%'>";
        $headersPrinted = false;
        foreach ($module['items'] as $item) {
            if (!$headersPrinted) {
                $html .= "<tr style='background-color:#f0f0f0;font-weight:bold'>";
                foreach (array_keys($item) as $header)
                    $html .= "<td>" . ucfirst($header) . "</td>";
                $html .= "</tr>";
                $headersPrinted = true;
            }
            $html .= "<tr>";
            foreach ($item as $val)
                $html .= "<td>" . htmlspecialchars($val) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
    }

    if (isset($module['notes']))
        $html .= "<h2>Notes</h2><p>{$module['notes']}</p>";

    return $html ?: "<p>No doctrinal body content defined for this module.</p>";
}
#endregion

#region FUNCTION: resolveRepositoryPath
function resolveRepositoryPath($codex) {
    $candidates = array(
        @$codex['documentStandards']['containedModules']['referentialDocumentRecord']['repository']['path'],
        @$codex['documentStandards']['repository']['path'],
        '/documents/'
    );
    foreach ($candidates as $candidate)
        if (is_string($candidate) && trim($candidate) !== '')
            return '/' . ltrim(trim($candidate), '/');
    return '/documents/';
}
#endregion

#region FUNCTION: resolveDocumentOutputPath
function resolveDocumentOutputPath($root, $codex, $module, $slug, $docTitle, $displayLabel) {
    $repoPath = resolveRepositoryPath($codex);
    $saveDir  = rtrim($root . $repoPath, '/') . '/';
    if (!is_dir($saveDir)) { $old = umask(0); mkdir($saveDir, 0777, true); umask($old); }

    $type = isset($module['type']) ? strtolower($module['type']) : 'document';
    $naming = array(
        'report'       => '{entity} # {jobNumber} (WO # {workOrder}) – {title} Report.pdf',
        'survey'       => '{entity} # {jobNumber} (WO # {workOrder}) – {title} Survey.pdf',
        'audit'        => 'Skyesoft – {title} Audit.pdf',
        'directive'    => 'Skyesoft – {title} Directive.pdf',
        'sheet'        => 'Skyesoft – {title} Information Sheet.pdf',
        'default'      => 'Skyesoft – {title} {type}.pdf'
    );
    $pattern = isset($naming[$type]) ? $naming[$type] : $naming['default'];

    $replacements = array(
        '{entity}'    => isset($module['entity']) ? trim($module['entity']) : 'Skyesoft',
        '{jobNumber}' => isset($module['jobNumber']) ? trim($module['jobNumber']) : '0000',
        '{workOrder}' => isset($module['workOrder']) ? trim($module['workOrder']) : 'N/A',
        '{title}'     => preg_replace('/^[^\p{L}\p{N}\(]+/u', '', $docTitle),
        '{type}'      => ucfirst(preg_replace('/[^A-Za-z0-9 ]+/', '', $displayLabel))
    );
    $saveFile = str_replace(array_keys($replacements), array_values($replacements), $pattern);

    return array($saveDir, $saveFile);
}
#endregion

#region BODY
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10.5);
$body = buildBodyFromCodex($module, $codex, $root);
$pdf->writeHTML($body, true, false, true, false, '');
#endregion

#region OUTPUT
list($saveDir, $saveFile) = resolveDocumentOutputPath($root, $codex, $module, $slug, $docTitle, $displayLabel);
$savePath = $saveDir . $saveFile;
$pdf->Output($savePath, 'F');

echo "📂 Document directory: $saveDir\n";
echo "✅ Saved as: $saveFile\n";
echo "🪶 Generated for module: $slug ({$docType})\n";
#endregion