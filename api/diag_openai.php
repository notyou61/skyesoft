<?php
// /skyesoft/api/diag_openai.php (legacy PHP compatible)
header('Content-Type: application/json');
require_once __DIR__ . '/env_boot.php';

$apiKey = getenv('OPENAI_API_KEY');

$out = array(
  'hasKey' => (bool)$apiKey,
  'http' => null,
  'errno' => null,
  'error' => null,
  'bodyPreview' => null,
  'notes' => array()
);

if (!$apiKey) { echo json_encode($out); exit; }

$ch = curl_init('https://api.openai.com/v1/models'); // simple, auth-required GET
$headers = array(
  'Authorization: Bearer ' . $apiKey,
  'Content-Type: application/json'
);
curl_setopt_array($ch, array(
  CURLOPT_HTTPHEADER      => $headers,
  CURLOPT_RETURNTRANSFER  => true,
  CURLOPT_FOLLOWLOCATION  => false,
  CURLOPT_TIMEOUT         => 15,
  CURLOPT_CONNECTTIMEOUT  => 8,
  CURLOPT_SSL_VERIFYPEER  => true,
  CURLOPT_SSL_VERIFYHOST  => 2
));

$body  = curl_exec($ch);
$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno = curl_errno($ch);
$error = curl_error($ch);
curl_close($ch);

$out['http'] = $http;
$out['errno'] = $errno;
$out['error'] = $error;
$out['bodyPreview'] = is_string($body) ? substr($body, 0, 300) : null;

// quick hints
if ($errno === 0 && $http === 200) $out['notes'][] = 'Connectivity OK (key valid).';
if ($errno === 0 && $http === 401) $out['notes'][] = 'Key present but invalid/expired (401).';
if ($errno === 0 && $http === 429) $out['notes'][] = 'Rate limited (429).';
if ($errno === 0 && $http >= 400 && $http < 500 && $http !== 401) $out['notes'][] = 'Client error; request OK over TLS.';
if ($errno === 60) $out['notes'][] = 'SSL cert/CA bundle problem (error 60).';
if ($errno === 35) $out['notes'][] = 'TLS handshake issue (error 35) — old OpenSSL/cURL.';
if ($errno === 6)  $out['notes'][] = 'DNS resolution failed.';
if ($errno === 28) $out['notes'][] = 'Connection timed out (firewall or network).';

echo json_encode($out);
