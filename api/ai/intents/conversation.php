<?php
// 📘 File: api/ai/intents/conversation.php
// Purpose: Handle conversation-level semantic inference for Skyebot
// Version: v2.3 (Array-safe normalization, 2025-10-23)
// ================================================================

if (!function_exists('analyzeConversationIntent')) {
    function analyzeConversationIntent($input)
    {
        // 🩹 Normalize input to string
        if (is_array($input)) {
            $input = implode(' ', $input);
        }
        $input = trim((string)$input);

        // ✅ Default fallback intent
        $default = array(
            'intent'      => 'general',
            'confidence'  => 0.5,
            'description' => 'General chat or small-talk detected.'
        );

        if ($input === '') {
            return $default;
        }

        $inputLower = strtolower($input);

        // ============================================================
        // 💬 Conversation Intent Detection Rules
        // ============================================================

        // Greeting
        if (strpos($inputLower, 'hello') !== false ||
            strpos($inputLower, 'hi ') !== false ||
            strpos($inputLower, 'hey') !== false) {
            return array(
                'intent'      => 'greeting',
                'confidence'  => 0.9,
                'description' => 'User initiated a greeting.'
            );
        }

        // Farewell
        if (strpos($inputLower, 'bye') !== false ||
            strpos($inputLower, 'goodnight') !== false ||
            strpos($inputLower, 'see you') !== false) {
            return array(
                'intent'      => 'farewell',
                'confidence'  => 0.9,
                'description' => 'User is closing the conversation.'
            );
        }

        // Inquiry
        if (strpos($inputLower, 'how are you') !== false ||
            strpos($inputLower, 'what are you doing') !== false) {
            return array(
                'intent'      => 'inquiry',
                'confidence'  => 0.85,
                'description' => 'User asked about Skyebot’s state or condition.'
            );
        }

        // Work context
        if (strpos($inputLower, 'project') !== false ||
            strpos($inputLower, 'permit') !== false ||
            strpos($inputLower, 'sign') !== false ||
            strpos($inputLower, 'task') !== false ||
            strpos($inputLower, 'deadline') !== false) {
            return array(
                'intent'      => 'work_context',
                'confidence'  => 0.9,
                'description' => 'Work-related inquiry or reference detected.'
            );
        }

        // Temporal inquiry
        if (strpos($inputLower, 'when') !== false ||
            strpos($inputLower, 'time') !== false ||
            strpos($inputLower, 'workday') !== false) {
            return array(
                'intent'      => 'temporal_query',
                'confidence'  => 0.9,
                'description' => 'User is asking about time or schedule.'
            );
        }

        // Gratitude
        if (strpos($inputLower, 'thank') !== false) {
            return array(
                'intent'      => 'gratitude',
                'confidence'  => 0.95,
                'description' => 'User expressed appreciation.'
            );
        }

        // Fallback
        return $default;
    }
}