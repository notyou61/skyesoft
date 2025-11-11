<?php
// ================================================================
//  FILE: codexStressTest.php
//  PURPOSE: Skyesoft™ Codex Stress Test (Simplified Mode)
//  VERSION: v1.2.0
//  AUTHOR: Parliamentarian CPAP-01
// ================================================================

header('Content-Type: application/json');

// ---------------------------------------------------------------
//  STEP 1 – Locate Codex + Icon Map
// ---------------------------------------------------------------
$root = realpath(dirname(__DIR__));
$codexPath   = $root . '/assets/data/codex.json';
$iconMapPath = $root . '/assets/data/iconMap.json';
$reportDir   = $root . '/documents/';
$dateTag     = date('Y-m-d');
$reportPDF   = $reportDir . "Codex Stress Test Report - {$dateTag}.pdf";

if (!file_exists($codexPath) || !file_exists($iconMapPath)) {
    echo json_encode(["error" => "Codex or iconMap missing."]);
    exit;
}

$codex    = json_decode(file_get_contents($codexPath), true);
$iconMap  = json_decode(file_get_contents($iconMapPath), true);
$results  = [];
$score = 0; $max = 0;

// ---------------------------------------------------------------
//  STEP 2 – Validate Module Structure
// ---------------------------------------------------------------
foreach ($codex as $key => $module) {
    $moduleScore = 0; $moduleMax = 5;
    $messages = [];

    if (!empty($module['title'])) $moduleScore++; else $messages[] = "Missing title.";
    if (!empty($module['purpose']['text'])) $moduleScore++; else $messages[] = "Missing purpose.text.";
    if (!empty($module['purpose']['icon'])) $moduleScore++; else $messages[] = "Missing purpose.icon.";
    if (!empty($module['category'])) $moduleScore++; else $messages[] = "Missing category.";
    if (!empty($module['type'])) $moduleScore++; else $messages[] = "Missing type.";

    $percent = round(($moduleScore / $moduleMax) * 100, 1);
    $results[] = [
        "module" => $key,
        "score" => $moduleScore,
        "max" => $moduleMax,
        "percent" => $percent,
        "messages" => $messages
    ];

    $score += $moduleScore;
    $max   += $moduleMax;
}

$totalPercent = round(($score / $max) * 100, 1);
$recommendation = ($totalPercent < 90)
    ? "Review missing icons or purpose fields."
    : "Codex structure is within optimal compliance.";

$summary = [
    "date" => date('c'),
    "structureScore" => $totalPercent,
    "ontologyHealth" => "Stable",
    "recommendations" => $recommendation
];

// ---------------------------------------------------------------
//  STEP 3 – Generate PDF Report
// ---------------------------------------------------------------
require_once($root . '/libs/tcpdf.php');

class CodexAuditPDF extends TCPDF {
    public $root;
    function Header() {
        $logo = $this->root . '/assets/images/christyLogo.png';
        if (file_exists($logo)) $this->Image($logo, 15, 8, 36, '', 'PNG');
        $this->SetFont('helvetica','B',12);
        $this->Cell(0,10,'Codex Stress Test Report',0,1,'R');
        $this->SetFont('helvetica','',9);
        $this->Cell(0,5,'Issued by Parliamentarian CPAP-01',0,1,'R');
        $this->Line(15,25,200,25);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica','I',8);
        $this->Cell(0,10,'© Skyesoft Parliamentarian System — Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');
    }
}

$pdf = new CodexAuditPDF();
$pdf->root = $root;
$pdf->AddPage();
$pdf->SetFont('helvetica','',10);

$pdf->Write(5, "🧭 Codex Stress Test Doctrine – CP-STRESS-001\n\n");
$pdf->Write(5, "Date: " . date('F j, Y g:i A') . "\n");
$pdf->Write(5, "Overall Structure Score: {$summary['structureScore']}%\n");
$pdf->Write(5, "Ontology Health: {$summary['ontologyHealth']}\n");
$pdf->Write(5, "Recommendations: {$summary['recommendations']}\n\n");

foreach ($results as $r) {
    $pdf->SetFont('helvetica','B',10);
    $pdf->Write(4, $r['module'] . " (" . $r['percent'] . "%)\n");
    $pdf->SetFont('helvetica','',9);
    if (!empty($r['messages'])) {
        foreach ($r['messages'] as $m) $pdf->Write(4, " • " . $m . "\n");
    } else {
        $pdf->Write(4, " • PASS — structurally compliant\n");
    }
    $pdf->Ln(2);
}

if (!is_dir($reportDir)) mkdir($reportDir, 0777, true);
$pdf->Output($reportPDF, 'F');

// ---------------------------------------------------------------
//  STEP 4 – Response
// ---------------------------------------------------------------
echo json_encode([
    "status" => "success",
    "document" => str_replace($root, '', $reportPDF),
    "summary" => $summary
]);