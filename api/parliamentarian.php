<?php
// ===============================================================
//  Skyebot™ Parliamentarian Engine – PHP 5.6 Compatible
//  Version: 1.0.0
//  Purpose: Enforce Codex Constitution Articles 1–12
//           (Mode C: Block + Suggest Amendment)
// ===============================================================

/**
 * Parliamentarian Review
 * -----------------------
 * Evaluates a proposed action against the Constitution.
 * Returns a structured ruling:
 *
 *  [allowed] => bool
 *  [article] => violated article (if any)
 *  [reason]  => explanation
 *  [next]    => next steps (Amendment suggestion or approval note)
 */
function parliamentarian_review($action, $codex)
{
    $ruling = array(
        'allowed' => true,
        'article' => null,
        'reason'  => 'Action permitted under current Codex.',
        'next'    => 'Proceed normally.'
    );

    // ================================================
    // 🛑 ARTICLE I — Human Authority
    // ================================================
    if (isset($action['attemptsLegislation']) && $action['attemptsLegislation'] === true) {
        return array(
            'allowed' => false,
            'article' => 'I',
            'reason'  => 'AI may not legislate or create binding rules.',
            'next'    => 'Propose an Amendment under Article X.'
        );
    }

    // ================================================
    // 🛑 ARTICLE II — Codex as Law
    // ================================================
    if (!empty($action['overridesCodex']) && $action['overridesCodex'] === true) {
        return array(
            'allowed' => false,
            'article' => 'II',
            'reason'  => 'Action conflicts with existing Codex doctrine.',
            'next'    => 'Propose an Amendment to modify doctrine.'
        );
    }

    // ================================================
    // 🛑 ARTICLE VII — Real-Time Data Doctrine
    // ================================================
    if (isset($action['requiresLiveTime']) && $action['requiresLiveTime'] === false) {
        return array(
            'allowed' => false,
            'article' => 'VII',
            'reason'  => 'Live time/weather/state must come from SSE; simulated or invented values are not allowed.',
            'next'    => 'Use SSE stream or propose an Amendment for alternate sources.'
        );
    }

    // ================================================
    // 🛑 ARTICLE VIII — No Shadow Rules
    // ================================================
    if (!empty($action['createsShadowRule']) && $action['createsShadowRule'] === true) {
        return array(
            'allowed' => false,
            'article' => 'VIII',
            'reason'  => 'Policies cannot exist outside the Codex.',
            'next'    => 'Submit proposal to Codex and follow Article X.'
        );
    }

    // ================================================
    // 🛑 ARTICLE XI — Limits of Automation
    // ================================================
    if (isset($action['automatesDoctrine']) && $action['automatesDoctrine'] === true) {
        return array(
            'allowed' => false,
            'article' => 'XI',
            'reason'  => 'Automation cannot modify doctrine or enforce new rules.',
            'next'    => 'Convert desired behavior into a formal Amendment proposal.'
        );
    }

    // ================================================
    // If no violations
    // ================================================
    return $ruling;
}