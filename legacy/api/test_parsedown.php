<?php
require_once(__DIR__ . '/../libs/parsedown/Parsedown.php');

$Parsedown = new Parsedown();
echo $Parsedown->text("# Hello World\nThis is a test.");
