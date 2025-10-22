<?php
// 📄 File: api/ai/intents/default.php
// Purpose: Universal fallback when no other intent applies

function handle_default($prompt, $codex, $sse) {
    return "I couldn’t determine the exact intent of your message, but I’m ready to help. Try rephrasing or specifying a module like *permit*, *report*, or *time*.";
}