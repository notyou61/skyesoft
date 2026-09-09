<?php
declare(strict_types=1);


// #region SECTION 1 — Project Configuration

$roadmapPath = __DIR__ . '/roadmap.json';
$codexPath   = __DIR__ . '/codex/codex.json';

$roadmap = null;


// Load roadmap
if (file_exists($roadmapPath)) {
    $roadmapJson = file_get_contents($roadmapPath);
    $roadmap = json_decode($roadmapJson, true);
}


// Resolve project state
$projectVersion = $roadmap['project']['version'] ?? '0.1.0';
$projectStatus  = $roadmap['project']['status'] ?? 'Development';
$nextAction     = $roadmap['nextAction']['task'] ?? 'Build read-only Kalshi market API layer';

$currentPhase = (int) ($roadmap['currentState']['currentPhase'] ?? 1);

$currentStrategyVersion =
    $roadmap['currentState']['currentStrategyVersion']
    ?? $roadmap['strategyResearch']['currentStrategy']['version']
    ?? 'v0.1.0-observe';

$currentStrategyType =
    $roadmap['strategyResearch']['currentStrategy']['type']
    ?? 'observation';

$currentStrategyLabel =
    $currentStrategyType === 'observation'
        ? 'Observation only'
        : 'Baseline development';

$phases = is_array($roadmap['phases'] ?? null)
    ? $roadmap['phases']
    : [];

$authenticationVerified =
    ($roadmap['currentState']['kalshiAuthentication'] ?? null) === 'verified';

$codexAvailable   = file_exists($codexPath);
$roadmapAvailable = file_exists($roadmapPath);

// #endregion


// #region SECTION 2 — Roadmap Display Helpers

function resolvePhaseClass(string $status): string
{
    return match ($status) {
        'complete', 'completed' => 'complete',
        'next', 'current', 'in_progress', 'in progress' => 'active',
        'blocked' => 'blocked',
        default => ''
    };
}


function resolvePhaseStatusLabel(array $phase): string
{
    $status = strtolower((string) ($phase['status'] ?? 'planned'));

    if ($status === 'next' || $status === 'current' || $status === 'in_progress' || $status === 'in progress') {
        return 'Current development';
    }

    if ($status === 'blocked') {
        return 'Blocked';
    }

    if ($status === 'complete' || $status === 'completed') {
        return 'Complete';
    }

    if (
        (int) ($phase['phase'] ?? 0) === 6
        && isset($phase['minimumInitialSampleMarkets'])
    ) {
        return number_format((int) $phase['minimumInitialSampleMarkets']) . '-market initial sample';
    }

    return 'Planned';
}

// #endregion

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Kalshi BTC Lab | Skyesoft</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
    :root {
        --lab-bg: #f4f6f9;
        --lab-navy: #172033;
        --lab-muted: #6c757d;
        --lab-border: #e2e6ea;
        --lab-green: #198754;
    }

    body {
        background: var(--lab-bg);
        color: #212529;
        font-family: Arial, Helvetica, sans-serif;
    }

    .lab-navbar {
        background: var(--lab-navy);
        box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
    }

    .lab-navbar .navbar-brand {
        font-weight: 700;
        letter-spacing: .02em;
    }

    .lab-subtitle {
        color: rgba(255, 255, 255, .65);
        font-size: .78rem;
    }

    .lab-mode {
        background: rgba(25, 135, 84, .18);
        border: 1px solid rgba(117, 224, 165, .45);
        color: #a9e7c5;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .08em;
    }

    .lab-nav-link {
        color: rgba(255, 255, 255, .72);
        font-size: .76rem;
        font-weight: 600;
        text-decoration: none;
    }

    .lab-nav-link:hover,
    .lab-nav-link:focus {
        color: #fff;
        text-decoration: underline;
    }

    .lab-card {
        border: 1px solid var(--lab-border);
        border-radius: .75rem;
        box-shadow: 0 2px 7px rgba(0, 0, 0, .035);
    }

    .lab-card .card-header {
        background: #fff;
        border-bottom: 1px solid var(--lab-border);
        padding: .9rem 1.1rem;
        font-size: .78rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .metric-label {
        color: var(--lab-muted);
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .035em;
        text-transform: uppercase;
    }

    .metric-value {
        color: var(--lab-navy);
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.15;
    }

    .metric-small {
        font-size: 1rem;
    }

    .status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        margin-right: 6px;
        border-radius: 50%;
        background: var(--lab-green);
    }

    .market-price {
        font-size: 2rem;
        font-weight: 700;
        color: var(--lab-navy);
    }

    .market-side {
        border: 1px solid var(--lab-border);
        border-radius: .6rem;
        padding: .8rem 1rem;
        background: #fafbfc;
    }

    .market-side strong {
        font-size: 1.4rem;
    }

    .phase-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .62rem 0;
        border-bottom: 1px solid #edf0f2;
    }

    .phase-row:last-child {
        border-bottom: 0;
    }

    .phase-number {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        flex: 0 0 28px;
        border-radius: 50%;
        background: #e9ecef;
        color: #495057;
        font-size: .72rem;
        font-weight: 700;
    }

    .phase-number.complete {
        background: #d1e7dd;
        color: #146c43;
    }

    .phase-number.active {
        background: #cfe2ff;
        color: #084298;
    }

    .phase-number.blocked {
        background: #f8d7da;
        color: #842029;
    }

    .next-action {
        border-left: 4px solid #0d6efd;
        background: #f8fbff;
    }

    .footer {
        color: #8a929a;
        font-size: .72rem;
    }

    @media (max-width: 767.98px) {
        .market-price {
            font-size: 1.6rem;
        }
    }
</style>
</head>

<body>


<!-- Navigation -->
<nav class="navbar navbar-dark lab-navbar">
    <div class="container-xl py-1">

        <div>
            <div class="navbar-brand mb-0">
                Kalshi BTC Lab
            </div>

            <div class="lab-subtitle">
                15-Minute Market Research &amp; Paper Trading
            </div>
        </div>

        <div class="d-flex align-items-center gap-3 flex-wrap justify-content-end">

            <?php if ($codexAvailable): ?>
                <a
                    class="lab-nav-link"
                    href="codex/codex.json"
                    target="_blank"
                    rel="noopener"
                >
                    Codex
                </a>
            <?php endif; ?>

            <?php if ($roadmapAvailable): ?>
                <a
                    class="lab-nav-link"
                    href="roadmap.json"
                    target="_blank"
                    rel="noopener"
                >
                    Roadmap
                </a>
            <?php endif; ?>

            <span class="badge rounded-pill lab-mode px-3 py-2">
                PAPER ONLY
            </span>

            <span class="text-white-50 small">
                v<?= htmlspecialchars($projectVersion) ?>
            </span>

        </div>

    </div>
</nav>


<main class="container-xl py-4">


    <!-- Status -->
    <div class="row g-3 mb-3">

        <div class="col-6 col-lg-3">
            <div class="card lab-card h-100">
                <div class="card-body">

                    <div class="metric-label mb-2">
                        Kalshi API
                    </div>

                    <div class="metric-value metric-small">
                        <span class="status-dot"></span>
                        Connected
                    </div>

                    <div class="small text-muted mt-1">
                        Production
                    </div>

                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="card lab-card h-100">
                <div class="card-body">

                    <div class="metric-label mb-2">
                        Trading Mode
                    </div>

                    <div class="metric-value metric-small">
                        Paper
                    </div>

                    <div class="small text-muted mt-1">
                        Live orders disabled
                    </div>

                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="card lab-card h-100">
                <div class="card-body">

                    <div class="metric-label mb-2">
                        Strategy
                    </div>

                    <div class="metric-value metric-small">
                        <?= htmlspecialchars($currentStrategyVersion) ?>
                    </div>

                    <div class="small text-muted mt-1">
                        <?= htmlspecialchars($currentStrategyLabel) ?>
                    </div>

                </div>
            </div>
        </div>


        <div class="col-6 col-lg-3">
            <div class="card lab-card h-100">
                <div class="card-body">

                    <div class="metric-label mb-2">
                        Data Collection
                    </div>

                    <div class="metric-value metric-small">
                        Not Started
                    </div>

                    <div class="small text-muted mt-1">
                        Phase 1
                    </div>

                </div>
            </div>
        </div>

    </div>


    <div class="row g-3">


        <!-- Current Market -->
        <div class="col-lg-8">

            <div class="card lab-card mb-3">

                <div class="card-header d-flex justify-content-between align-items-center">

                    <span>Current BTC 15-Minute Market</span>

                    <span class="badge text-bg-light border">
                        Waiting for API
                    </span>

                </div>

                <div class="card-body p-4">

                    <div class="row align-items-end mb-4">

                        <div class="col-md-7">

                            <div class="metric-label mb-2">
                                Price to Beat
                            </div>

                            <div class="market-price">
                                —
                            </div>

                            <div class="text-muted small mt-1">
                                Market data has not yet been loaded.
                            </div>

                        </div>

                        <div class="col-md-5 mt-3 mt-md-0">

                            <div class="metric-label mb-2">
                                Time Remaining
                            </div>

                            <div class="fs-4 fw-bold text-dark">
                                --:--
                            </div>

                        </div>

                    </div>


                    <div class="row g-3 mb-4">

                        <div class="col-sm-6">
                            <div class="market-side">

                                <div class="metric-label mb-1">
                                    YES
                                </div>

                                <strong>—</strong>

                                <div class="small text-muted">
                                    Ask price
                                </div>

                            </div>
                        </div>


                        <div class="col-sm-6">
                            <div class="market-side">

                                <div class="metric-label mb-1">
                                    NO
                                </div>

                                <strong>—</strong>

                                <div class="small text-muted">
                                    Ask price
                                </div>

                            </div>
                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn btn-primary px-4"
                        disabled
                    >
                        Find Current Market
                    </button>

                    <span class="small text-muted ms-2">
                        Enabled during Phase 1.
                    </span>

                </div>
            </div>


            <!-- Paper Test -->
            <div class="card lab-card">

                <div class="card-header">
                    Paper Test Performance
                </div>

                <div class="card-body">

                    <div class="row g-4">

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Markets
                            </div>
                            <div class="metric-value">0</div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Paper Trades
                            </div>
                            <div class="metric-value">0</div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Passes
                            </div>
                            <div class="metric-value">0</div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Win Rate
                            </div>
                            <div class="metric-value">—</div>
                        </div>

                    </div>

                    <hr>

                    <div class="row g-4">

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Net P/L
                            </div>
                            <div class="metric-value">$0.00</div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Max Drawdown
                            </div>
                            <div class="metric-value">$0.00</div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Strategy
                            </div>
                            <div class="metric-value metric-small">
                                <?= htmlspecialchars($currentStrategyVersion) ?>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="metric-label mb-1">
                                Target Sample
                            </div>
                            <div class="metric-value">
                                500
                            </div>
                        </div>

                    </div>

                </div>
            </div>

        </div>


        <!-- Roadmap -->
        <div class="col-lg-4">

            <div class="card lab-card mb-3">

                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Development Roadmap</span>

                    <?php if ($roadmapAvailable): ?>
                        <a
                            class="small text-decoration-none text-capitalize"
                            href="roadmap.json"
                            target="_blank"
                            rel="noopener"
                        >
                            View Roadmap
                        </a>
                    <?php endif; ?>
                </div>

                <div class="card-body py-2">

                    <div class="phase-row">
                        <div class="phase-number <?= $authenticationVerified ? 'complete' : '' ?>">
                            <?= $authenticationVerified ? '✓' : 'A' ?>
                        </div>
                        <div>
                            <div class="fw-semibold small">
                                Authentication
                            </div>
                            <div class="text-muted small">
                                <?= $authenticationVerified
                                    ? 'GoDaddy → Kalshi verified'
                                    : 'Pending verification' ?>
                            </div>
                        </div>
                    </div>

                    <?php foreach ($phases as $phase): ?>
                        <?php
                            $phaseNumber = (int) ($phase['phase'] ?? 0);
                            $phaseName = (string) ($phase['name'] ?? ('Phase ' . $phaseNumber));
                            $phaseStatus = (string) ($phase['status'] ?? 'planned');
                            $phaseClass = resolvePhaseClass(strtolower($phaseStatus));
                            $phaseStatusLabel = resolvePhaseStatusLabel($phase);
                        ?>

                        <div class="phase-row">
                            <div class="phase-number <?= htmlspecialchars($phaseClass) ?>">
                                <?= htmlspecialchars((string) $phaseNumber) ?>
                            </div>

                            <div>
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($phaseName) ?>
                                </div>

                                <div class="text-muted small">
                                    <?= htmlspecialchars($phaseStatusLabel) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>


            <!-- Next Action -->
            <div class="card lab-card next-action">

                <div class="card-body">

                    <div class="metric-label text-primary mb-2">
                        Next Action
                    </div>

                    <div class="small fw-semibold">
                        <?= htmlspecialchars($nextAction) ?>
                    </div>

                </div>
            </div>

        </div>

    </div>


    <!-- Footer -->
    <div class="footer d-flex justify-content-between mt-4 pb-3">

        <span>
            Skyesoft · Kalshi BTC Lab
        </span>

        <span>
            <?php if ($codexAvailable): ?>
                <a
                    class="text-reset text-decoration-none me-3"
                    href="codex/codex.json"
                    target="_blank"
                    rel="noopener"
                >
                    Codex
                </a>
            <?php endif; ?>

            <?php if ($roadmapAvailable): ?>
                <a
                    class="text-reset text-decoration-none me-3"
                    href="roadmap.json"
                    target="_blank"
                    rel="noopener"
                >
                    Roadmap
                </a>
            <?php endif; ?>

            Research Environment · Live Trading Disabled
        </span>

    </div>


</main>

</body>
</html>