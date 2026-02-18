<?php
require 'dbConnect.php';

$result = $databaseConnection->query("SELECT DATABASE() AS current_db");

$row = $result->fetch_assoc();

echo "Active database: " . $row['current_db'];