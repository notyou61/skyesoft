<?php
// /skyesoft/api/diag_openai.php
header('Content-Type: application/json');
require_once __DIR__ . '/env_boot.php';
$apiKey = getenv('OPENAI_API_KEY');

$out = [
  'hasKey' => (bool)$apiKey,
  'http' => null,
  'errno' => null,
  'error' => null,
  'bodyPreview' => null,
  'notes' => []
];

if (!$apiKey) { echo json_encode($out); exit; }

$ch = curl_init('https://api.openai.com/v1/models'); // simple, auth-required GET
$headers = [
  'Authorization: Bearer ' . $apiKey,
  'Content-Type: application/json'
];
curl_setopt_array($ch, [
  CURLOPT_HTTPHEADER      => $headers,
  CURLOPT_RETURNTRANSFER  => true,
  CURLOPT_FOLLOWLOCATION  => false,
  CURLOPT_TIMEOUT         => 15,
  CURLOPT_CONNECTTIMEOUT  => 8,
  CURLOPT_SSL_VERIFYPEER  => true,
  CURLOPT_SSL_VERIFYHOST  => 2,
  CURLOPT_SSLVERSION      => defined('CURL_SSLVERSION_TLSv1_2') ? CURL_SSLVERSION_TLSv1_2 : 0
]);

$body = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno = curl_errno($ch);
$error = curl_error($ch);
curl_close($ch);

$out['http'] = $http;
$out['errno'] = $errno;
$out['error'] = $error;
$out['bodyPreview'] = is_string($body) ? substr($body, 0, 300) : null;

// quick hints
if ($errno === 0 && $http === 200) $out['notes'][] = 'âœ… Connectivity OK (key valid).';
if ($errno === 0 && $http === 401) $out['notes'][] = 'í´‘ Key present but invalid/expired (401).';
if ($errno === 0 && $http === 429) $out['notes'][] = 'â±ï¸ Rate limited (429).';
if ($errno === 0 && $http >= 400 && $http < 500 && $http !== 401) $out['notes'][] = 'â— Client error; request OK over TLS.';
if ($errno === 60) $out['notes'][] = 'í´’ SSL cert/CA bundle problem (error 60).';
if ($errno === 35) $out['notes'][] = 'í´ TLS handshake issue (error 35) â€” old OpenSSL/cURL.';
if ($errno === 6)  $out['notes'][] = 'í¼ DNS resolution failed.';
if ($errno === 28) $out['notes'][] = 'âŒ› Connection timed out (firewall or network).';

echo json_encode($out);
