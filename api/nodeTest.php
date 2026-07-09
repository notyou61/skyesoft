<?php

echo "<pre>";

echo "PATH:\n";
echo getenv('PATH');

echo "\n\n";

echo "which node:\n";
echo shell_exec('which node 2>&1');

echo "\n\n";

echo "whereis node:\n";
echo shell_exec('whereis node 2>&1');

echo "\n\n";

echo "node -v:\n";
echo shell_exec('node -v 2>&1');

echo "</pre>";