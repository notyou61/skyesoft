<?php
// api/diag_env.php — quick probe to confirm OPENAI_API_KEY is seen
header('Content-Type: application/json');
require_once __DIR__.'/env_boot.php';
$val = getenv('OPENAI_API_KEY');
$mask = $val ? substr($val,0,2).'***'.substr($val,-2) : null;
echo json_encode(array(
  'hasKey' => $val ? true : false,
  'keyMask'=> $mask
));
