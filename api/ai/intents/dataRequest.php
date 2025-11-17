<?php
// 📄 File: api/ai/intents/dataRequest.php
// Purpose: Retrieve or describe structured operational data

function handle_dataRequest($prompt, $codex, $sse) {
    $records = isset($sse['recordCounts']) ? $sse['recordCounts'] : array();
    $orders  = isset($records['orders']) ? $records['orders'] : 0;
    $permits = isset($records['permits']) ? $records['permits'] : 0;

    if (stripos($prompt, 'permit') !== false)
        return "Currently there are {$permits} permits logged in the system.";
    if (stripos($prompt, 'order') !== false)
        return "Skyesoft is tracking {$orders} open orders.";
    return "I can retrieve live project or permit counts if you specify which dataset you want.";
}
?>
