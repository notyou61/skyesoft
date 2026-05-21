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
// HEADER
// =====================================================
$headerHtml = '
<div style="border-bottom: 2.5px solid #14377C; padding-bottom: 6px;">
    <table style="width:100%; border:none;">
        <tr>
            <td style="width:62px; padding-right:8px; vertical-align:middle;">
                <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png" 
                     style="width:58px; height:auto;" alt="Christy Signs">
            </td>
            <td>
                <div style="font-size:14pt; font-weight:700; color:#14377C; line-height:1.1;">' . $reportTitle . '</div>
                <div style="font-size:9pt; color:#555;">Skyesoft Operational Intelligence</div>
            </td>
        </tr>
    </table>
</div>
';

// =====================================================
// FOOTER
// =====================================================
$footerHtml = '
<div style="border-top: 2.5px solid #14377C; padding-top: 5px; font-size:7.5pt; color:#555; text-align:center;">
    <div style="font-weight:600; margin-bottom:2px;">
        Christy Signs &nbsp;|&nbsp; 3145 N 33rd Ave, Phoenix, AZ 85017 &nbsp;|&nbsp; (602) 242-4488
    </div>
    <div style="font-size:7pt; color:#666;">
        © 2026 Christy Signs — Confidential Internal Operational Document &nbsp;&nbsp;•&nbsp;&nbsp; Page {PAGENO} of {nbpg}
    </div>
</div>
';

// =====================================================
// BODY CONTENT
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

        /* =====================================================
           🏢 EXECUTIVE OPERATIONAL HEADER
        ===================================================== */
        .header {
            border-bottom: 2.5px solid #14377C;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }

        /* =====================================================
           🏷️ SECTION HEADER TABLE (mPDF-safe alignment)
        ===================================================== */
        .sectionHeaderTable {
            width: 100%;
            border-collapse: collapse;
            border: none;
            margin-top: 16px;
            margin-bottom: 5px;
            border-bottom: 1px solid #ccc;
        }

        .sectionIconCell {
            width: 20px;
            border: none;
            padding: 0 8px 4px 0;
            vertical-align: middle;
        }

        .sectionTitleCell {
            border: none;
            padding: 0 0 4px 0;
            vertical-align: middle;
        }

        .sectionIcon {
            width: 16px;
            height: 16px;
            object-fit: contain;
            display: block;
        }

        .sectionTitle {
            font-size: 11.5pt;
            font-weight: 700;
            color: #14377C;
            line-height: 1.0;
            margin: 0;
        }

        /* =====================================================
           📊 OPERATIONAL DATA TABLES
        ===================================================== */
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

        /* =====================================================
           🚨 OPERATIONAL PANELS
        ===================================================== */
        .highlight {
            background: #f0f7ff;
            border-left: 4px solid #14377C;
            padding: 8px 10px;
            margin: 8px 0;
        }

        /* =====================================================
           🧩 SECTION INTEGRITY GOVERNANCE
        ===================================================== */
        .section {
            page-break-inside: avoid;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

    <!-- RESOLUTION SUMMARY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/clipboard.png" 
                         class="sectionIcon" alt="Resolution">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Resolution Summary</div>
                </td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Report Title</th><td>' . $reportTitle . '</td></tr>
            <tr><th>Confidence</th><td>' . $confidence . '%</td></tr>
            <tr><th>PC Code</th><td>' . $pcCode . '</td></tr>
            <tr><th>Status</th><td>' . $resolutionStatus . '</td></tr>
            <tr><th>Commit Ready</th><td><strong>' . $commitAllowed . '</strong></td></tr>
        </table>
    </div>

    <!-- ENTITY -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/property.png" 
                         class="sectionIcon" alt="Entity">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Entity Information</div>
                </td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Entity Name</th><td>' . $entityName . '</td></tr>
        </table>
    </div>

    <!-- CONTACT -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/users.png" 
                         class="sectionIcon" alt="Contact">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Contact Information</div>
                </td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Contact Name</th><td>' . $contactName . '</td></tr>
            <tr><th>Title</th><td>' . $contactTitle . '</td></tr>
            <tr><th>Phone</th><td>' . $contactPhone . '</td></tr>
            <tr><th>Email</th><td>' . $contactEmail . '</td></tr>
        </table>
    </div>

    <!-- LOCATION -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/pin.png" 
                         class="sectionIcon" alt="Location">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Location Information</div>
                </td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Full Address</th><td>' . $locationAddress . '</td></tr>
            <tr><th>City, State ZIP</th><td>' . $locationCityStateZip . '</td></tr>
            <tr><th>Place ID</th><td>' . $locationPlaceId . '</td></tr>
        </table>
    </div>

    <!-- GOVERNANCE NARRATIVE -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/scales.png" 
                         class="sectionIcon" alt="Governance">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Governance &amp; Operational Narrative</div>
                </td>
            </tr>
        </table>
        <div class="highlight">
            ' . $governanceNarrative . '
        </div>
    </div>

    <!-- PERSISTENCE / STAGING -->
    <div class="section">
        <table class="sectionHeaderTable">
            <tr>
                <td class="sectionIconCell">
                    <img src="https://skyelighting.com/skyesoft/assets/images/icons/puzzle.png" 
                         class="sectionIcon" alt="Persistence">
                </td>
                <td class="sectionTitleCell">
                    <div class="sectionTitle">Persistence / Staging State</div>
                </td>
            </tr>
        </table>
        <table class="dataTable">
            <tr><th>Entity Action</th><td>' . $entityAction . '</td></tr>
            <tr><th>Location Action</th><td>' . $locationAction . '</td></tr>
            <tr><th>Contact Action</th><td>' . $contactAction . '</td></tr>
            <tr><th>Commit Allowed</th><td><strong>' . $commitAllowed . '</strong></td></tr>
        </table>
    </div>

</body>
</html>
';

// =====================================================
// Generate PDF
// =====================================================
$mpdf = new Mpdf([
    'format'        => 'Letter',
    'margin_left'   => 10,
    'margin_right'  => 10,
    'margin_top'    => 26,
    'margin_bottom' => 22,
]);

$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);

$mpdf->WriteHTML($html);
$mpdf->Output('Proposed_Contact_Report_PC1.pdf', 'I');