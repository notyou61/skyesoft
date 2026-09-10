<?php
declare(strict_types=1);


// ======================================================================
// Skyesoft — live_market.php
// Kalshi BTC Lab — Live BTC 15-Minute Market Monitor
//
// Purpose:
//  • Provide a live, read-only view of the current KXBTC15M market
//  • Reuse the authenticated KalshiClient already verified in production
//  • Refresh current market detail and order book every 5 seconds
//  • Display executable market prices and timing information
//
// Roadmap:
//  • Phase 1 — Read-Only Kalshi API Layer
//  • Supports later Phase 3 observation capture without writing data yet
//
// Safety:
//  • GET requests only
//  • No database writes
//  • No predictive decision
//  • No paper execution
//  • No live order capability
//
// ======================================================================


require_once __DIR__ . '/src/KalshiClient.php';


// #region SECTION 1 — Configuration

$seriesTicker = 'KXBTC15M';
$refreshMilliseconds = 5000;

// #endregion


// #region SECTION 2 — Helpers

function parseUnixTime(?string $isoTime): ?int
{
    if (!$isoTime) {
        return null;
    }

    $unix = strtotime($isoTime);

    return $unix === false ? null : $unix;
}


function findCurrentMarket(array $markets, int $nowUnix): ?array
{
    $candidates = [];

    foreach ($markets as $market) {
        if (!is_array($market)) {
            continue;
        }

        $openUnix  = parseUnixTime($market['open_time'] ?? null);
        $closeUnix = parseUnixTime($market['close_time'] ?? null);
        $status    = strtolower((string) ($market['status'] ?? ''));

        if ($openUnix === null || $closeUnix === null) {
            continue;
        }

        if (
            in_array($status, ['active', 'open'], true)
            && $openUnix <= $nowUnix
            && $closeUnix > $nowUnix
        ) {
            $candidates[] = [
                'market'    => $market,
                'closeUnix' => $closeUnix
            ];
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort(
        $candidates,
        static fn(array $a, array $b): int =>
            $a['closeUnix'] <=> $b['closeUnix']
    );

    return $candidates[0]['market'];
}


function getBestOrderBookBid(array $levels): ?array
{
    if ($levels === []) {
        return null;
    }

    $bestPrice = null;
    $bestSize  = null;

    foreach ($levels as $level) {
        if (!is_array($level) || count($level) < 2) {
            continue;
        }

        $price = (float) $level[0];
        $size  = (float) $level[1];

        if ($bestPrice === null || $price > $bestPrice) {
            $bestPrice = $price;
            $bestSize  = $size;
        }
    }

    if ($bestPrice === null) {
        return null;
    }

    return [
        'price' => $bestPrice,
        'size'  => $bestSize
    ];
}


function buildLiveSnapshot(KalshiClient $kalshi, string $seriesTicker): array
{
    $retrievedUnix = time();

    $marketsResponse = $kalshi->get(
        '/trade-api/v2/markets',
        [
            'series_ticker' => $seriesTicker,
            'status'        => 'open',
            'limit'         => 100,
            'mve_filter'    => 'exclude'
        ]
    );

    $markets = is_array($marketsResponse['markets'] ?? null)
        ? $marketsResponse['markets']
        : [];

    $currentMarket = findCurrentMarket(
        $markets,
        $retrievedUnix
    );

    if ($currentMarket === null) {
        return [
            'ok'                  => true,
            'retrievedUnix'       => $retrievedUnix,
            'seriesTicker'        => $seriesTicker,
            'currentMarketFound'  => false,
            'message'             => 'No active BTC 15-minute market window was found.'
        ];
    }

    $ticker = (string) ($currentMarket['ticker'] ?? '');

    if ($ticker === '') {
        throw new RuntimeException(
            'Current market did not contain a ticker.'
        );
    }

    $detailResponse = $kalshi->get(
        '/trade-api/v2/markets/' . rawurlencode($ticker)
    );

    $orderBookResponse = $kalshi->get(
        '/trade-api/v2/markets/'
        . rawurlencode($ticker)
        . '/orderbook'
    );

    $market = is_array($detailResponse['market'] ?? null)
        ? $detailResponse['market']
        : $currentMarket;

    $orderBook = is_array(
        $orderBookResponse['orderbook_fp'] ?? null
    )
        ? $orderBookResponse['orderbook_fp']
        : [];

    $yesLevels = is_array($orderBook['yes_dollars'] ?? null)
        ? $orderBook['yes_dollars']
        : [];

    $noLevels = is_array($orderBook['no_dollars'] ?? null)
        ? $orderBook['no_dollars']
        : [];

    $bestYesBid = getBestOrderBookBid($yesLevels);
    $bestNoBid  = getBestOrderBookBid($noLevels);

    $yesBid = isset($market['yes_bid_dollars'])
        ? (float) $market['yes_bid_dollars']
        : ($bestYesBid['price'] ?? null);

    $yesAsk = isset($market['yes_ask_dollars'])
        ? (float) $market['yes_ask_dollars']
        : (
            $bestNoBid !== null
                ? 1.0 - $bestNoBid['price']
                : null
        );

    $noBid = isset($market['no_bid_dollars'])
        ? (float) $market['no_bid_dollars']
        : ($bestNoBid['price'] ?? null);

    $noAsk = isset($market['no_ask_dollars'])
        ? (float) $market['no_ask_dollars']
        : (
            $bestYesBid !== null
                ? 1.0 - $bestYesBid['price']
                : null
        );

    $closeUnix = parseUnixTime(
        $market['close_time'] ?? null
    );

    $secondsRemaining = $closeUnix !== null
        ? max(0, $closeUnix - $retrievedUnix)
        : null;

    return [
        'ok'                 => true,
        'retrievedUnix'      => $retrievedUnix,
        'retrievedIsoUtc'    => gmdate('c', $retrievedUnix),
        'seriesTicker'       => $seriesTicker,
        'currentMarketFound' => true,
        'ticker'             => $ticker,
        'eventTicker'        => $market['event_ticker'] ?? null,
        'title'              => $market['title'] ?? null,
        'status'             => $market['status'] ?? null,
        'strike'             => isset($market['floor_strike'])
            ? (float) $market['floor_strike']
            : null,
        'strikeType'         => $market['strike_type'] ?? null,
        'openTime'           => $market['open_time'] ?? null,
        'closeTime'          => $market['close_time'] ?? null,
        'secondsRemaining'   => $secondsRemaining,
        'yesBid'             => $yesBid,
        'yesAsk'             => $yesAsk,
        'noBid'              => $noBid,
        'noAsk'              => $noAsk,
        'yesBidSize'         => $bestYesBid['size']
            ?? (
                isset($market['yes_bid_size_fp'])
                    ? (float) $market['yes_bid_size_fp']
                    : null
            ),
        'yesAskSize'         => isset($market['yes_ask_size_fp'])
            ? (float) $market['yes_ask_size_fp']
            : null,
        'noBidSize'          => $bestNoBid['size'] ?? null,
        'yesSpread'          => (
            $yesBid !== null && $yesAsk !== null
        )
            ? $yesAsk - $yesBid
            : null,
        'lastPrice'          => isset($market['last_price_dollars'])
            ? (float) $market['last_price_dollars']
            : null,
        'openInterest'       => isset($market['open_interest_fp'])
            ? (float) $market['open_interest_fp']
            : null,
        'volume'             => isset($market['volume_fp'])
            ? (float) $market['volume_fp']
            : null,
        'volume24h'          => isset($market['volume_24h_fp'])
            ? (float) $market['volume_24h_fp']
            : null,
        'result'             => $market['result'] ?? '',
        'expirationValue'    => $market['expiration_value'] ?? '',
        'rulesPrimary'       => $market['rules_primary'] ?? null
    ];
}

// #endregion


// #region SECTION 3 — JSON Endpoint

if (($_GET['mode'] ?? '') === 'data') {
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
    );

    try {
        $kalshi = new KalshiClient();

        echo json_encode(
            buildLiveSnapshot(
                $kalshi,
                $seriesTicker
            ),
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

    } catch (Throwable $e) {
        http_response_code(500);

        echo json_encode(
            [
                'ok'      => false,
                'message' => $e->getMessage()
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
        );
    }

    exit;
}

// #endregion


// #region SECTION 4 — Page
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<title>Kalshi BTC Lab — Live Market</title>

<style>
:root{
    --bg:#f5f7fa;
    --panel:#ffffff;
    --border:#d9dee7;
    --text:#182230;
    --muted:#687386;
    --green:#16794f;
    --red:#a33a3a;
    --blue:#225ea8;
    --shadow:0 3px 12px rgba(0,0,0,.06);
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial, Helvetica, sans-serif;
}

.shell{
    max-width:1180px;
    margin:0 auto;
    padding:22px;
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:18px;
}

.eyebrow{
    color:var(--muted);
    font-size:12px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:4px;
}

h1{
    margin:0;
    font-size:28px;
}

.subtitle{
    margin-top:5px;
    color:var(--muted);
    font-size:14px;
}

.badges{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.badge{
    padding:7px 10px;
    border-radius:999px;
    border:1px solid var(--border);
    background:#fff;
    font-size:12px;
    font-weight:700;
}

.badge.safe{
    color:var(--green);
}

.market-card{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:12px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:18px;
}

.market-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
}

.market-title{
    font-size:22px;
    font-weight:700;
    margin-bottom:4px;
}

.ticker{
    color:var(--muted);
    font-size:13px;
    word-break:break-all;
}

.timer-wrap{
    text-align:right;
}

.timer-label{
    color:var(--muted);
    font-size:12px;
    text-transform:uppercase;
    font-weight:700;
}

.timer{
    font-size:30px;
    font-weight:700;
    font-variant-numeric:tabular-nums;
    margin-top:2px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:14px;
    margin-top:18px;
}

.stat{
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px;
    background:#fbfcfe;
}

.stat-label{
    color:var(--muted);
    font-size:11px;
    text-transform:uppercase;
    font-weight:700;
    letter-spacing:.04em;
}

.stat-value{
    font-size:22px;
    font-weight:700;
    margin-top:5px;
    font-variant-numeric:tabular-nums;
}

.quote-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
    margin-top:18px;
}

.quote{
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px;
}

.quote-title{
    font-size:15px;
    font-weight:700;
    margin-bottom:12px;
}

.quote-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}

.price-box{
    border-radius:9px;
    padding:12px;
    background:#f7f9fc;
}

.price-label{
    color:var(--muted);
    font-size:11px;
    text-transform:uppercase;
    font-weight:700;
}

.price{
    font-size:28px;
    font-weight:700;
    margin-top:3px;
    font-variant-numeric:tabular-nums;
}

.yes .price{
    color:var(--green);
}

.no .price{
    color:var(--red);
}

.footer-panel{
    background:var(--panel);
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px 16px;
    color:var(--muted);
    font-size:13px;
    line-height:1.45;
}

.status-line{
    display:flex;
    justify-content:space-between;
    gap:14px;
    margin-bottom:8px;
}

.status-dot{
    display:inline-block;
    width:8px;
    height:8px;
    border-radius:50%;
    background:var(--green);
    margin-right:6px;
}

.error{
    color:var(--red);
    font-weight:700;
}

@media (max-width:850px){
    .grid{
        grid-template-columns:repeat(2, 1fr);
    }

    .quote-grid{
        grid-template-columns:1fr;
    }
}

@media (max-width:560px){
    .shell{
        padding:14px;
    }

    .header,
    .market-top{
        display:block;
    }

    .badges{
        margin-top:12px;
        justify-content:flex-start;
    }

    .timer-wrap{
        text-align:left;
        margin-top:15px;
    }

    .grid{
        grid-template-columns:1fr 1fr;
        gap:9px;
    }

    .stat{
        padding:11px;
    }

    .stat-value{
        font-size:18px;
    }

    h1{
        font-size:24px;
    }
}
</style>
</head>

<body>

<div class="shell">

    <div class="header">
        <div>
            <div class="eyebrow">Skyesoft Prediction Market Lab</div>
            <h1>Kalshi BTC Lab</h1>
            <div class="subtitle">
                Live BTC 15-Minute Market Monitor
            </div>
        </div>

        <div class="badges">
            <div class="badge safe">READ ONLY</div>
            <div class="badge safe">PAPER ONLY</div>
            <div class="badge">v0.1.0-observe</div>
        </div>
    </div>


    <div class="market-card">

        <div class="market-top">
            <div>
                <div
                    class="market-title"
                    id="marketTitle"
                >
                    Loading current market…
                </div>

                <div
                    class="ticker"
                    id="marketTicker"
                >
                    —
                </div>
            </div>

            <div class="timer-wrap">
                <div class="timer-label">
                    Time Remaining
                </div>
                <div
                    class="timer"
                    id="timeRemaining"
                >
                    --:--
                </div>
            </div>
        </div>


        <div class="grid">

            <div class="stat">
                <div class="stat-label">Target / Strike</div>
                <div
                    class="stat-value"
                    id="strike"
                >
                    —
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">YES Spread</div>
                <div
                    class="stat-value"
                    id="yesSpread"
                >
                    —
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">Last Price</div>
                <div
                    class="stat-value"
                    id="lastPrice"
                >
                    —
                </div>
            </div>

            <div class="stat">
                <div class="stat-label">Open Interest</div>
                <div
                    class="stat-value"
                    id="openInterest"
                >
                    —
                </div>
            </div>

        </div>


        <div class="quote-grid">

            <div class="quote yes">
                <div class="quote-title">YES Market</div>

                <div class="quote-row">
                    <div class="price-box">
                        <div class="price-label">Bid</div>
                        <div
                            class="price"
                            id="yesBid"
                        >
                            —
                        </div>
                    </div>

                    <div class="price-box">
                        <div class="price-label">Ask</div>
                        <div
                            class="price"
                            id="yesAsk"
                        >
                            —
                        </div>
                    </div>
                </div>
            </div>


            <div class="quote no">
                <div class="quote-title">NO Market</div>

                <div class="quote-row">
                    <div class="price-box">
                        <div class="price-label">Bid</div>
                        <div
                            class="price"
                            id="noBid"
                        >
                            —
                        </div>
                    </div>

                    <div class="price-box">
                        <div class="price-label">Ask</div>
                        <div
                            class="price"
                            id="noAsk"
                        >
                            —
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>


    <div class="footer-panel">
        <div class="status-line">
            <div>
                <span class="status-dot"></span>
                <span id="connectionStatus">
                    Connecting to Kalshi…
                </span>
            </div>

            <div id="lastUpdated">
                —
            </div>
        </div>

        <div id="marketRule">
            This monitor is observational only. No predictive decision,
            paper execution, database write, or live trade is performed.
        </div>
    </div>

</div>


<script>
// #region SECTION 5 — Live Market UI

const refreshMilliseconds = <?= (int) $refreshMilliseconds ?>;

let latestSecondsRemaining = null;
let lastSnapshotUnix = null;


function formatContractPrice(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return (Number(value) * 100).toFixed(1) + '¢';
}


function formatUsd(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return '$' + Number(value).toLocaleString(
        undefined,
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );
}


function formatNumber(value, decimals = 0) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString(
        undefined,
        {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }
    );
}


function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) {
        return '--:--';
    }

    const safeSeconds = Math.max(
        0,
        Math.floor(seconds)
    );

    const minutes = Math.floor(
        safeSeconds / 60
    );

    const remainingSeconds =
        safeSeconds % 60;

    return String(minutes).padStart(2, '0')
        + ':'
        + String(remainingSeconds).padStart(2, '0');
}


function setText(id, value) {
    const element = document.getElementById(id);

    if (element) {
        element.textContent = value;
    }
}


function renderSnapshot(data) {
    if (!data.ok) {
        throw new Error(
            data.message || 'Unknown Kalshi error'
        );
    }

    if (!data.currentMarketFound) {
        setText(
            'marketTitle',
            'Waiting for active BTC 15-minute market…'
        );

        setText(
            'marketTicker',
            data.seriesTicker || 'KXBTC15M'
        );

        latestSecondsRemaining = null;

        setText(
            'connectionStatus',
            data.message || 'No current market found.'
        );

        return;
    }

    setText(
        'marketTitle',
        data.title || 'BTC price up in next 15 mins?'
    );

    setText(
        'marketTicker',
        data.ticker || '—'
    );

    setText(
        'strike',
        formatUsd(data.strike)
    );

    setText(
        'yesSpread',
        formatContractPrice(data.yesSpread)
    );

    setText(
        'lastPrice',
        formatContractPrice(data.lastPrice)
    );

    setText(
        'openInterest',
        formatNumber(data.openInterest, 2)
    );

    setText(
        'yesBid',
        formatContractPrice(data.yesBid)
    );

    setText(
        'yesAsk',
        formatContractPrice(data.yesAsk)
    );

    setText(
        'noBid',
        formatContractPrice(data.noBid)
    );

    setText(
        'noAsk',
        formatContractPrice(data.noAsk)
    );

    latestSecondsRemaining =
        data.secondsRemaining;

    lastSnapshotUnix =
        data.retrievedUnix;

    setText(
        'connectionStatus',
        'Live Kalshi data — '
        + (data.status || 'active')
    );

    const updated = new Date(
        data.retrievedUnix * 1000
    );

    setText(
        'lastUpdated',
        'Updated '
        + updated.toLocaleTimeString()
    );

    if (data.rulesPrimary) {
        setText(
            'marketRule',
            data.rulesPrimary
        );
    }
}


async function refreshMarket() {
    try {
        const response = await fetch(
            'live_market.php?mode=data&_=' + Date.now(),
            {
                cache: 'no-store'
            }
        );

        const data = await response.json();

        renderSnapshot(data);

    } catch (error) {
        setText(
            'connectionStatus',
            'Live data error: ' + error.message
        );

        const statusElement =
            document.getElementById(
                'connectionStatus'
            );

        if (statusElement) {
            statusElement.classList.add('error');
        }
    }
}


setInterval(
    () => {
        if (
            latestSecondsRemaining !== null
            && latestSecondsRemaining > 0
        ) {
            latestSecondsRemaining -= 1;
        }

        setText(
            'timeRemaining',
            formatDuration(
                latestSecondsRemaining
            )
        );
    },
    1000
);


refreshMarket();

setInterval(
    refreshMarket,
    refreshMilliseconds
);

// #endregion
</script>

</body>
</html>
<?php
// #endregion