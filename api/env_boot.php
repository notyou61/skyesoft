<?php
// api/env_boot.php — legacy-safe .env loader
if (!function_exists('skye_load_env_once')) {
  function skye_load_env_once() {
    static $did = false; if ($did) return; $did = true;
    $candidates = array(__DIR__.'/../secure/.env', __DIR__.'/../../../secure/.env');
    foreach ($candidates as $p) {
      if (!file_exists($p)) continue;
      $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!$lines) continue;
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        if ($v !== '' && $v[0] === '"' && substr($v,-1) === '"') $v = substr($v,1,-1);
        if ($v !== '' && $v[0] === "'" && substr($v,-1) === "'") $v = substr($v,1,-1);
        if ($k !== '' && getenv($k) === false) putenv($k.'='.$v);
      }
      break; // stop at first found
    }
  }
}
skye_load_env_once();
