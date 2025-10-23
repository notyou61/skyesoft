<?php
// 📘 File: api/ai/policyEngine.php
// Purpose: Central governance layer for Skyebot™ prompt construction (function-based version)
// Compatible with PHP 5.6 (no null-coalescing operators)

error_reporting(E_ALL);
ini_set('display_errors', 1);

#region ⚙️ DEPENDENCY LOADING
$baseDir = dirname(__FILE__);
$rootDir = dirname(dirname(__DIR__)); // PHP 5.6-safe, two levels up

// Include helpers first
require_once($rootDir . '/api/helpers.php');

// Core modules
require_once($baseDir . '/semanticRouter.php');
require_once($baseDir . '/sseProxy.php');

// Expected data files
$codexPath = $rootDir . '/assets/data/codex.json';

// Log missing file (non-fatal)
if (!file_exists($codexPath)) {
    error_log("⚠️ codex.json missing at $codexPath");
}
#endregion

#region 🧠 SEMANTIC ROUTER FALLBACK
if (!function_exists('semanticRoute')) {
    function semanticRoute($input) {
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

#region 🧩 CODEX CONSULT FALLBACK
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
        // Deep lookup
        foreach ($codex as $sectionName => $sectionData) {
            if (is_array($sectionData) && isset($sectionData['title']) &&
                stripos($sectionData['title'], $target) !== false) {
                return $sectionData;
            }
        }
        return array();
    }
}
#endregion

#region 🧩 POLICY ENGINE FUNCTION
if (!function_exists('runPolicyEngine')) {
    function runPolicyEngine($userInput) {
        if (trim($userInput) === '') {
            return array(
                'success'  => false,
                'message'  => '❌ Empty or invalid query received.',
                'timestamp'=> date('c')
            );
        }

        $semantic = semanticRoute($userInput);
        $domain   = $semantic['domain'];
        $target   = $semantic['target'];
        $policy   = codexConsult($target);
        $policyLoaded = !empty($policy);

        return array(
            'success'     => true,
            'input'       => $userInput,
            'domain'      => $domain,
            'target'      => $target,
            'confidence'  => $semantic['confidence'],
            'codexFound'  => $policyLoaded,
            'policy'      => $policyLoaded ? $policy : null,
            'timestamp'   => date('c')
        );
    }
}
#endregion

#region 🌐 OPTIONAL DIRECT EXECUTION (for browser/GET mode)
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    header('Content-Type: application/json');
    $userInput = isset($_GET['q']) ? trim($_GET['q']) : '';
    $result = runPolicyEngine($userInput);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion