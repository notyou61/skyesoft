<?php
// /skyesoft/api/logout.php (PHP 5.x friendly)
error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');
if (session_id()==='') session_start();
header('Content-Type: application/json');
// Destroy session
$_SESSION = array();
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
// Clear app cookies
setcookie('skye_contactID','', time()-3600, '/');
echo json_encode(array('success'=>true));
