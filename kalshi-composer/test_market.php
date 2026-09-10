<?php
declare(strict_types=1);


// ======================================================================
// Skyesoft — test_market.php
// Kalshi BTC 15-Minute Market Discovery
//
// Purpose:
//  • Query the live Kalshi markets API through the existing KalshiClient
//  • Restrict discovery to the KXBTC15M series
//  • Identify the currently active 15-minute BTC market window
//  • Retrieve the selected market detail and current order book
//  • Return the unmodified API payloads for Phase 1 inspection
//
// Safety:
//  • Read-only
//  • No POST requests
//  • No order placement
//  • No database writes
//
// ======================================================================


require_once __DIR__ . '/src/KalshiClient.php';


// #region SECTION 1 — Configuration

$seriesTicker = 'KXBTC15M';

// #endregion


// #region SECTION 2 — Helpers

function parseUnixTime(?string $isoTime): ?int
{
    if (!$isoTime) {
        return null;
    }

    $unix = strtotime($isoTime);

    return $unix === false
        ? null
        : $unix;
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
            $status === 'open'
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

// #endregion


// #region SECTION 3 — Market Discovery

try {
    $kalshi = new KalshiClient();

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

    $marketDetailResponse = null;
    $orderBookResponse    = null;

    if ($currentMarket !== null) {
        $marketTicker = (string) ($currentMarket['ticker'] ?? '');

        if ($marketTicker !== '') {
            $marketDetailResponse = $kalshi->get(
                '/trade-api/v2/markets/' . rawurlencode($marketTicker)
            );

            $orderBookResponse = $kalshi->get(
                '/trade-api/v2/markets/'
                . rawurlencode($marketTicker)
                . '/orderbook'
            );
        }
    }

    $output = [
        'researchMeta' => [
            'seriesTicker'       => $seriesTicker,
            'retrievedUnix'      => $retrievedUnix,
            'retrievedIsoUtc'    => gmdate('c', $retrievedUnix),
            'openMarketsFound'   => count($markets),
            'currentMarketFound' => $currentMarket !== null,
            'selectedTicker'     => $currentMarket['ticker'] ?? null
        ],

        'selectedMarket' => $currentMarket,

        'rawResponses' => [
            'markets'      => $marketsResponse,
            'marketDetail' => $marketDetailResponse,
            'orderBook'    => $orderBookResponse
        ]
    ];

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $output,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );

} catch (Throwable $e) {
    http_response_code(500);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        [
            'error'   => true,
            'message' => $e->getMessage()
        ],
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
    );
}

// #endregion