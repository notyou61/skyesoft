<?php
// /skyesoft/api/login.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'message'=>'Method Not Allowed']);
  exit;
}

// Support FormData (recommended) and JSON (fallback)
$u = $_POST['username'] ?? null;
$p = $_POST['password'] ?? null;

if ($u === null && $p === null) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $u = $json['username'] ?? null;
      $p = $json['password'] ?? null;
    }
  }
}

// TODO: replace with real auth check
if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Invalid credentials']);
  exit;
}

// ✅ Fake-pass for now (wire real validator later)
$_SESSION['logged_in'] = true;
$_SESSION['contact_id'] = 1;

// Optional cookie used by your logger
setcookie('skye_contactID', (string)$_SESSION['contact_id'], [
  'path' => '/',
  'httponly' => false,
  'samesite' => 'Lax'
]);

echo json_encode([
  'success' => true,
  'contactID' => $_SESSION['contact_id'],
  'session' => session_id()
]);
