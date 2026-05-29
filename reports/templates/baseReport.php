<?php
// =============================================
// baseReport.php - Universal Renderer
// =============================================
require_once __DIR__ . '/../../vendor/autoload.php';

function renderReport(array $report): string
{
    $mpdf = new \Mpdf\Mpdf([
        'margin_header' => 10,
        'margin_footer' => 15,
        'margin_left'   => 15,
        'margin_right'  => 15,
    ]);

    // Universal Header
    $mpdf->SetHTMLHeader('
        <div style="text-align:center; border-bottom:2px solid #003087; padding-bottom:10px;">
            <strong>CHRISTY</strong><br>
            <h2 style="margin:5px 0;">' . htmlspecialchars($report['reportTitle']) . '</h2>
            <div style="font-size:11px;">Generated on ' . date('F j, Y \a\t g:i A') . '</div>
        </div>
    ');

    // Page 1: Executive Summary
    $mpdf->WriteHTML('<h1 style="color:#003087;">Executive Summary</h1>');
    $mpdf->WriteHTML($report['reportSummary'] ?? '<p>No summary available.</p>');

    $mpdf->AddPage();

    // All remaining content (fully generic)
    $mpdf->WriteHTML($report['reportBodyHtml'] ?? '');

    // Universal Footer
    $mpdf->SetHTMLFooter('
        <table width="100%" style="font-size:10px; border-top:1px solid #ccc; padding-top:5px;">
            <tr>
                <td width="33%" align="left">Christy | Tempe, Arizona</td>
                <td width="33%" align="center">Confidential</td>
                <td width="33%" align="right">Page {PAGENO} of {nbpg}</td>
            </tr>
        </table>
    ');

    return $mpdf->Output('', 'S');
}