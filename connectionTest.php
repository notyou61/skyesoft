<?php
$mysqli = new mysqli("localhost", "notyou64_skyesoft_user", "TestPass123!", "skyesoft");

if ($mysqli->connect_error) {
    die("MySQLi failed: " . $mysqli->connect_error);
}

echo "MySQLi connection successful!";
