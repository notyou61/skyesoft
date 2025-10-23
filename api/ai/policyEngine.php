<?php
// 📘 File: api/ai/policyEngine.php
// Purpose: Central governance layer for Skyebot™ prompt construction
// Compatible with PHP 5.6 (no null-coalescing operators)

error_reporting(E_ALL);
ini_set('display_errors', 1);

#region ⚙️  DEPENDENCY LOADING
$baseDir = dirname(__FILE__);
$rootDir = dirname(dirname(__DIR__));  // PHP 5.6-safe, two levels up

// 🔗 Include helpers first so codexConsult() is always available
require_once($rootDir . '/api/helpers.php');

// Core logic modules
require_once($baseDir . '/semanticRouter.php');
require_once($baseDir . '/sseProxy.php');

// Define expected data file paths
$semanticPath = $rootDir . '/assets/data/semantic.json';
$codexPath    = $rootDir . '/assets/data/codex.json';
$ssePath      = $rootDir . '/assets/data/dynamicData.json';

// Log missing data files (non-fatal)
if (!file_exists($semanticPath)) error_log("⚠️ semantic.json missing at $semanticPath");
if (!file_exists($codexPath))    error_log("⚠️ codex.json missing at $codexPath");
if (!file_exists($ssePath))      error_log("⚠️ dynamicData.json missing at $ssePath");
#endregion

#region 🧠  SEMANTIC ROUTER FALLBACK
// Temporary stub until full semanticRouter implemented
if (!function_exists('semanticRoute')) {
    function semanticRoute($input) {
        // Example semantic inference
        if (stripos($input, 'workday') !== false) {
            return array('domain' => 'temporal', 'target' => 'timeIntervalStandards', 'confidence' => 0.9);
        } elseif (stripos($input, 'weather') !== false) {
            return array('domain' => 'contextual', 'target' => 'weatherData', 'confidence' => 0.9);
        } else {
            return array('domain' => 'general', 'target' => 'skyesoftConstitution', 'confidence' => 0.5);
        }
    }
}
#endregion

#region 🧩  CODEX CONSULT FALLBACK
if (!function_exists('codexConsult')) {
    function codexConsult($target) {
        $codexPath = dirname(dirname(__DIR__)) . '/assets/data/codex.json';
        if (!file_exists($codexPath)) {
            error_log("⚠️ codex.json missing at $codexPath");
            return array();
        }
        $codex = json_decode(file_get_contents($codexPath), true);
        if (isset($codex[$target])) {
            return $codex[$target];
        }

        // Try deeper lookup (when target is nested)
        foreach ($codex as $sectionName => $sectionData) {
            if (is_array($sectionData) && isset($sectionData['title']) && stripos($sectionData['title'], $target) !== false) {
                return $sectionData;
            }
        }
        return array();
    }
}
#endregion

#region 🧩  POLICY RESOLUTION
$userInput = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($userInput === '') {
    echo "❌ No query received.";
    exit;
}

echo "✅ PolicyEngine initialized.<br>";

$semanticResult = semanticRoute($userInput);
echo "📘 Domain: " . $semanticResult['domain'] . "<br>";
echo "🧩 Target: " . $semanticResult['target'] . "<br>";

if (!empty($semanticResult['target'])) {
    $policy = codexConsult($semanticResult['target']);
    if (!empty($policy)) {
        echo "📊 Codex Policy Loaded Successfully.<br>";
    } else {
        echo "⚠️ No policy found for target: " . $semanticResult['target'] . "<br>";
    }
}
#endregion