<?php
// 📘 File: api/PolicyEngine.php

require_once 'SemanticRouter.php';
require_once 'CodexConsult.php';
require_once 'SSEProxy.php';

function runPolicyEngine($userInput) {
    // 1️⃣ Identify domain
    $domain = detectDomain($userInput); // e.g., temporal, permit, finance

    // 2️⃣ Load Codex context for this domain
    $codexModule = codexConsult($domain);

    // 3️⃣ Load live context if needed (temporal, operational)
    $sseContext = null;
    if (in_array($domain, array('temporal','operational'))) {
        $sseContext = fetchSSESnapshot();
    }

    // 4️⃣ Apply Codex governance hierarchy
    $priority = array('legal','temporal','operational','inference');
    $resolvedTier = 'inference'; // default

    foreach ($priority as $tier) {
        if (codexHasRule($codexModule, $tier)) {
            $resolvedTier = $tier;
            break;
        }
    }

    // 5️⃣ Compose governed context
    $policyFrame = array(
        'domain' => $domain,
        'tier' => $resolvedTier,
        'codexModule' => $codexModule['title'],
        'rules' => $codexModule['rules'] ?? array(),
        'context' => $sseContext
    );

    // 6️⃣ Build AI prompt (for askOpenAI.php)
    $prompt = buildGovernedPrompt($userInput, $policyFrame);

    // 7️⃣ Log to audit ledger
    logPolicyEvent($policyFrame, $userInput);

    // 8️⃣ Return to caller (askOpenAI.php)
    return $prompt;
}