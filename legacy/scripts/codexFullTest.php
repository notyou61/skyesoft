<?php
// =====================================================
// Codex Full Test Harness (Fixed for codex.json schema)
// =====================================================

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load TCPDF
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

// Load codex data
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codex = json_decode(file_get_contents($codexPath), true);
if (!$codex) {
    die("❌ Could not load codex.json at $codexPath\n");
}

// Create PDF
$pdf = new TCPDF();
$pdf->SetCreator('Skyesoft');
$pdf->SetAuthor('Skyesoft');
$pdf->SetTitle('Codex Full Test Report');
$pdf->SetMargins(15, 25, 15);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// -----------------------------------------------------
// Render helper
// -----------------------------------------------------
function renderModule($pdf, $name, $module) {
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, strtoupper($name), 0, 1, 'C');
    $pdf->Ln(4);

    if (!is_array($module)) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, print_r($module, true));
        return;
    }

    foreach ($module as $sectionName => $section) {
        if (!is_array($section)) continue;

        // Section title
        if (isset($section['icon'])) {
            $title = ucfirst($sectionName);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, $title, 0, 1, 'L');
        }

        // Section content
        if (isset($section['text'])) {
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 6, $section['text']);
            $pdf->Ln(2);
        }

        if (isset($section['items']) && is_array($section['items'])) {
            $pdf->SetFont('helvetica', '', 10);
            foreach ($section['items'] as $item) {
                if (is_array($item)) {
                    $pdf->MultiCell(0, 6, "- " . implode(" | ", $item));
                } else {
                    $pdf->MultiCell(0, 6, "- $item");
                }
            }
            $pdf->Ln(2);
        }
    }
}

// -----------------------------------------------------
// Iterate all codex objects
// -----------------------------------------------------

// Handle codexModules
if (isset($codex['codexModules'])) {
    foreach ($codex['codexModules'] as $moduleName => $moduleData) {
        $pdf->AddPage();
        renderModule($pdf, $moduleName, $moduleData);
    }
}

// Handle other root objects
foreach ($codex as $objectName => $objectData) {
    if ($objectName === 'codexModules') continue;
    $pdf->AddPage();
    renderModule($pdf, $objectName, $objectData);
}

// Save output
$filename = __DIR__ . '/../docs/reports/Codex_Full_Test.pdf';
$pdf->Output($filename, 'F');

echo "✅ Codex Full Test PDF created: $filename\n";
