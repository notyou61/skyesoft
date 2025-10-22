<?php
// 📘 File: api/ai/policyEngine.php
// Purpose: Central governance layer for Skyebot™ prompt construction
// Compatible with PHP 5.6 (no null-coalescing operators)

#region 🔧 DEPENDENCY LOADING
$baseDir = __DIR__;

$semanticPath = $baseDir . '/semanticRouter.php';
$codexPath    = $baseDir . '/codexConsult.php';
$ssePath      = $baseDir . '/sSEProxy.php';

if (file_exists($semanticPath))  require_once($semanticPath);
else error_log("⚠️ semanticRouter.php missing at $semanticPath");

if (file_exists($codexPath))     require_once($codexPath);
else error_log("⚠️ codexConsult.php missing at $codexPath");

if (file_exists($ssePath))       require_once($ssePath);
else error_log("⚠️ sSEProxy.php missing at $ssePath");
#endregion


#region 🧠 POLICY ENGINE CORE
function runPolicyEngine($userInput) {

    // 1️⃣ Identify domain (temporal, permit, finance, etc.)
    $domain = detectDomain($userInput);

    // 2️⃣ Load Codex context for this domain
    $codexModule = codexConsult($domain);

    // 3️⃣ Load live SSE context when relevant
    $sseContext = null;
    if (in_array($domain, array('temporal','operational'))) {
        $sseContext = fetchSSESnapshot();
    }

    // 4️⃣ Apply Codex governance hierarchy
    $priority = array('legal','temporal','operational','inference');
    $resolvedTier = 'inference';
    foreach ($priority as $tier) {
        if (codexHasRule($codexModule, $tier)) {
            $resolvedTier = $tier;
            break;
        }
    }

    // 5️⃣ Compose governed context
    $policyFrame = array(
        'domain'      => $domain,
        'tier'        => $resolvedTier,
        'codexModule' => isset($codexModule['title']) ? $codexModule['title'] : 'unknown',
        'rules'       => isset($codexModule['rules']) ? $codexModule['rules'] : array(),
        'context'     => $sseContext
    );

    // 6️⃣ Build governed AI prompt
    $prompt = buildGovernedPrompt($userInput, $policyFrame);

    // 7️⃣ Log audit event
    logPolicyEvent($policyFrame, $userInput);

    // 8️⃣ Return prompt to Skyebot
    return $prompt;
}
#endregion


#region 🪶 FALLBACK STUBS (if dependencies not yet implemented)
if (!function_exists('detectDomain')) {
    function detectDomain($input) {
        $map = array(
            'time' => 'temporal',
            'permit' => 'permit',
            'finance' => 'finance',
            'weather' => 'temporal',
            'workday' => 'temporal'
        );
        foreach ($map as $kw => $dmn) {
            if (stripos($input, $kw) !== false) return $dmn;
        }
        return 'inference';
    }
}

if (!function_exists('codexHasRule')) {
    function codexHasRule($module, $tier) {
        return isset($module['rules']) && is_array($module['rules']) && isset($module['rules'][$tier]);
    }
}

if (!function_exists('buildGovernedPrompt')) {
    function buildGovernedPrompt($input, $frame) {
        return "Based on the {$frame['domain']} domain and {$frame['tier']} tier, interpret the following request:\n\n{$input}";
    }
}

if (!function_exists('logPolicyEvent')) {
    function logPolicyEvent($frame, $input) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $logFile = $logDir . '/policy_audit.log';
        $entry = "[" . date('Y-m-d H:i:s') . "] Domain: {$frame['domain']} | Tier: {$frame['tier']} | Input: " . substr($input, 0, 120) . "\n";
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
#endregion