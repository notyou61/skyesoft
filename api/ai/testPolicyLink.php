<?php
// 🔍 PolicyEngine Integration Test

require_once(__DIR__ . '/policyEngine.php');

echo "<h2>🧠 Skyebot PolicyEngine Diagnostic</h2>";

$checks = array(
  'semanticRouter.php' => function_exists('detectDomain'),
  'codexConsult.php'   => function_exists('codexConsult'),
  'sseProxy.php'       => function_exists('fetchSSESnapshot'),
  'policyEngine.php'   => function_exists('runPolicyEngine')
);

foreach ($checks as $file => $result) {
  echo $result
    ? "✅ $file loaded successfully<br>"
    : "❌ $file missing or function undefined<br>";
}

// Optional live test
echo "<hr>";
if (function_exists('runPolicyEngine')) {
  $prompt = runPolicyEngine("What time does the workday end today?");
  echo "<b>Sample PolicyEngine output:</b><pre>" . htmlspecialchars(print_r($prompt, true)) . "</pre>";
} else {
  echo "⚠️ PolicyEngine not available.";
}
?>
