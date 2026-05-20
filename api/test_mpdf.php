<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

// =====================================================
// SAMPLE DATA
// =====================================================
$reportTitle          = "Proposed Contact Report (PC-1)";
$entityName           = "Fireshield Services LLC";
$contactName          = "Ms Kira Festa";
$contactTitle         = "Inside Account Manager";
$contactPhone         = "888-540-7632 Ext 704";
$contactEmail         = "kira@fireshieldcorp.com";
$locationAddress      = "3654 N Power Rd";
$locationCityStateZip = "Mesa, AZ 85215";
$locationPlaceId      = "ChIJZa4P6dykk4cRHuAQvn1sWw4";
$confidence           = 85;
$pcCode               = "PC-1";
$resolutionStatus     = "multiple_parcels";
$commitAllowed        = "NO";
$governanceNarrative  = "This proposal represents a new entity, location, and contact. Review: Multiple parcel candidates were found and user selection is required.";
$entityAction         = "hold";
$locationAction       = "hold";
$contactAction        = "hold";

// =====================================================
// FOOTER HTML (This will be locked at the bottom)
// =====================================================
$footerHtml = '
<div style="border-top: 2.5px solid #14377C; padding-top: 5px; font-size: 7.5pt; color: #555; text-align: center; width: 100%;">
    <div style="font-weight: 600; margin-bottom: 2px;">
        Christy Signs &nbsp;|&nbsp; 3145 N 33rd Ave, Phoenix, AZ 85017 &nbsp;|&nbsp; (602) 242-4488
    </div>
    <div style="font-size: 7pt; color: #666;">
        © 2026 Christy Signs — Confidential Internal Operational Document &nbsp;&nbsp;•&nbsp;&nbsp; Page {PAGENO} of {nbpg}
    </div>
</div>
';

// =====================================================
// Build HTML Content
// =====================================================
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11pt;
            color: #222;
            line-height: 1.25;
            margin: 0;
            padding: 0;
        }

        .header {
            border-bottom: 2.5px solid #14377C;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }

        .headerTable {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }

        .headerLogoCell {
            width: 62px;
            white-space: nowrap;
            padding-right: 8px;
            vertical-align: middle;
        }

        .logo {
            width: 58px;
            height: auto;
        }

        .headerTitle {
            font-size: 14pt;
            font-weight: 700;
            color: #14377C;
            line-height: 1.1;
            margin-bottom: 1px;
        }

        .headerSubtitle {
            font-size: 9pt;
            color: #555;
        }

        h2 {
            font-size: 11.5pt;
            color: #14377C;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin-top: 14px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .dataTable {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin: 4px 0 10px 0;
        }

        .dataTable th,
        .dataTable td {
            border: 1px solid #ccc;
            padding: 5px 7px;
            text-align: left;
            vertical-align: top;
        }

        .dataTable th {
            background: #f5f5f5;
            width: 28%;
            font-weight: 700;
            color: #333;
        }

        .highlight {
            background: #f0f7ff;
            border-left: 4px solid #14377C;
            padding: 8px 10px;
            margin: 8px 0;
        }
    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">
        <table class="headerTable">
            <tr>
                <td class="headerLogoCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png"
                         class="logo" alt="Christy Signs">
                </td>
                <td>
                    <div class="headerTitle">' . $reportTitle . '</div>
                    <div class="headerSubtitle">Skyesoft Operational Intelligence</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- RESOLUTION SUMMARY -->
    <h2>Resolution Summary</h2>
    <table class="dataTable">
        <tr><th>Report Title</th><td>' . $reportTitle . '</td></tr>
        <tr><th>Confidence</th><td>' . $confidence . '%</td></tr>
        <tr><th>PC Code</th><td>' . $pcCode . '</td></tr>
        <tr><th>Status</th><td>' . $resolutionStatus . '</td></tr>
        <tr><th>Commit Ready</th><td><strong>' . $commitAllowed . '</strong></td></tr>
    </table>

    <!-- ENTITY -->
    <h2>Entity Information</h2>
    <table class="dataTable">
        <tr><th>Entity Name</th><td>' . $entityName . '</td></tr>
    </table>

    <!-- CONTACT -->
    <h2>Contact Information</h2>
    <table class="dataTable">
        <tr><th>Contact Name</th><td>' . $contactName . '</td></tr>
        <tr><th>Title</th><td>' . $contactTitle . '</td></tr>
        <tr><th>Phone</th><td>' . $contactPhone . '</td></tr>
        <tr><th>Email</th><td>' . $contactEmail . '</td></tr>
    </table>

    <!-- LOCATION -->
    <h2>Location Information</h2>
    <table class="dataTable">
        <tr><th>Full Address</th><td>' . $locationAddress . '</td></tr>
        <tr><th>City, State ZIP</th><td>' . $locationCityStateZip . '</td></tr>
        <tr><th>Place ID</th><td>' . $locationPlaceId . '</td></tr>
    </table>

    <!-- GOVERNANCE NARRATIVE -->
    <h2>Governance &amp; Operational Narrative</h2>
    <div class="highlight">
        ' . $governanceNarrative . '
    </div>

    <!-- PERSISTENCE / STAGING -->
    <h2>Persistence / Staging State</h2>
    <table class="dataTable">
        <tr><th>Entity Action</th><td>' . $entityAction . '</td></tr>
        <tr><th>Location Action</th><td>' . $locationAction . '</td></tr>
        <tr><th>Contact Action</th><td>' . $contactAction . '</td></tr>
        <tr><th>Commit Allowed</th><td><strong>' . $commitAllowed . '</strong></td></tr>
    </table>

</body>
</html>
';

// =====================================================
// Generate PDF with Native Footer
// =====================================================
$mpdf = new Mpdf([
    'format'        => 'Letter',
    'margin_left'   => 10,
    'margin_right'  => 10,
    'margin_top'    => 10,
    'margin_bottom' => 25,           // Reserve space for footer
]);

// Set the footer (this locks it to the bottom)
$mpdf->SetHTMLFooter($footerHtml);

$mpdf->WriteHTML($html);
$mpdf->Output('Proposed_Contact_Report_PC1.pdf', 'I');