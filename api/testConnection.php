<?php
require_once __DIR__ . '/dbConnect.php';

$result = $databaseConnection->query("SHOW TABLES");

echo "<pre>";
while ($row = $result->fetch_row()) {
    echo $row[0] . "\n";
}
echo "</pre>";