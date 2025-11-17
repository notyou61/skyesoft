<?php
// =======================================================
// 🧪 Skyesoft Test: TCPDF Write Permissions Verification
// =======================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load TCPDF
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

// Create a simple test PDF
$pdf = new TCPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 16);
$pdf->Write(10, "✅ Skyesoft PDF Write Test\n\n" . date('Y-m-d H:i:s'));

// Define file output path
$path = '/home/notyou64/public_html/skyesoft/docs/test.pdf';

// Log attempt
error_log("🧭 Attempting to write test PDF to: $path");

// Write PDF to disk
try {
    $pdf->Output($path, 'F');
    if (file_exists($path)) {
        echo "✅ File created successfully: $path";
        error_log("✅ File created successfully: $path");
    } else {
        echo "❌ File creation failed!";
        error_log("❌ File creation failed!");
    }
} catch (Exception $e) {
    echo "❌ TCPDF Exception: " . $e->getMessage();
    error_log("❌ TCPDF Exception: " . $e->getMessage());
}
?>
