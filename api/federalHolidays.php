<?php
// =============================================================
// Skyesoft Federal Holidays Provider (PHP 5.6 Compatible)
// =============================================================
$year = date('Y');

$holidays = [
    date('Y-m-d', strtotime("january 1 $year")) => "New Year's Day",
    date('Y-m-d', strtotime("third monday of january $year")) => "Martin Luther King Jr. Day",
    date('Y-m-d', strtotime("third monday of february $year")) => "Presidents' Day",
    date('Y-m-d', strtotime("last monday of may $year")) => "Memorial Day",
    date('Y-m-d', strtotime("july 4 $year")) => "Independence Day",
    date('Y-m-d', strtotime("first monday of september $year")) => "Labor Day",
    date('Y-m-d', strtotime("second monday of october $year")) => "Columbus Day",
    date('Y-m-d', strtotime("november 11 $year")) => "Veterans Day",
];

// --- Reliable Thanksgiving + Day After computation ---
$thanksgiving = strtotime("fourth thursday of november $year");
if ($thanksgiving !== false) {
    $holidays[date('Y-m-d', $thanksgiving)] = "Thanksgiving Day";
    $dayAfterThanksgiving = strtotime("+1 day", $thanksgiving);
    $holidays[date('Y-m-d', $dayAfterThanksgiving)] = "Day After Thanksgiving";
}

// --- Christmas ---
$holidays[date('Y-m-d', strtotime("december 25 $year"))] = "Christmas Day";

ksort($holidays);

// ✅ When *included*, just return the array (no echo).
if (defined('SKYESOFT_INTERNAL_CALL')) {
    return $holidays;
}

// ✅ When *run directly* (CLI or web), output JSON for testing.
header('Content-Type: application/json');
echo json_encode($holidays);
exit;
