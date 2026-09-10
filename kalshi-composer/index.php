<?php
declare(strict_types=1);


// #region SECTION 1 — Project Configuration

$roadmapPath = __DIR__ . '/roadmap.json';
$codexPath   = __DIR__ . '/codex/codex.json';


function loadJsonObject(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);

    if ($json === false) {
        return null;
    }

    $decoded = json_decode($json, true);

    return is_array($decoded)
        ? $decoded
        : null;
}


$roadmap = loadJsonObject($roadmapPath);
$codex   = loadJsonObject($codexPath);


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

$codexAvailable   = $codex !== null;
$roadmapAvailable = $roadmap !== null;

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

    if (in_array($status, ['next', 'current', 'in_progress', 'in progress'], true)) {
        return 'Current development';
    }

    if ($status === 'blocked') {
        return 'Blocked';
    }

    if (in_array($status, ['complete', 'completed'], true)) {
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


// #region SECTION 3 — Modal Document Pages

function makeDisplayTitle(string $key): string
{
    $title = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
    $title = str_replace(['_', '-'], ' ', (string) $title);

    return ucwords(trim((string) $title));
}


function buildCodexPages(?array $codex): array
{
    if ($codex === null) {
        return [];
    }

    $pages = [];

    foreach ($codex as $key => $value) {
        $pages[] = [
            'title' => makeDisplayTitle((string) $key),
            'key'   => (string) $key,
            'data'  => $value
        ];
    }

    return $pages;
}


function buildRoadmapPages(?array $roadmap): array
{
    if ($roadmap === null) {
        return [];
    }

    $pages = [];

    foreach ($roadmap as $key => $value) {
        if ($key === 'phases' && is_array($value)) {
            foreach ($value as $phase) {
                if (!is_array($phase)) {
                    continue;
                }

                $phaseNumber = (string) ($phase['phase'] ?? '');
                $phaseName   = (string) ($phase['name'] ?? 'Roadmap Phase');

                $pages[] = [
                    'title' => trim('Phase ' . $phaseNumber . ' — ' . $phaseName),
                    'key'   => 'phase_' . $phaseNumber,
                    'data'  => $phase
                ];
            }

            continue;
        }

        $pages[] = [
            'title' => makeDisplayTitle((string) $key),
            'key'   => (string) $key,
            'data'  => $value
        ];
    }

    return $pages;
}


$codexPages   = buildCodexPages($codex);
$roadmapPages = buildRoadmapPages($roadmap);

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

    .document-link {
        border: 0;
        background: transparent;
        padding: 0;
        color: rgba(255, 255, 255, .72);
        font-size: .76rem;
        font-weight: 600;
        text-decoration: none;
    }

    .document-link:hover,
    .document-link:focus {
        color: #fff;
        text-decoration: underline;
    }

    .document-modal .modal-dialog {
        max-width: 980px;
    }

    .document-modal .modal-content {
        border: 0;
        border-radius: .9rem;
        box-shadow: 0 16px 48px rgba(0, 0, 0, .22);
        overflow: hidden;
    }

    .document-modal .modal-header {
        background: var(--lab-navy);
        color: #fff;
        border-bottom: 0;
        padding: 1rem 1.25rem;
    }

    .document-modal .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
    }

    .document-modal .modal-body {
        background: #f7f9fc;
        padding: 1.25rem;
        min-height: 520px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .document-modal .modal-footer {
        background: #fff;
        border-top: 1px solid var(--lab-border);
        padding: .8rem 1.25rem;
    }

    .document-page-title {
        color: var(--lab-navy);
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .document-section {
        background: #fff;
        border: 1px solid var(--lab-border);
        border-radius: .65rem;
        padding: 1rem;
        margin-bottom: .85rem;
    }

    .document-field {
        padding: .65rem 0;
        border-bottom: 1px solid #edf0f2;
    }

    .document-field:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .document-key {
        color: var(--lab-muted);
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .035em;
        text-transform: uppercase;
        margin-bottom: .2rem;
    }

    .document-value {
        color: #212529;
        font-size: .9rem;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .document-array {
        margin: .25rem 0 0;
        padding-left: 1.2rem;
    }

    .document-array li {
        margin-bottom: .35rem;
    }

    .document-object {
        border-left: 3px solid #dfe6ef;
        margin-top: .45rem;
        padding-left: .9rem;
    }

    .document-page-count {
        color: var(--lab-muted);
        font-size: .78rem;
        white-space: nowrap;
    }

    .roadmap-view-link {
        border: 0;
        background: transparent;
        padding: 0;
        color: #0d6efd;
        font-size: .74rem;
        font-weight: 600;
        text-decoration: none;
        text-transform: none;
        letter-spacing: 0;
    }

    .roadmap-view-link:hover,
    .roadmap-view-link:focus {
        text-decoration: underline;
    }

    /* #region SECTION — Live Market Modal */

    .live-market-modal .modal-dialog {
        max-width: 1200px;
    }

    .live-market-modal .modal-content {
        height: calc(100vh - 2rem);
        min-height: 640px;
        border: 0;
        border-radius: .75rem;
        overflow: hidden;
    }

    .live-market-modal .modal-header {
        padding: .7rem 1rem;
        border-bottom: 1px solid var(--lab-border);
    }

    .live-market-modal .modal-title {
        color: var(--lab-navy);
        font-size: .95rem;
        font-weight: 700;
    }

    .live-market-modal .modal-body {
        min-height: 0;
    }

    .live-market-frame {
        width: 100%;
        height: 100%;
        border: 0;
        background: #fff;
    }

    /* #endregion */


    @media (max-width: 767.98px) {
        .market-price {
            font-size: 1.6rem;
        }

        .live-market-modal .modal-dialog {
            max-width: none;
            margin: .5rem;
        }

        .live-market-modal .modal-content {
            height: calc(100vh - 1rem);
            min-height: 0;
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
                <button
                    type="button"
                    class="document-link"
                    data-bs-toggle="modal"
                    data-bs-target="#codexModal"
                >
                    Codex
                </button>
            <?php endif; ?>

            <?php if ($roadmapAvailable): ?>
                <button
                    type="button"
                    class="document-link"
                    data-bs-toggle="modal"
                    data-bs-target="#roadmapModal"
                >
                    Roadmap
                </button>
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
                        data-bs-toggle="modal"
                        data-bs-target="#liveMarketModal"
                    >
                        View Live BTC Market
                    </button>

                    <span class="small text-muted ms-2">
                        Live read-only Kalshi + BRTI monitor.
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
                        <button
                            type="button"
                            class="roadmap-view-link"
                            data-bs-toggle="modal"
                            data-bs-target="#roadmapModal"
                        >
                            View Roadmap
                        </button>
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
                <button
                    type="button"
                    class="btn btn-link text-reset text-decoration-none p-0 me-3 footer"
                    data-bs-toggle="modal"
                    data-bs-target="#codexModal"
                >
                    Codex
                </button>
            <?php endif; ?>

            <?php if ($roadmapAvailable): ?>
                <button
                    type="button"
                    class="btn btn-link text-reset text-decoration-none p-0 me-3 footer"
                    data-bs-toggle="modal"
                    data-bs-target="#roadmapModal"
                >
                    Roadmap
                </button>
            <?php endif; ?>

            Research Environment · Live Trading Disabled
        </span>

    </div>


</main>



<!-- #region SECTION 4 — Live Market Modal -->

<div
    class="modal fade live-market-modal"
    id="liveMarketModal"
    tabindex="-1"
    aria-labelledby="liveMarketModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <div
                        class="modal-title"
                        id="liveMarketModalLabel"
                    >
                        Kalshi BTC Lab — Live BTC 15-Minute Market
                    </div>

                    <div class="small text-muted">
                        Read-only observational monitor
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body p-0">
                <iframe
                    id="liveMarketFrame"
                    class="live-market-frame"
                    title="Kalshi BTC Live Market"
                    src="about:blank"
                ></iframe>
            </div>

        </div>
    </div>
</div>

<!-- #endregion -->



<!-- #region SECTION 5 — Codex & Roadmap Modals -->

<?php if ($codexAvailable): ?>
<div
    class="modal fade document-modal"
    id="codexModal"
    tabindex="-1"
    aria-labelledby="codexModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 mb-0" id="codexModalLabel">
                        Prediction Market Lab Codex
                    </h2>
                    <div class="small text-white-50 mt-1">
                        Governance · v<?= htmlspecialchars((string) ($codex['meta']['version'] ?? '0.1.0')) ?>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body">
                <div
                    id="codexDocumentBody"
                    data-document-viewer="codex"
                ></div>
            </div>

            <div class="modal-footer d-flex justify-content-between">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-document-prev="codex"
                >
                    ← Previous
                </button>

                <div
                    class="document-page-count"
                    data-document-count="codex"
                ></div>

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-document-next="codex"
                >
                    Next →
                </button>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>


<?php if ($roadmapAvailable): ?>
<div
    class="modal fade document-modal"
    id="roadmapModal"
    tabindex="-1"
    aria-labelledby="roadmapModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5 mb-0" id="roadmapModalLabel">
                        Prediction Market Lab Roadmap
                    </h2>
                    <div class="small text-white-50 mt-1">
                        Development Plan · v<?= htmlspecialchars($projectVersion) ?>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body">
                <div
                    id="roadmapDocumentBody"
                    data-document-viewer="roadmap"
                ></div>
            </div>

            <div class="modal-footer d-flex justify-content-between">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-document-prev="roadmap"
                >
                    ← Previous
                </button>

                <div
                    class="document-page-count"
                    data-document-count="roadmap"
                ></div>

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-document-next="roadmap"
                >
                    Next →
                </button>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- #endregion -->


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
></script>

<script>
// #region SECTION 6 — Paginated Document Viewer

const documentViewerData = {
    codex: <?= json_encode(
        $codexPages,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?>,
    roadmap: <?= json_encode(
        $roadmapPages,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?>
};

const documentViewerState = {
    codex: 0,
    roadmap: 0
};


function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}


function humanizeKey(key) {
    return String(key)
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, character => character.toUpperCase());
}


function renderDocumentValue(value) {
    if (value === null) {
        return '<span class="text-muted">Not set</span>';
    }

    if (typeof value === 'boolean') {
        return value
            ? '<span class="badge text-bg-success">Yes</span>'
            : '<span class="badge text-bg-secondary">No</span>';
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '<span class="text-muted">None</span>';
        }

        const allScalar = value.every(
            item => item === null || typeof item !== 'object'
        );

        if (allScalar) {
            return `
                <ul class="document-array">
                    ${value.map(item => `<li>${renderDocumentValue(item)}</li>`).join('')}
                </ul>
            `;
        }

        return value.map((item, index) => `
            <div class="document-section">
                <div class="document-key">Item ${index + 1}</div>
                ${renderDocumentValue(item)}
            </div>
        `).join('');
    }

    if (typeof value === 'object') {
        return `
            <div class="document-object">
                ${Object.entries(value).map(([key, childValue]) => `
                    <div class="document-field">
                        <div class="document-key">
                            ${escapeHtml(humanizeKey(key))}
                        </div>
                        <div class="document-value">
                            ${renderDocumentValue(childValue)}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    return escapeHtml(value);
}


function renderDocumentPage(documentType) {
    const pages = documentViewerData[documentType] ?? [];

    if (pages.length === 0) {
        return;
    }

    const currentIndex = Math.max(
        0,
        Math.min(documentViewerState[documentType], pages.length - 1)
    );

    documentViewerState[documentType] = currentIndex;

    const page = pages[currentIndex];
    const body = document.querySelector(
        `[data-document-viewer="${documentType}"]`
    );
    const count = document.querySelector(
        `[data-document-count="${documentType}"]`
    );
    const previousButton = document.querySelector(
        `[data-document-prev="${documentType}"]`
    );
    const nextButton = document.querySelector(
        `[data-document-next="${documentType}"]`
    );

    if (!body || !count || !previousButton || !nextButton) {
        return;
    }

    body.innerHTML = `
        <div class="document-page-title">
            ${escapeHtml(page.title)}
        </div>

        <div class="document-section">
            ${renderDocumentValue(page.data)}
        </div>
    `;

    count.textContent = `Section ${currentIndex + 1} of ${pages.length}`;
    previousButton.disabled = currentIndex === 0;
    nextButton.disabled = currentIndex === pages.length - 1;

    const modalBody = body.closest('.modal-body');

    if (modalBody) {
        modalBody.scrollTop = 0;
    }
}


function changeDocumentPage(documentType, direction) {
    const pages = documentViewerData[documentType] ?? [];

    if (pages.length === 0) {
        return;
    }

    const nextIndex =
        documentViewerState[documentType] + direction;

    if (nextIndex < 0 || nextIndex >= pages.length) {
        return;
    }

    documentViewerState[documentType] = nextIndex;
    renderDocumentPage(documentType);
}


document.querySelectorAll('[data-document-prev]').forEach(button => {
    button.addEventListener('click', () => {
        changeDocumentPage(
            button.dataset.documentPrev,
            -1
        );
    });
});


document.querySelectorAll('[data-document-next]').forEach(button => {
    button.addEventListener('click', () => {
        changeDocumentPage(
            button.dataset.documentNext,
            1
        );
    });
});


['codex', 'roadmap'].forEach(documentType => {
    const modalElement = document.getElementById(`${documentType}Modal`);

    if (!modalElement) {
        return;
    }

    modalElement.addEventListener('show.bs.modal', () => {
        renderDocumentPage(documentType);
    });
});


document.addEventListener('keydown', event => {
    const openModal = document.querySelector('.document-modal.show');

    if (!openModal) {
        return;
    }

    const documentType =
        openModal.id === 'codexModal'
            ? 'codex'
            : 'roadmap';

    if (event.key === 'ArrowLeft') {
        changeDocumentPage(documentType, -1);
    }

    if (event.key === 'ArrowRight') {
        changeDocumentPage(documentType, 1);
    }
});

// #endregion
</script>


<script>
// #region SECTION 7 — Live Market Modal Controller

document.addEventListener(
    'DOMContentLoaded',
    () => {
        const modalElement =
            document.getElementById(
                'liveMarketModal'
            );

        const frame =
            document.getElementById(
                'liveMarketFrame'
            );

        if (!modalElement || !frame) {
            return;
        }

        modalElement.addEventListener(
            'shown.bs.modal',
            () => {
                frame.src =
                    'live_market.php?embed=1';
            }
        );

        modalElement.addEventListener(
            'hidden.bs.modal',
            () => {
                frame.src = 'about:blank';
            }
        );
    }
);

// #endregion
</script>

</body>
</html>