<?php

$output = shell_exec('wkhtmltopdf --version 2>&1');

echo '<pre>';
print_r($output);
echo '</pre>';