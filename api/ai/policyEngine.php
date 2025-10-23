<?php
// 📘 File: api/ai/policyEngine.php
// Purpose: Central governance layer for Skyebot™ prompt construction
// Framework: Phase 5 (SSE-aware, Codex-integrated)
// Compatible with PHP 5.6 (no null-coalescing operators)

error_reporting(E_ALL);
ini_set('display_errors', 1);

#region ⚙️ DEPENDENCY LOADING
$baseDir = dirname(__FILE__);
$rootDir = dirname(dirname(__DIR__));  // two levels up → /skyesoft

// 🔗 Include helper + logic modules
require_once($rootDir . '/api/helpers.php');
require_once($baseDir . '/semanticRouter.php');
require_once($baseDir . '/sseProxy.php');

// Define Codex path (only persistent data file required)
$codexPath = $rootDir . '/assets/data/codex.json';
if (!file_exists($codexPath)) {
    error_log("⚠️ codex.json missing at $codexPath");
}
#endregion


#region 🧠 SEMANTIC ROUTER (fallback)
if (!function_exists('semanticRoute')) {
    function semanticRoute($input)
    {
        $input = strtolower(trim($input));

        if (strpos($input, 'workday') !== false || strpos($input, 'time') !== false) {
            return array('domain' => 'temporal', 'target' => 'timeIntervalStandards', 'confidence' => 0.9);
        }
        if (strpos($input, 'weather') !== false) {
            return array('domain' => 'contextual', 'target' => 'weatherData', 'confidence' => 0.9);
        }
        if (strpos($input, 'policy') !== false || strpos($input, 'constitution') !== false) {
            return array('domain' => 'governance', 'target' => 'skyesoftConstitution', 'confidence' => 0.8);
        }

        // Default fallback
        return array('domain' => 'general', 'target' => 'codexOverview', 'confidence' => 0.5);
    }
}
#endregion


#region 📘 CODEX CONSULT
if (!function_exists('codexConsult')) {
    function codexConsult($target)
    {
        $codexPath = dirname(dirname(__DIR__)) . '/assets/data/codex.json';
        if (!file_exists($codexPath)) {
            error_log("⚠️ codex.json missing at $codexPath");
            return array();
        }

        $codex = json_decode(file_get_contents($codexPath), true);
        if (!is_array($codex)) {
            error_log("⚠️ Invalid codex.json format.");
            return array();
        }

        // Direct lookup
        if (isset($codex[$target])) {
            return $codex[$target];
        }

        // Deep lookup
        foreach ($codex as $sectionName => $sectionData) {
            if (is_array($sectionData)) {
                if ((isset($sectionData['title']) && stripos($sectionData['title'], $target) !== false)
                    || (isset($sectionData['id']) && stripos($sectionData['id'], $target) !== false)) {
                    return $sectionData;
                }
            }
        }
        return array();
    }
}
#endregion


#region 🧩 POLICY RESOLUTION

// ✅ Accept input from ?q= or from global inline bridge
$userInput = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($userInput === '' && isset($GLOBALS['policyQuery'])) {
    $userInput = trim($GLOBALS['policyQuery']);
}

// 🚨 Handle empty input after both checks
if ($userInput === '') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'message' => '❌ No query received.',
        '_get'     => $_GET,
        'globals'  => array_keys($GLOBALS), // optional diagnostic
        'uri'      => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'referrer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'none'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔍 Semantic routing
$semantic = semanticRoute($userInput);
$domain   = $semantic['domain'];
$target   = $semantic['target'];

// 🔎 Codex consultation
$policy = codexConsult($target);
$policyLoaded = !empty($policy);

// 📡 Compose structured reply (for askOpenAI.php)
header('Content-Type: application/json');
echo json_encode(array(
    'success'     => true,
    'input'       => $userInput,
    'domain'      => $domain,
    'target'      => $target,
    'confidence'  => $semantic['confidence'],
    'codexFound'  => $policyLoaded,
    'policy'      => $policyLoaded ? $policy : null,
    'timestamp'   => date('c')
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion