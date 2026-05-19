<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;

$mpdf = new Mpdf([
    'margin_left'   => 15,
    'margin_right'  => 15,
    'margin_top'    => 20,
    'margin_bottom' => 20,
]);

$mpdf->WriteHTML('<h1>Hello from mPDF</h1><p>This is a test.</p>');

$mpdf->Output('test_mpdf.pdf', 'I'); // 'I' = inline (opens in browser)