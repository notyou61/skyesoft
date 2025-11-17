<?php
// /skyesoft/api/login.php  (PHP 5.x compatible)
session_start();
header('Content-Type: application/json');

// Be quiet in output (avoid HTML warnings breaking JSON)
@ini_set('display_errors','0');
@ini_set('log_errors','1');

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(array('success'=>false,'message'=>'Method Not Allowed'));
  exit;
}

// Read form fields first
$u = isset($_POST['username']) ? $_POST['username'] : null;
$p = isset($_POST['password']) ? $_POST['password'] : null;

// If missing, try JSON body
if ($u === null && $p === null) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $u = isset($json['username']) ? $json['username'] : null;
      $p = isset($json['password']) ? $json['password'] : null;
    }
  }
}

// Basic validation (replace with real auth)
if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
  http_response_code(400);
  echo json_encode(array('success'=>false,'message'=>'Invalid credentials'));
  exit;
}

// ✅ Fake success (plug in your real check here)
$_SESSION['logged_in']  = true;
$_SESSION['contact_id'] = 1;

// Cookie for frontend logger (old setcookie signature for PHP 5.x)
setcookie('skye_contactID', (string)$_SESSION['contact_id'], 0, '/');

echo json_encode(array(
  'success'   => true,
  'contactID' => $_SESSION['contact_id'],
  'session'   => session_id()
));
