<?php
// federalHolidays.php
header('Content-Type: application/json');

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
    date('Y-m-d', strtotime("fourth thursday of november $year")) => "Thanksgiving Day",
    date('Y-m-d', strtotime("friday after fourth thursday of november $year")) => "Day After Thanksgiving",
    date('Y-m-d', strtotime("december 25 $year")) => "Christmas Day"
];

ksort($holidays);

echo json_encode($holidays);
