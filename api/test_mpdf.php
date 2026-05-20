<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

// =====================================================
// SAMPLE DATA (from previous payload - Fireshield example)
// =====================================================
$reportTitle     = "Proposed Contact Report (PC-1)";
$entityName      = "Fireshield Services LLC";
$contactName     = "Ms Kira Festa";
$contactTitle    = "Inside Account Manager";
$contactPhone    = "888-540-7632 Ext 704";
$contactEmail    = "kira@fireshieldcorp.com";
$locationAddress = "3654 N Power Rd";
$locationCityStateZip = "Mesa, AZ 85215";
$locationPlaceId = "ChIJZa4P6dykk4cRHuAQvn1sWw4";
$confidence      = 85;
$pcCode          = "PC-1";
$resolutionStatus = "multiple_parcels";
$commitAllowed   = "NO";
$governanceNarrative = "This proposal represents a new entity, location, and contact. Review: Multiple parcel candidates were found and user selection is required.";
$entityAction    = "hold";
$locationAction  = "hold";
$contactAction   = "hold";

// =====================================================
// Build HTML Content - HTML
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

            line-height: 1.22;

            margin: 0;

            padding: 0;
        }

        /* =====================================================
        🏢 EXECUTIVE OPERATIONAL HEADER
        ===================================================== */

        .header {

            border-bottom: 2.5px solid #14377C;

            padding-bottom: 4px;

            margin-bottom: 8px;
        }

        .headerTable {

            width: 100%;

            border-collapse: collapse;

            border: none;
        }

        .headerTable td {

            border: none;

            padding: 0;

            vertical-align: top;
        }

        .headerLogoCell {

            width: 62px;

            white-space: nowrap;

            padding-right: 6px;
        }

        .logo {

            width: 58px;

            height: auto;

            margin-top: 2px;
        }

        .headerTitleCell {

            text-align: left;
        }

        .headerTitle {

            font-size: 14pt;

            font-weight: 700;

            color: #14377C;

            line-height: 1.0;

            margin-bottom: 2px;
        }

        .headerSubtitle {

            font-size: 8.5pt;

            color: #444;

            line-height: 1.1;
        }

        /* =====================================================
        🏷️ SECTION HEADERS
        ===================================================== */

        h1 {
            font-size: 16pt;

            color: #14377C;

            margin: 0 0 4px 0;

            font-weight: 700;
        }

        h2 {

            font-size: 11.5pt;

            color: #14377C;

            border-bottom: 1px solid #ccc;

            padding-bottom: 2px;

            margin-top: 12px;

            margin-bottom: 4px;

            font-weight: 700;
        }

        /* =====================================================
        📊 OPERATIONAL TABLES
        ===================================================== */

        table {

            width: 100%;

            table-layout: fixed;

            border-collapse: collapse;

            margin: 4px 0 8px 0;
        }

        th,
        td {

            border: 1px solid #ccc;

            padding: 4px 6px;

            text-align: left;

            vertical-align: top;
        }

        th {

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

            padding: 7px 9px;

            margin: 6px 0;
        }

        .warning {

            background: #fff8e6;

            border-left: 4px solid #d48a00;

            padding: 12px 14px;

            margin: 14px 0;
        }

        .critical {

            background: #fff0f0;

            border-left: 4px solid #b00020;

            padding: 12px 14px;

            margin: 14px 0;
        }

    </style>
</head>
<body>

    <!-- HEADER -->
    <div class="header">

        <table class="headerTable">

            <tr>

                <!-- =================================================
                    🏢 LOGO COLUMN
                ================================================== -->

                <td class="headerLogoCell">

                    <img
                        src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png"
                        class="logo"
                        alt="Christy Signs">

                </td>

                <!-- =================================================
                    🧾 TITLE COLUMN
                ================================================== -->

                <td class="headerTitleCell">

                    <div class="headerTitle">
                        ' . $reportTitle . '
                    </div>

                    <div class="headerSubtitle">
                        Skyesoft Operational Intelligence
                    </div>

                </td>

            </tr>

        </table>

    </div>

    <!-- RESOLUTION SUMMARY -->
    <h2>Resolution Summary</h2>
    <table>
        <tr><th>Report Title</th><td>' . $reportTitle . '</td></tr>
        <tr><th>Confidence</th><td>' . $confidence . '%</td></tr>
        <tr><th>PC Code</th><td>' . $pcCode . '</td></tr>
        <tr><th>Status</th><td>' . $resolutionStatus . '</td></tr>
        <tr><th>Commit Ready</th><td><strong>' . $commitAllowed . '</strong></td></tr>
    </table>

    <!-- ENTITY -->
    <h2>Entity Information</h2>
    <table>
        <tr><th>Entity Name</th><td>' . $entityName . '</td></tr>
    </table>

    <!-- CONTACT -->
    <h2>Contact Information</h2>
    <table>
        <tr><th>Contact Name</th><td>' . $contactName . '</td></tr>
        <tr><th>Title</th><td>' . $contactTitle . '</td></tr>
        <tr><th>Phone</th><td>' . $contactPhone . '</td></tr>
        <tr><th>Email</th><td>' . $contactEmail . '</td></tr>
    </table>

    <!-- LOCATION -->
    <h2>Location Information</h2>
    <table>
        <tr><th>Full Address</th><td>' . $locationAddress . '</td></tr>
        <tr><th>City, State ZIP</th><td>' . $locationCityStateZip . '</td></tr>
        <tr><th>Place ID</th><td>' . $locationPlaceId . '</td></tr>
    </table>

    <!-- GOVERNANCE NARRATIVE -->
    <h2>Governance & Operational Narrative</h2>
    <div class="highlight">
        ' . $governanceNarrative . '
    </div>

    <!-- PERSISTENCE / STAGING -->
    <h2>Persistence / Staging State</h2>
    <table>
        <tr><th>Entity Action</th><td>' . $entityAction . '</td></tr>
        <tr><th>Location Action</th><td>' . $locationAction . '</td></tr>
        <tr><th>Contact Action</th><td>' . $contactAction . '</td></tr>
        <tr><th>Commit Allowed</th><td><strong>' . $commitAllowed . '</strong></td></tr>
    </table>

    <!-- =====================================================
     🧾 OPERATIONAL FOOTER
    ====================================================== -->

    <div class="footer">

        <!-- =============================================
            🏢 COMPANY LINE
        ============================================== -->

        <div class="footerCompany">

            Christy Signs |
            3145 N 33rd Ave, Phoenix AZ 85017 |
            (602) 242-4488 |
            christysigns.com

        </div>

        <!-- =============================================
            © COPYRIGHT / DOCUMENT CLASSIFICATION
        ============================================== -->

        <div class="footerMeta">

            © 2026 Christy Signs —
            Confidential Internal Operational Document

        </div>

    </div>

</body>
</html>
';

// =====================================================
// Generate PDF
// =====================================================
$mpdf = new \Mpdf\Mpdf([

    'format' => 'Letter',

    'margin_left'   => 8,

    'margin_right'  => 8,

    'margin_top'    => 8,

    'margin_bottom' => 8,
]);

$mpdf->WriteHTML($html);
$mpdf->Output('Proposed_Contact_Report_PC1.pdf', 'I');