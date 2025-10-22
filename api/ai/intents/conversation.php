<?php
// 📄 File: api/ai/intents/conversation.php
// Purpose: Light conversational responses

function handle_conversation($prompt, $codex, $sse) {
    $time = isset($sse['timeDateArray']['currentLocalTime'])
        ? $sse['timeDateArray']['currentLocalTime'] : '';
    if (stripos($prompt, 'hello') !== false || stripos($prompt, 'hi') !== false)
        return "Hello! It’s {$time} — how can I help you today?";
    if (stripos($prompt, 'thank') !== false)
        return "You’re welcome! Always glad to assist.";
    return "I’m here and ready. What would you like to do next?";
}