<?php
// ===============================
// Weekly Mileage Report Generator (PHP 5.6)
// ===============================

// Config (edit as needed)
$employeeName = 'Steve Skye';
$ratePerMile  = 0.66;
$periodLabel  = 'August 4–8, 2025';
$logoPath     = 'christyLogo.png';           // (must be in reports/ with this script)
$outputFile   = __DIR__ . '/mileage_report_2025-08-04_to_2025-08-08.html';

// Data (round trips from Christy Signs unless noted otherwise)
$trips = array(
  // Init rows (date, destination, purpose, miles)
  array('date' => '8/6/2025', 'destination' => "Cheetah Gentlemen's Club – (WO #27320)", 'purpose' => 'Survey', 'miles' => 28.4),
  array('date' => '8/7/2025', 'destination' => 'Scott’s Coach Works – 4620 N. 7th Ave., Phoenix, AZ 85013', 'purpose' => 'Survey', 'miles' => 15.2),
);

// --------------- Helpers ---------------

// Escape HTML (basic)
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Format money ($12.34)
function fmtMoney($n) { return number_format($n, 2, '.', ''); }

// (calc) reimbursement
function reimbursement($miles, $rate) { return round($miles * $rate, 2); }

// --------------- Compute totals ---------------

// (sum) miles
$totalMiles = 0.0;  // Init total
// (sum) reimbursement
$totalReimb = 0.0;  // Init total

// Build table rows HTML
$rowsHtml = '';     // Init rows buffer

// Iterate trips (compute per-row reimbursement)
foreach ($trips as $t) {
  // User check (ensure keys exist)
  // (defensive coding for older PHP)
  $date        = isset($t['date']) ? $t['date'] : '';
  $destination = isset($t['destination']) ? $t['destination'] : '';
  $purpose     = isset($t['purpose']) ? $t['purpose'] : '';
  $miles       = isset($t['miles']) ? floatval($t['miles']) : 0.0;

  // Set count (default)
  $reimb = reimbursement($miles, $ratePerMile);

  // Totals (accumulate)
  $totalMiles += $miles;
  $totalReimb += $reimb;

  // Row HTML (right-aligned numeric cells)
  $rowsHtml .=
    "<tr>\n" .
    "  <td>" . h($date) . "</td>\n" .
    "  <td>" . h($destination) . "</td>\n" .
    "  <td>" . h($purpose) . "</td>\n" .
    "  <td style=\"text-align:right;\">" . number_format($miles, 1) . " miles</td>\n" .
    "  <td style=\"text-align:right;\">\$" . fmtMoney($reimb) . "</td>\n" .
    "</tr>\n";
}

// --------------- HTML Template ---------------

// Introduce blocks (styles + structure)
$html =
'<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Weekly Mileage Report – ' . h($periodLabel) . '</title>
<style>
  body { font-family: Arial, sans-serif; margin: 0.75in; position: relative; min-height: 10.5in; }
  header { display: flex; align-items: flex-start; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
  header img { height: 70px; margin-right: 15px; }
  .header-text h2 { margin: 0 0 5px 0; }
  .header-text p { margin: 2px 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 20px; }
  th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
  th { background-color: #f0f0f0; }
  tfoot td, tfoot th { font-weight: bold; }
  .footer {
    border-top: 2px solid #333; text-align: center; font-size: 0.8em; padding-top: 5px;
    position: absolute; bottom: 0.75in; left: 0.75in; right: 0.75in;
  }
</style>
</head>
<body>
  <header>
    <img src="' . h($logoPath) . '" alt="Christy Signs Logo" />
    <div class="header-text">
      <h2>Weekly Mileage Report</h2>
      <p>' . h($periodLabel) . '</p>
      <p><strong>Employee Name:</strong> ' . h($employeeName) . '</p>
      <p><strong>Rate Per Mile:</strong> $' . fmtMoney($ratePerMile) . '</p>
    </div>
  </header>

  <main>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Destination</th>
          <th>Purpose</th>
          <th>Mileage</th>
          <th>Reimbursement</th>
        </tr>
      </thead>
      <tbody>
' . $rowsHtml . '
      </tbody>
      <tfoot>
        <tr>
          <th colspan="3">Total</th>
          <th style="text-align:right;">' . number_format($totalMiles, 1) . ' miles</th>
          <th style="text-align:right;">$' . fmtMoney($totalReimb) . '</th>
        </tr>
      </tfoot>
    </table>
  </main>

  <div class="footer">
    Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 |
    (602) 242-4488 | christysigns.com<br />
    Copyright 2025 Christy Signs | All Rights Reserved
  </div>
</body>
</html>';

// Ensure consistent indentation and spacing
file_put_contents($outputFile, $html);

// Concise return statement (including all properties)
echo "Report generated: " . $outputFile . "\n";
