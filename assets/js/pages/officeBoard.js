/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Unified Card Model – 2026 refactored edition
   Phoenix, Arizona – MST timezone
*/
// ======================================================================
// GLOBAL STATE CONTAINER (SSE SOURCE OF TRUTH)
// ======================================================================

window.SkyIndex = window.SkyIndex || {
    lastSSE: null
};

// #region 🔔 Version Update Indicator Controller
window.SkyVersion = {

    timeoutId: null,

    show(durationMs = 60000) {
        const versionEl = document.getElementById('versionFooter');
        if (!versionEl) {
            console.warn('[SkyVersion] #versionFooter not found');
            return;
        }

        // Prevent duplicates
        if (versionEl.querySelector('.versionUpdateBadge')) return;

        const badge = document.createElement('span');
        badge.className = 'versionUpdateBadge';
        badge.textContent = 'Updated';

        // Insert as LEADING element
        versionEl.prepend(badge);

        if (this.timeoutId) clearTimeout(this.timeoutId);

        this.timeoutId = setTimeout(() => {
            this.hide();
        }, durationMs);
    },

    hide() {
        const badge = document.querySelector('#versionFooter .versionUpdateBadge');
        if (badge) badge.remove();
        this.timeoutId = null;
    }
};
// #endregion

// #region 🔔 OfficeBoard Version Update Indicator
window.OfficeBoardVersion = {

    timeoutId: null,

    show(durationMs = 60000) {
        const el = document.getElementById('versionFooter');
        if (!el) {
            console.warn('[OfficeBoardVersion] #versionFooter not found');
            return;
        }

        el.classList.add('hasUpdate');

        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }

        this.timeoutId = setTimeout(() => {
            this.hide();
        }, durationMs);
    },

    hide() {
        const el = document.getElementById('versionFooter');
        if (el) {
            el.classList.remove('hasUpdate');
        }
        this.timeoutId = null;
    }
};
// #endregion

// #region GLOBAL REGISTRIES
    
    // Permit Statuses
    const PERMIT_STATUSES = [
        'need_to_submit',
        'submitted',
        'qc_passed',
        'under_review',
        'corrections',
        'ready_to_issue',
        'issued',
        'inspections',
        'finaled'
    ];

let jurisdictionRegistry = null;
let latestActivePermits = [];
let iconMap = null;
let lastBoardPayload = null; // 🔁 cache most recent SSE payload
let versionsMeta = null;

// Countdown state (global, managed by rotation controller)
let countdownTimer = null;
let countdownRemainingMs = 0;
let activeCountdownEl = null;

function resolveJurisdictionLabel(raw) {
    if (!raw || !jurisdictionRegistry) return raw;
    const norm = String(raw).trim().toUpperCase();
    for (const key in jurisdictionRegistry) {
        const entry = jurisdictionRegistry[key];
        if (!entry) continue;
        if (key.toUpperCase() === norm) return entry.label;
        if (Array.isArray(entry.aliases)) {
            if (entry.aliases.some(a => a.toUpperCase() === norm)) {
                return entry.label;
            }
        }
    }
    return norm.toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
}

function formatStatus(status) {
    if (!status) return '';
    return String(status)
        .toLowerCase()
        .split('_')
        .map(w => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', { cache: 'no-cache' })
    .then(res => res.ok ? res.json() : Promise.reject(`HTTP ${res.status}`))
    .then(data => {
        jurisdictionRegistry = data;
        console.log(`✅ Jurisdiction registry loaded — ${Object.keys(data).length} entries`);
        window.SkyOfficeBoard?.lastPermitSignature && (window.SkyOfficeBoard.lastPermitSignature = null);
    })
    .catch(err => {
        console.error('❌ Failed to load jurisdictionRegistry.json', err);
        jurisdictionRegistry = {};
    });

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/iconMap.json', { cache: 'no-cache' })
    .then(res => res.ok ? res.json() : Promise.reject(`HTTP ${res.status}`))
    .then(data => {
        iconMap = data.icons;
        console.log(`✅ Icon map loaded — ${Object.keys(iconMap).length} icons`);
    })
    .catch(err => {
        console.error('❌ Failed to load iconMap.json', err);
        iconMap = {};
    });

// Load versions metadata (used for card footers)
fetch('https://www.skyelighting.com/skyesoft/data/authoritative/versions.json', { cache: 'no-cache' })
    .then(res => res.ok ? res.json() : Promise.reject(`HTTP ${res.status}`))
    .then(data => {
        versionsMeta = data;
        console.log('✅ Versions meta loaded', data);
    })
    .catch(err => {
        console.warn('⚠️ Failed to load versions.json', err);
        versionsMeta = null;
    });

// #endregion

// #region HELPERS

window.glbVar = window.glbVar || {};
window.glbVar.tips = [];
window.glbVar.tipsLoaded = false;
// get status icon HTML from status string
function getStatusIcon(stage) {
    if (!stage) return '';

    const s = String(stage)
        .trim()
        .toLowerCase();

    const keyMap = {
        'pre-submittal':       'clipboard',
        'submitted':           'uparrow',
        'jurisdiction review': 'temple',
        'approval / issuance': 'shield',
        'inspection':          'tools',
        'finaled':             'trophy'
    };

    const iconKey = keyMap[s];

    if (!iconKey || !iconMap) return '';

    const entry = Object.values(iconMap).find(e =>
        (e.file && e.file.toLowerCase().includes(iconKey)) ||
        (e.alt && e.alt.toLowerCase().includes(iconKey))
    );

    if (!entry) return '';

    if (entry.emoji) {
        return entry.emoji + ' ';
    }

    if (entry.file) {
        const url =
            `https://www.skyelighting.com/skyesoft/assets/images/icons/${entry.file}`;

        return `<img src="${url}" alt="${entry.alt || 'stage icon'}" style="width:16px; height:16px; vertical-align:middle; margin-right:4px;">`;
    }

    return '';
}
// format seconds into smart interval string (no seconds)
function formatSmartInterval(totalSeconds) {

    let sec = Math.max(0, totalSeconds);

    // Humanized recent update
    if (sec < 60) return "just now";

    const days    = Math.floor(sec / 86400); sec %= 86400;
    const hours   = Math.floor(sec / 3600);  sec %= 3600;
    const minutes = Math.floor(sec / 60);

    if (days > 0)    return `${days}d ${hours}h ${minutes}m`;
    if (hours > 0)   return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m`;

    return "just now";
}
// normalize unix timestamp (seconds vs milliseconds)
function normalizeUnixSeconds(ts) {
    const n = Number(ts);
    if (!Number.isFinite(n)) return null;
    // Heuristic: milliseconds are typically 13 digits
    return n > 1_000_000_000_000 ? Math.floor(n / 1000) : Math.floor(n);
}
function formatTimestamp(ts) {
    const unix = normalizeUnixSeconds(ts);
    if (!unix) return '--/--/-- --:--';
    const date = new Date(unix * 1000);
    const opts = {
        timeZone: 'America/Phoenix',
        month: '2-digit', day: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: true
    };
    return date.toLocaleString('en-US', opts).replace(',', '');
}
// get Date object from SSE payload
function getDateFromSSE(payload) {
    const ts = payload?.timeDateArray?.currentUnixTime;
    if (!ts) return null;
    return new Date(ts * 1000);
}
// calculate daylight duration from sunrise/sunset strings (HH:MM AM/PM)
function calculateDaylight(sunrise, sunset) {
    if (!sunrise || !sunset) return null;
    const toMinutes = t => {
        const d = new Date(`1970-01-01 ${t}`);
        return d.getHours() * 60 + d.getMinutes();
    };
    const minutes = toMinutes(sunset) - toMinutes(sunrise);
    return minutes > 0 ? formatSmartInterval(minutes * 60) : null;
}
// get live date info from SSE payload
function getLiveDateInfoFromSSE(payload) {
    const now = getDateFromSSE(payload);
    if (!now) return null;

    const formattedDate = now.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric'
    });
    const startOfYear = new Date(now.getFullYear(), 0, 1);
    const oneDay = 1000 * 60 * 60 * 24;
    const dayOfYear = Math.floor((now - startOfYear) / oneDay);

    const isLeapYear =
        (now.getFullYear() % 4 === 0 && now.getFullYear() % 100 !== 0) ||
        (now.getFullYear() % 400 === 0);

    const daysInYear = isLeapYear ? 366 : 365;

    return {
        formattedDate,
        dayOfYear,
        daysRemaining: daysInYear - dayOfYear
    };
}
// Render Today's Highlights skeleton (monitor-safe, sectioned)
function renderTodaysHighlightsSkeleton() {
    return `
        <div class="highlights-grid">

            <!-- LEFT COLUMN -->
            <div class="highlights-col left-col">

                <!-- 📅 DATE -->
                <div class="section-block">
                    <div class="section-header">
                        📅 <span class="section-title">Today</span>
                    </div>
                    <div class="entry compact">
                        <span id="todaysDate">—</span>
                        &nbsp;|&nbsp;
                        🗓️ Day <span id="dayOfYear">—</span>
                        (<span id="daysRemaining">—</span> remaining)
                    </div>
                </div>

                <!-- ❄️ SEASON -->
                <div class="section-block">
                    <div class="section-header">
                        <span id="seasonIcon">❄️</span>
                        <span class="section-title">Season</span>
                    </div>
                    <div class="entry compact highlight-season">
                        <span id="seasonName">—</span>
                        — Day <span id="seasonDay">—</span>
                        (<span id="seasonDaysLeft">—</span> days left)
                    </div>
                </div>

                <!-- 🌄 SUN & LIGHT -->
                <div class="section-block">
                    <div class="section-header">
                        🌄 <span class="section-title">Sun & Light</span>
                    </div>
                    <div class="entry compact">
                        Sunrise: <span id="sunriseTime">—</span>
                        &nbsp;|&nbsp;
                        Sunset: <span id="sunsetTime">—</span>
                    </div>
                    <div class="entry compact">
                        Daylight: <span id="daylightTime">—</span>
                        &nbsp;|&nbsp;
                        Night: <span id="nightTime">—</span>
                    </div>
                </div>

                <!-- 🎉 UPCOMING -->
                <div class="section-block">
                    <div class="section-header">
                        🎉 <span class="section-title">Upcoming</span>
                    </div>
                    <div class="entry compact">
                        <span id="nextHoliday">—</span>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN -->
            <div class="highlights-col">

                <div class="entry section-header">
                    📊 Skyesoft Activity
                </div>

                <div class="entry kpi-row">
                    <span>Entities</span>
                    <strong id="activityEntityCount">—</strong>
                </div>

                <div class="entry kpi-row">
                    <span>Locations</span>
                    <strong id="activityLocationCount">—</strong>
                </div>

                <div class="entry kpi-row">
                    <span>Contacts</span>
                    <strong id="activityContactCount">—</strong>
                </div>

                <div class="entry section-header">
                    ⚡ Actions
                </div>

                <div class="entry kpi-row">
                    <span>Today</span>
                    <strong id="activityActionsToday">—</strong>
                </div>

                <div class="entry kpi-row">
                    <span>Total</span>
                    <strong id="activityActionsTotal">—</strong>
                </div>

                <div class="entry section-header">
                    🕒 Last Action
                </div>

                <div class="entry compact" id="activityLastActionSummary">
                    —
                </div>

            </div>

        </div>
    `;
}
// Get Season Summary from UNIX time (day precision)
function getSeasonSummaryFromUnix(unixSeconds) {

    if (!unixSeconds || isNaN(unixSeconds)) return null;

    const date = new Date(unixSeconds * 1000);

    // Normalize to midnight (avoids hour drift)
    date.setHours(0,0,0,0);

    const year = date.getFullYear();

    // Astronomical season start days (rounded)
    const spring = new Date(year, 2, 20);  // Mar 20
    const summer = new Date(year, 5, 21);  // Jun 21
    const fall   = new Date(year, 8, 22);  // Sep 22
    const winter = new Date(year,11,21);   // Dec 21

    const prevWinter = new Date(year-1,11,21);
    const nextSpring = new Date(year+1,2,20);

    let current;

    // Determine season
    if (date < spring)
        current = { name:'Winter', start:prevWinter, end:spring };

    else if (date < summer)
        current = { name:'Spring', start:spring, end:summer };

    else if (date < fall)
        current = { name:'Summer', start:summer, end:fall };

    else if (date < winter)
        current = { name:'Fall', start:fall, end:winter };

    else
        current = { name:'Winter', start:winter, end:nextSpring };

    const msPerDay = 86400000;

    const day =
        Math.floor((date - current.start) / msPerDay) + 1;

    const totalDays =
        Math.floor((current.end - current.start) / msPerDay);

    return {
        name: current.name,
        day: day,
        daysRemaining: totalDays - day
    };
}
// Update today's highlights card with live data
function updateHighlightsCard(payload = lastBoardPayload) {
    if (!payload) return;

    const unix = payload?.timeDateArray?.currentUnixTime;
    if (!unix) return;

    const now = new Date(unix * 1000);

    const formattedDate = now.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric'
    });

    const startOfYear = new Date(now.getFullYear(), 0, 1);
    const dayOfYear = Math.floor((now - startOfYear) / (1000 * 60 * 60 * 24)) + 1;

    const isLeapYear =
        (now.getFullYear() % 4 === 0 && now.getFullYear() % 100 !== 0) ||
        (now.getFullYear() % 400 === 0);

    const daysInYear = isLeapYear ? 366 : 365;
    const daysRemaining = daysInYear - dayOfYear;

    const dateEl = document.getElementById('todaysDate');
    const dayEl  = document.getElementById('dayOfYear');
    const remEl  = document.getElementById('daysRemaining');

    if (dateEl) dateEl.textContent = formattedDate;
    if (dayEl)  dayEl.textContent  = dayOfYear;
    if (remEl)  remEl.textContent  = daysRemaining;

    const sunriseEl = document.getElementById('sunriseTime');
    const sunsetEl  = document.getElementById('sunsetTime');

    const sunriseUnix = payload?.weather?.sunriseUnix ?? null;
    const sunsetUnix  = payload?.weather?.sunsetUnix  ?? null;

    const sunriseStr = formatPhoenixTimeFromUnix(sunriseUnix) || '—';
    const sunsetStr  = formatPhoenixTimeFromUnix(sunsetUnix)  || '—';

    if (sunriseEl) sunriseEl.textContent = sunriseStr;
    if (sunsetEl)  sunsetEl.textContent  = sunsetStr;

    const daylightEl = document.getElementById('daylightTime');
    const nightEl    = document.getElementById('nightTime');

    if (sunriseUnix && sunsetUnix && !isNaN(sunriseUnix) && !isNaN(sunsetUnix)) {
        let daylightSeconds = sunsetUnix - sunriseUnix;
        if (daylightSeconds < 0) daylightSeconds += 86400;
        const nightSeconds = 86400 - daylightSeconds;

        if (daylightEl) daylightEl.textContent = formatSmartInterval(daylightSeconds);
        if (nightEl)    nightEl.textContent    = formatSmartInterval(nightSeconds);
    } else {
        if (daylightEl) daylightEl.textContent = '—';
        if (nightEl)    nightEl.textContent    = '—';
    }

    const holidayEl = document.getElementById('nextHoliday');
    const nextHoliday = payload?.holidayState?.nextHoliday;

    if (holidayEl && nextHoliday) {
        holidayEl.textContent = `${nextHoliday.name} (${nextHoliday.daysAway} days)`;
    }
}
// load and render a random Skyesoft tip
function loadAndRenderSkyesoftTip(providedEl = null) {
    const el = providedEl || document.getElementById('skyesoftTips');
    if (!el) {
        console.warn('loadAndRenderSkyesoftTip: element not found');
        return;
    }

    if (window.glbVar.tipsLoaded && window.glbVar.tips.length > 0) {
        renderRandomTip(el);
        return;
    }

    if (window.glbVar.tipsLoading) return;
    window.glbVar.tipsLoading = true;

    fetch('https://www.skyelighting.com/skyesoft/data/authoritative/skyesoftTips.json', {
        cache: 'no-cache'
    })
    .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    })
    .then(data => {
        const tipsArray = data?.skyesoftTips?.tips || data?.tips;
        if (Array.isArray(tipsArray) && tipsArray.length > 0) {
            window.glbVar.tips = tipsArray;
            window.glbVar.tipsLoaded = true;
            console.log(`💡 Skyesoft Tips loaded — ${tipsArray.length} entries`);
            renderRandomTip(el);
        } else {
            console.warn('Tips JSON loaded but no valid tips array found');
        }
    })
    .catch(err => {
        console.warn('⚠️ Failed to load Skyesoft tips', err);
        el.textContent = '💡 Skyesoft Tip: Double-check drawings before submission.';
    })
    .finally(() => {
        window.glbVar.tipsLoading = false;
    });
}
// render a random tip into the given element
function renderRandomTip(el) {
    const tips = window.glbVar.tips;
    if (!tips.length) return;

    const tip = tips[Math.floor(Math.random() * tips.length)];
    if (!tip?.text) return;

    // ❌ REMOVE emoji here
    el.textContent = tip.text;
}
// build initial payload with current time
function buildInitialTimePayload() {
    const now = new Date();
    return {
        time: {
            now: Math.floor(now.getTime() / 1000)
        }
    };
}
// format unix timestamp to Phoenix time string
function formatPhoenixTimeFromUnix(unixSeconds) {
    if (!unixSeconds) return '—';
    return new Date(unixSeconds * 1000).toLocaleTimeString('en-US', {
        timeZone: 'America/Phoenix',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}
// format unix timestamp to Phoenix date (MM/DD)
function formatPhoenixDateFromUnix(unixSeconds) {
    if (!unixSeconds) return '—';

    return new Date(unixSeconds * 1000).toLocaleDateString('en-US', {
        timeZone: 'America/Phoenix',
        month: '2-digit',
        day: '2-digit'
    });
}
// Map OpenWeather icon code → rendered icon
function mapWeatherIcon(icon, condition='') {

    if (!icon) return '—';

    // force day icons
    const code = icon.replace('n','d');

    const map = {

        '01d':'clear-day.svg',
        '02d':'cloudy-1-day.svg',
        '03d':'cloudy-2-day.svg',
        '04d':'cloudy-3-day.svg',

        '09d':'rainy-1.svg',
        '10d':'rainy-1-day.svg',

        '11d':'thunderstorms.svg',
        '13d':'snowy-1.svg',

        '50d':'fog-day.svg'
    };

    const file = map[code] || 'cloudy-2-day.svg';

    return `<img class="forecast-icon" src="/skyesoft/assets/images/weather/${file}" alt="${condition || 'weather'}">`;
}
// Get Phoenix midnight timestamp for "today" cutoff
function getPhoenixTodayMidnight() {

    const now = new Date();

    const phoenixNow = new Date(
        now.toLocaleString("en-US", { timeZone: "America/Phoenix" })
    );

    phoenixNow.setHours(0, 0, 0, 0);

    return Math.floor(phoenixNow.getTime() / 1000);

}

// Render 3-day weather forecast
function renderThreeDayForecast(forecastEls, payload) {

    const forecast = payload?.weather?.forecast;

    if (!Array.isArray(forecast) || !forecastEls?.length) {

        forecastEls.forEach(el => {
            if (el.day)   el.day.textContent   = '—';
            if (el.icon)  el.icon.textContent  = '—';
            if (el.temps) el.temps.textContent = '— / —';
        });

        return;

    }

    // Phoenix midnight cutoff
    const todayUnix = getPhoenixTodayMidnight();

    // Remove yesterday entries
    const validDays = forecast
        .filter(day => day?.dateUnix >= todayUnix)
        .slice(0, 3);

    const labels = ['Today', 'Tomorrow', 'Day After Next'];

    validDays.forEach((dayData, i) => {

        const { dateUnix, high, low, icon, condition } = dayData || {};

        const dateLabel = formatPhoenixDateFromUnix(dateUnix);

        if (forecastEls[i]?.day)
            forecastEls[i].day.textContent = `${labels[i]} (${dateLabel})`;

        if (forecastEls[i]?.icon)
            forecastEls[i].icon.innerHTML = mapWeatherIcon(icon, condition);

        if (forecastEls[i]?.temps) {

            const hi = Number.isFinite(high) ? Math.round(high) : '?';
            const lo = Number.isFinite(low)  ? Math.round(low)  : '?';

            forecastEls[i].temps.textContent = `${hi}° / ${lo}°`;

        }

    });

}

// ── Countdown helpers ────────────────────────────────────────────────

function startCardCountdown(durationMs, el) {
    stopCardCountdown();

    if (!el || !durationMs) return;

    countdownRemainingMs = durationMs;
    activeCountdownEl = el;

    const tick = () => {
        if (!activeCountdownEl) return;

        const seconds = Math.max(0, Math.ceil(countdownRemainingMs / 1000));
        activeCountdownEl.textContent = `⏳ ${seconds}s`;

        countdownRemainingMs -= 1000;

        if (countdownRemainingMs <= 0) {
            activeCountdownEl.textContent = '⏳ 0s';
            stopCardCountdown();
        }
    };

    tick(); // immediate
    countdownTimer = setInterval(tick, 1000);
}

function stopCardCountdown() {
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = null;
    countdownRemainingMs = 0;
    activeCountdownEl = null;
}

// ── Card footer freshness resolver ──────────────────────────────────

function resolveCardFooter(cardId) {
    if (!versionsMeta?.cards?.[cardId]) return null;

    const cardMeta = versionsMeta.cards[cardId];
    const footerCfg = cardMeta.footer || {};
    const modules = cardMeta.modules || [];

    let lastUpdated = null;

    // AUTO: latest timestamp among used modules
    if (footerCfg.mode === 'auto' && Array.isArray(modules)) {
        modules.forEach(m => {
            const ts = versionsMeta.modules?.[m]?.lastUpdatedUnix;
            if (ts && (!lastUpdated || ts > lastUpdated)) {
                lastUpdated = ts;
            }
        });
    }

    // MANUAL: explicit timestamp
    if (footerCfg.mode === 'manual' && footerCfg.lastUpdatedUnix) {
        lastUpdated = footerCfg.lastUpdatedUnix;
    }

    if (!lastUpdated) return null;

    const label = footerCfg.label;

    const absoluteTime = formatTimestamp(lastUpdated);
    const relativeTime = humanizeRelativeTime(lastUpdated);

    const timeStr = `${absoluteTime} (${relativeTime})`;
    // Return final footer string
    return label
        ? `${label} • Updated ${timeStr}`
        : `Updated ${timeStr}`;


}
// Humanize relative time helper
function humanizeRelativeTime(updatedUnix, referenceUnix = null) {
    const updated = normalizeUnixSeconds(updatedUnix);
    const ref = referenceUnix != null
        ? normalizeUnixSeconds(referenceUnix)
        : Math.floor(Date.now() / 1000);

    if (!updated || !ref) return 'just now';

    const seconds = ref - updated;

    if (seconds < 0) return 'just now';

    const units = [
        { label: 'month',  value: 60 * 60 * 24 * 30 },
        { label: 'day',    value: 60 * 60 * 24 },
        { label: 'hour',   value: 60 * 60 },
        { label: 'minute', value: 60 }
    ];

    for (const unit of units) {
        const amount = Math.floor(seconds / unit.value);
        if (amount >= 1) {
            return `${amount} ${unit.label}${amount !== 1 ? 's' : ''} ago`;
        }
    }

    return 'just now';
}
// Apply Highlights Density
function applyHighlightsDensity(cardEl) {
    if (!cardEl) return;

    const vh = window.innerHeight;

    // Office monitors / TVs
    if (vh >= 900) {
        cardEl.classList.add('dense');
    } else {
        cardEl.classList.remove('dense');
    }
}
// Get Season Icon
function getSeasonIcon(seasonName) {
    switch (seasonName) {
        case 'Winter': return '❄️';
        case 'Spring': return '🌱';
        case 'Summer': return '☀️';
        case 'Fall':   return '🍂';
        default:       return '📆';
    }
}
// Create Permit New Card
function createPermitNewsCard(card) {
    const root = document.createElement('div');
    root.className = 'board-card';
    root.id = card.id;

    const header = document.createElement('div');
    header.className = 'cardHeader';
    header.textContent = card.title;

    const content = document.createElement('div');
    content.className = 'cardContent';

    const footer = document.createElement('div');
    footer.className = 'cardFooter';

    root.append(header, content, footer);

    return { root, content, footer };
}
// ⏱️ Format Version Footer (canonical, shared behavior)
function formatVersionFooter(siteMeta) {

    // Normalize version so "unknown" never appears
    const version =
        (siteMeta?.siteVersion && siteMeta.siteVersion !== 'unknown')
            ? siteMeta.siteVersion
            : '—';

    if (!siteMeta?.lastUpdateUnix) {
        return `v${version}`;
    }

    const TZ = 'America/Phoenix';

    const d = new Date(siteMeta.lastUpdateUnix * 1000);

    const dateStr = d.toLocaleDateString('en-US', {
        timeZone: TZ,
        month: '2-digit',
        day: '2-digit',
        year: '2-digit'
    });

    const timeStr = d.toLocaleTimeString('en-US', {
        timeZone: TZ,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });

    const ageSeconds = siteMeta.lastUpdateAgeSeconds ?? 0;

    let agoStr;

    if (ageSeconds < 60) {

        agoStr = '<span class="version-now">just now</span>';

    }
    else if (ageSeconds < 3600) {

        const mins = Math.floor(ageSeconds / 60);
        agoStr = `${mins} minute${mins === 1 ? '' : 's'} ago`;

    }
    else if (ageSeconds < 86400) {

        const hrs  = Math.floor(ageSeconds / 3600);
        const mins = Math.floor((ageSeconds % 3600) / 60);

        agoStr =
            `${hrs} hour${hrs === 1 ? '' : 's'}` +
            (mins ? `, ${mins} minute${mins === 1 ? '' : 's'}` : '') +
            ` ago`;

    }
    else if (ageSeconds < 2592000) {

        const days = Math.floor(ageSeconds / 86400);
        agoStr = `${days} day${days === 1 ? '' : 's'} ago`;

    }
    else if (ageSeconds < 31536000) {

        const months = Math.floor(ageSeconds / 2592000);
        const days   = Math.floor((ageSeconds % 2592000) / 86400);

        agoStr =
            `${months} month${months === 1 ? '' : 's'}` +
            (days ? `, ${days} day${days === 1 ? '' : 's'}` : '') +
            ` ago`;

    }
    else {

        const years = Math.floor(ageSeconds / 31536000);
        agoStr = `${years} year${years === 1 ? '' : 's'} ago`;

    }

    return `v${version} · ${dateStr} ${timeStr} (${agoStr})`;
}
// ⏳ Canonical Interval Formatter (DD HH MM SS, padded, no leading nulls)
function formatIntervalDHMS(totalSeconds) {
    const pad = n => String(n).padStart(2, '0');

    const days = Math.floor(totalSeconds / 86400);
    const hrs  = Math.floor((totalSeconds % 86400) / 3600);
    const mins = Math.floor((totalSeconds % 3600) / 60);
    const secs = totalSeconds % 60;

    const parts = [];

    if (days > 0) parts.push(`${pad(days)}d`);
    if (hrs  > 0 || parts.length) parts.push(`${pad(hrs)}h`);
    if (mins > 0 || parts.length) parts.push(`${pad(mins)}m`);
    parts.push(`${pad(secs)}s`);

    return parts.join(' ');
}
// #endregion

// #region LIVE FOOTER HELPER

function renderLiveFooter({ text = '' }) {
    const liveIcon = `
        <img src="https://www.skyelighting.com/skyesoft/assets/images/live-streaming.gif"
             alt="Live"
             style="width:24px;height:24px;vertical-align:middle;margin-right:8px;">
    `;
    return `${liveIcon}${text}`;
}

// #endregion

// #region CARD TIMING

const DEFAULT_CARD_DURATION_MS = 10000;

// #endregion

// #region CARD FACTORY
// Create Active Permits Card Element
function createActivePermitsCardElement() {
    const card = document.createElement('section');
    card.className = 'card card-active-permits';
    card.innerHTML = `
        <div class="cardHeader"><h2>📋 Active Permits <span class="cardCountdown">—</span></h2></div>
        <div class="cardBodyDivider"></div>
        <div class="cardBody">
            <div class="cardContent" id="permitScrollWrap">
                <table class="permit-table">
                    <thead><tr>
                        <th>WO</th><th>Customer</th><th>Jobsite</th>
                        <th>Jurisdiction</th><th>Status</th>
                    </tr></thead>
                    <tbody id="permitTableBody">
                        <tr><td colspan="5">Loading permits…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="cardFooterDivider"></div>
        <div class="cardFooter" id="permitFooter">Loading…</div>
    `;
    return {
        root: card,
        scrollWrap: card.querySelector('#permitScrollWrap'),
        tableBody: card.querySelector('#permitTableBody'),
        footer: card.querySelector('#permitFooter')
    };
}
// Create Generic Card Element
function createGenericCardElement(spec) {
    const card = document.createElement('section');
    card.className = `card card-${spec.id}`;
    card.innerHTML = `
        <div class="cardHeader">
            <h2>
                ${spec.icon || '✨'} ${spec.title}
                <span class="cardCountdown" aria-hidden="true">—</span>
            </h2>
        </div>
        <div class="cardBodyDivider"></div>
        <div class="cardBody">
            <div class="cardContent">
                <div id="content-${spec.id}">Loading...</div>
            </div>
        </div>
        <div class="cardFooterDivider"></div>
        <div class="cardFooter" id="footer-${spec.id}"></div>
    `;
    return {
        root: card,
        content: card.querySelector(`#content-${spec.id}`),
        footer: card.querySelector(`#footer-${spec.id}`)
    };
}

// #endregion

// #region CARD REGISTRY + UNIVERSAL UPDATER
// Initialize Board Cards
const BOARD_CARDS = [];
// Active Permit Card
const ActivePermitsCard = {
    id: 'active-permits',
    durationMs: DEFAULT_CARD_DURATION_MS,
    instance: null,
    lastSignature: null,

    create() {
        this.lastSignature = null;
        this.instance = createActivePermitsCardElement();
        return this.instance.root;
    },

    update(payload) {
        if (!payload || !Array.isArray(payload.activePermits)) return;

        const permits = payload.activePermits;
        latestActivePermits = permits;

        const body = this.instance?.tableBody;
        const footer = this.instance?.footer;
        if (!body) return;

        const signature = permits.length
            ? permits.map(p => `${p.wo}|${p.status}|${p.jurisdiction}|${p.customer}|${p.jobsite}`).join('::')
            : 'empty';

        // ── Common footer rendering logic (Active Permits authoritative) ──
        const renderFooter = () => {
            if (!footer) return;

            // Active Permits are now supplied dynamically from the database.
            const totalPermits = permits.length;

            // SSE time represents the current dynamic snapshot.
            const updatedUnix = payload?.activePermitsMeta?.lastUpdatedUnix;

            if (!updatedUnix) {
                footer.innerHTML = renderLiveFooter({
                    text: `${totalPermits} active permit${totalPermits !== 1 ? 's' : ''} • Timestamp unavailable`
                });
                return;
            }

            const absoluteTime = formatTimestamp(updatedUnix);

            footer.innerHTML = renderLiveFooter({
                text: `${totalPermits} active permit${totalPermits !== 1 ? 's' : ''} • Updated ${absoluteTime}`
            });
        };

        // Signature match → just update footer (live ticking)
        if (signature === this.lastSignature) {
            renderFooter();
            return;
        }

        // ── Data changed → full rebuild ──
        this.lastSignature = signature;
        body.innerHTML = '';

        if (permits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No permits</td></tr>`;
            if (footer) {
                footer.textContent = 'No permits found';
            }
            return;
        }

        const sorted = permits.slice().sort((a, b) => (parseInt(a.wo, 10) || 0) - (parseInt(b.wo, 10) || 0));

        const frag = document.createDocumentFragment();
        sorted.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${p.wo}</td>
                <td>${p.customer}</td>
                <td>${p.jobsite}</td>
                <td>${resolveJurisdictionLabel(p.jurisdiction)}</td>
                <td>${getStatusIcon(p.stage)}${formatStatus(p.status)}</td>
            `;
            frag.appendChild(tr);
        });

        body.appendChild(frag);

        // Always use the same footer logic after rebuild
        renderFooter();

        requestAnimationFrame(() => {
            if (this.instance?.scrollWrap) {
                window.SkyOfficeBoard.autoScroll.start(this.instance.scrollWrap, this.durationMs);
            }
        });
    },

    onShow() {
        // Footer is now fully driven by SSE → no need for extra logic here
    },

    onHide() {
        window.SkyOfficeBoard.autoScroll.stop();
    }
};
// Today's Highlights Card
const TodaysHighlightsCard = {
    id: 'todays-highlights',
    icon: '🌅',
    title: 'Today’s Highlights',
    durationMs: DEFAULT_CARD_DURATION_MS,

    instance: null,
    tipElement: null,
    forecastElements: null,
    // Create handler
    create() {
        this.instance = createGenericCardElement(this);
        this.instance.content.innerHTML = renderTodaysHighlightsSkeleton();

        this.tipElement = this.instance.content.querySelector('#skyesoftTips');

        const forecastRows = this.instance.content.querySelectorAll('.forecast-row');
        this.forecastElements = Array.from(forecastRows).map(row => ({
            day:  row.querySelector('.day'),
            icon: row.querySelector('.icon'),
            temps: row.querySelector('.temps')
        }));

        if (this.forecastElements.length !== 3) {
            console.warn("[TodaysHighlightsCard] Expected 3 forecast rows, got", this.forecastElements.length);
        }

        return this.instance.root;
    },
    // Update with live data
    update(payload) {
        if (!payload) return;

        updateHighlightsCard(payload);

        if (this.forecastElements) {
            renderThreeDayForecast(this.forecastElements, payload);
        }

        // ── Season display (authoritative SSE time) ──
        const unixTime = payload?.timeDateArray?.currentUnixTime;
        const season = getSeasonSummaryFromUnix(unixTime);

        if (season) {
            const nameEl = document.getElementById('seasonName');
            const dayEl  = document.getElementById('seasonDay');
            const remEl  = document.getElementById('seasonDaysLeft');
            const iconEl = document.getElementById('seasonIcon');

            if (nameEl) nameEl.textContent = season.name;
            if (dayEl)  dayEl.textContent  = season.day;
            if (remEl)  remEl.textContent  = season.daysRemaining;
            if (iconEl) iconEl.textContent = getSeasonIcon(season.name);
        }

        /* ─────────────────────────────
        SKYESOFT ACTIVITY
        SSE-authoritative
        ───────────────────────────── */
        const systemActivity =
            payload?.systemActivity;

        if (systemActivity) {

            // ------------------------------------------------------------
            // ELC Counts
            // ------------------------------------------------------------
            const entityEl =
                this.instance.root.querySelector('#activityEntityCount');

            const locationEl =
                this.instance.root.querySelector('#activityLocationCount');

            const contactEl =
                this.instance.root.querySelector('#activityContactCount');

            if (entityEl) {
                entityEl.textContent =
                    Number.isInteger(systemActivity.elc?.entities)
                        ? systemActivity.elc.entities.toLocaleString()
                        : '—';
            }

            if (locationEl) {
                locationEl.textContent =
                    Number.isInteger(systemActivity.elc?.locations)
                        ? systemActivity.elc.locations.toLocaleString()
                        : '—';
            }

            if (contactEl) {
                contactEl.textContent =
                    Number.isInteger(systemActivity.elc?.contacts)
                        ? systemActivity.elc.contacts.toLocaleString()
                        : '—';
            }

            // ------------------------------------------------------------
            // Action Counts
            // ------------------------------------------------------------
            const actionsTodayEl =
                this.instance.root.querySelector('#activityActionsToday');

            const actionsTotalEl =
                this.instance.root.querySelector('#activityActionsTotal');

            if (actionsTodayEl) {
                actionsTodayEl.textContent =
                    Number.isInteger(systemActivity.actions?.today)
                        ? systemActivity.actions.today.toLocaleString()
                        : '—';
            }

            if (actionsTotalEl) {
                actionsTotalEl.textContent =
                    Number.isInteger(systemActivity.actions?.total)
                        ? systemActivity.actions.total.toLocaleString()
                        : '—';
            }

            // ------------------------------------------------------------
            // Most Recent Action — Compact Summary
            // ------------------------------------------------------------
            const lastAction =
                systemActivity.actions?.lastAction;

            const lastActionSummaryEl =
                this.instance.root.querySelector(
                    '#activityLastActionSummary'
                );

            if (lastActionSummaryEl) {

                const who =
                    lastAction?.contactName || '—';

                const what =
                    lastAction?.actionName || '—';

                const when =
                    Number.isFinite(lastAction?.actionUnix)
                        ? formatTimestamp(lastAction.actionUnix)
                        : '—';

                lastActionSummaryEl.textContent =
                    `${who} • ${what} • ${when}`;
            }
        }
    },
    // Show handler
    onShow() {
        // Apply Highlights Density mode
        applyHighlightsDensity(this.instance.root)
        if (this.tipElement) {
            loadAndRenderSkyesoftTip(this.tipElement);
        } else {
            console.warn("[TodaysHighlightsCard.onShow] tipElement is null");
        }

        // Update footer from versions metadata
        const footerText = resolveCardFooter(this.id);
        if (footerText && this.instance?.footer) {
            this.instance.footer.innerHTML = renderLiveFooter({ text: footerText });
        }
    },
    // Hide handler
    onHide() {}
};
// KPI Card
const KPICard = {
    id: 'kpi-dashboard',
    icon: '📊',
    title: 'Permit KPIs',
    durationMs: DEFAULT_CARD_DURATION_MS,
    instance: null,
    lastSignature: null,
    // Create
    create() {
        // Instance
        this.instance = createGenericCardElement(this);

        // Inner HTML
        this.instance.content.innerHTML = `
            <div class="highlights-grid kpi-grid">

                <!-- LEFT COLUMN -->
                <div class="highlights-col">

                    <div class="entry section-header">
                        <span aria-hidden="true">📌</span> At a Glance
                    </div>

                    <div class="entry kpi-row kpi-total">
                        <span>📦 Active Applications</span>
                        <strong id="kpiTotalPermits">—</strong>
                    </div>

                    <!--
                        Database-authoritative Stage / Status rows
                        are rendered dynamically during update().
                    -->
                    <div id="kpiStageStatusBreakdown"></div>

                </div>

                <!-- RIGHT COLUMN -->
                <div class="highlights-col">

                    <div class="entry section-header">
                        📈 Performance
                    </div>

                    <div class="entry kpi-row">
                        <span>Avg Notes per Application</span>
                        <strong id="kpiAvgNotes">—</strong>
                    </div>

                    <div class="entry kpi-row">
                        <span>Avg Turnaround</span>
                        <strong id="kpiAvgTurnaround">—</strong>
                    </div>

                    <div class="entry section-header">
                        📋 Workload
                    </div>

                    <div class="entry kpi-row">
                        <span>Oldest Open Application</span>
                        <strong id="kpiOldestOpen">—</strong>
                    </div>

                    <div class="entry kpi-row">
                        <span>Outstanding Fees</span>
                        <strong id="kpiOutstandingFees">—</strong>
                    </div>

                    <div class="entry kpi-row">
                        <span>Active Requirements</span>
                        <strong id="kpiActiveRequirements">—</strong>
                    </div>

                    <div class="entry kpi-row">
                        <span>Most Active Jurisdiction</span>
                        <strong id="kpiTopJurisdiction">—</strong>
                    </div>

                    <div class="entry section-header">
                        🕒 Last Permit Activity
                    </div>

                    <div class="entry compact" id="kpiLastPermitSummary">
                        —
                    </div>

                </div>

            </div>
        `;

        // Return
        return this.instance.root;
    },
    // Update
    update(payload) {
        // ── Guard: require KPI payload ──
        if (!payload?.kpi) return;
        if (!this.instance || !this.instance.root) return;

        /* ─────────────────────────────
        TOTAL APPLICATIONS (authoritative)
        ───────────────────────────── */
        const totalEl = this.instance.root.querySelector('#kpiTotalPermits');

        if (totalEl) {
            const total = payload.kpi.atAGlance?.totalActive;

            totalEl.textContent = Number.isInteger(total)
                ? total
                : '—';
        }

        /* ─────────────────────────────
        STAGE / STATUS BREAKDOWN
        Database-authoritative
        ───────────────────────────── */
        const breakdownEl = this.instance.root.querySelector(
            '#kpiStageStatusBreakdown'
        );

        const stageStatusBreakdown =
            payload.kpi.stageStatusBreakdown || {};

        if (breakdownEl) {
            breakdownEl.innerHTML = '';

            Object.entries(stageStatusBreakdown).forEach(
                ([stageStatus, value]) => {

                    if (!Number.isInteger(value) || value <= 0) {
                        return;
                    }

                    const separatorIndex = stageStatus.lastIndexOf(' / ');

                    const stage =
                        separatorIndex >= 0
                            ? stageStatus.slice(0, separatorIndex)
                            : stageStatus;

                    const status =
                        separatorIndex >= 0
                            ? stageStatus.slice(separatorIndex + 3)
                            : '';

                    const row = document.createElement('div');
                    row.className = 'entry kpi-row';

                    row.innerHTML = `
                        <span class="kpi-label-wrap">
                            ${getStatusIcon(stage)}
                            ${stage}${status ? ` — ${status}` : ''}
                        </span>
                        <strong>${value}</strong>
                    `;

                    breakdownEl.appendChild(row);
                }
            );
        }

        /* ─────────────────────────────
        PERFORMANCE (placeholder-safe)
        ───────────────────────────── */
        const notesEl = this.instance.root.querySelector('#kpiAvgNotes');
        const avgNotes = payload.kpi.performance?.averageNotesPerPermit;

        if (notesEl) {
            notesEl.textContent = Number.isFinite(avgNotes)
                ? avgNotes.toFixed(1)
                : '—';
        }

        const turnEl = this.instance.root.querySelector('#kpiAvgTurnaround');
        const avgDays = payload.kpi.atAGlance?.averageTurnaroundDays;

        if (turnEl) {
            turnEl.textContent = Number.isFinite(avgDays)
                ? `${avgDays.toFixed(1)} days`
                : '—';
        }

        /* ─────────────────────────────
        WORKLOAD
        Database-authoritative
        ───────────────────────────── */
        const workload = payload.kpi.workload || {};

        const oldestEl =
            this.instance.root.querySelector('#kpiOldestOpen');

        const oldest =
            workload.oldestOpenApplication;

        if (oldestEl) {
            oldestEl.textContent =
                oldest?.wo && Number.isFinite(oldest?.ageDays)
                    ? `WO ${oldest.wo} — ${oldest.ageDays.toFixed(1)} days`
                    : '—';
        }

        const feesEl =
            this.instance.root.querySelector('#kpiOutstandingFees');

        if (feesEl) {
            const feeCount =
                workload.applicationsWithOutstandingFees;

            const feeTotal =
                workload.totalOutstandingFees;

            feesEl.textContent =
                Number.isInteger(feeCount) &&
                Number.isFinite(feeTotal)
                    ? `${feeCount} application${feeCount !== 1 ? 's' : ''} — $${feeTotal.toFixed(2)}`
                    : '—';
        }

        const requirementsEl =
            this.instance.root.querySelector('#kpiActiveRequirements');

        if (requirementsEl) {
            const requirementCount =
                workload.applicationsWithActiveRequirements;

            requirementsEl.textContent =
                Number.isInteger(requirementCount)
                    ? `${requirementCount} application${requirementCount !== 1 ? 's' : ''}`
                    : '—';
        }

        const jurisdictionEl =
            this.instance.root.querySelector('#kpiTopJurisdiction');

        if (jurisdictionEl) {
            const top =
                workload.mostActiveJurisdiction;

            jurisdictionEl.textContent =
                top?.jurisdiction && Number.isInteger(top?.count)
                    ? `${top.jurisdiction} — ${top.count}`
                    : '—';
        }

        /* ─────────────────────────────
        LAST PERMIT ACTIVITY
        Database-authoritative
        ───────────────────────────── */
        const lastPermitActivity =
            payload.kpi.lastPermitActivity;

        const lastPermitSummaryEl =
            this.instance.root.querySelector(
                '#kpiLastPermitSummary'
            );

        if (lastPermitSummaryEl) {

            const who =
                lastPermitActivity?.contactName || '—';

            const what =
                lastPermitActivity?.actionName || '—';

            const wo =
                lastPermitActivity?.wo || null;

            const customer =
                lastPermitActivity?.customer || '';

            const permit =
                wo
                    ? `WO ${wo}${customer ? ` ${customer}` : ''}`
                    : '—';

            const when =
                Number.isFinite(lastPermitActivity?.actionUnix)
                    ? formatTimestamp(lastPermitActivity.actionUnix)
                    : '—';

            lastPermitSummaryEl.textContent =
                `${who} • ${what} • ${permit} • ${when}`;
        }

        /* ─────────────────────────────
        FOOTER (meta-authoritative)
        ───────────────────────────── */
        if (this.instance.footer && payload.kpi.meta?.generatedOn) {
            const updatedUnix = payload.kpi.meta.generatedOn;
            const nowUnix = payload?.timeDateArray?.currentUnixTime;

            const relative = nowUnix
                ? humanizeRelativeTime(updatedUnix, nowUnix)
                : formatTimestamp(updatedUnix);

            const absolute = formatTimestamp(updatedUnix);

            this.instance.footer.innerHTML = renderLiveFooter({
                text: `KPI snapshot updated ${absolute} (${relative})`
            });
        }
    },
    // On Show
    onShow() {
        const updatedUnix = lastBoardPayload?.kpi?.meta?.generatedOn;
        if (!updatedUnix || !this.instance?.footer) return;

        const nowUnix = lastBoardPayload?.timeDateArray?.currentUnixTime;
        const relative = nowUnix
            ? humanizeRelativeTime(updatedUnix, nowUnix)
            : formatTimestamp(updatedUnix);

        const absolute = formatTimestamp(updatedUnix);

        this.instance.footer.innerHTML = renderLiveFooter({
            text: `KPI snapshot updated ${absolute} (${relative})`
        });
    },
    // On Hide
    onHide() {}
};
// Permit News Card
const PermitNewsCard = {
    id: 'permit-news',
    icon: '📰',
    title: 'Permit News',
    durationMs: DEFAULT_CARD_DURATION_MS,
    instance: null,
    lastSignature: null,
    lastCelebratedSignature: null,

    // Create
    create() {
        this.lastSignature = null;

        this.instance = createGenericCardElement(this);

        this.instance.content.innerHTML = `
            <div class="highlights-grid">
                <div class="highlights-col">

                    <div class="entry section-header">
                        🌐 Latest Permit News
                    </div>

                    <div class="entry" id="permitNewsEntry">
                        <div class="entry-title" id="permitNewsHeadline">
                            Permit News
                        </div>

                        <div class="entry-body" id="permitNewsBody">
                            —
                        </div>
                    </div>

                    <div class="entry section-header">
                        📊 Permit Snapshot
                    </div>

                    <div class="highlights-grid permit-news-snapshot-grid">

                        <div class="highlights-col">

                            <div class="entry">
                                <div class="entry-title">
                                    Active Applications
                                </div>
                                <div class="entry-body" id="permitNewsActiveCount">
                                    —
                                </div>
                            </div>

                            <div class="entry">
                                <div class="entry-title">
                                    Outstanding Fees
                                </div>
                                <div class="entry-body" id="permitNewsOutstandingFees">
                                    —
                                </div>
                            </div>

                            <div class="entry">
                                <div class="entry-title">
                                    Most Active Jurisdiction
                                </div>
                                <div class="entry-body" id="permitNewsJurisdiction">
                                    —
                                </div>
                            </div>

                        </div>

                        <div class="highlights-col">

                            <div class="entry">
                                <div class="entry-title">
                                    Oldest Open Application
                                </div>
                                <div class="entry-body" id="permitNewsOldestOpen">
                                    —
                                </div>
                            </div>

                            <div class="entry">
                                <div class="entry-title">
                                    Active Requirements
                                </div>
                                <div class="entry-body" id="permitNewsActiveRequirements">
                                    —
                                </div>
                            </div>

                            <div class="entry">
                                <div class="entry-title">
                                    Stage Mix
                                </div>
                                <div class="entry-body" id="permitNewsStageMix">
                                    —
                                </div>
                            </div>

                        </div>

                    </div>

                </div>
            </div>
        `;

        if (lastBoardPayload?.permitNews) {
            this.update(lastBoardPayload);
        }

        return this.instance.root;
    },

    // Internal footer renderer
    renderFooter(payload, newsMeta) {
        if (!this.instance?.footer) return;

        const meta =
            newsMeta ||
            payload?.permitNews?.meta ||
            null;

        const updatedUnix =
            meta?.generatedAt ??
            null;

        if (!updatedUnix) {
            this.instance.footer.innerHTML = renderLiveFooter({
                text: 'Permit news awaiting current data'
            });
            return;
        }

        const nowUnix =
            payload?.timeDateArray?.currentUnixTime ??
            Math.floor(Date.now() / 1000);

        const absolute =
            formatTimestamp(updatedUnix);

        const relative =
            humanizeRelativeTime(
                updatedUnix,
                nowUnix
            );

        this.instance.footer.innerHTML = renderLiveFooter({
            text: `AI-generated from live permit data • Updated ${absolute} (${relative})`
        });
    },

    // Update
    update(payload) {
        if (
            !payload?.permitNews ||
            !this.instance?.root
        ) {
            return;
        }

        const news =
            payload.permitNews;

        const meta =
            news.meta || {};

        const headline =
            news.headline || {};

        const signature =
            meta.signature || null;

        const titleEl =
            this.instance.root.querySelector(
                '#permitNewsHeadline'
            );

        const bodyEl =
            this.instance.root.querySelector(
                '#permitNewsBody'
            );

        const entryEl =
            this.instance.root.querySelector(
                '#permitNewsEntry'
            );

        if (
            !titleEl ||
            !bodyEl ||
            !entryEl
        ) {
            return;
        }

        // --------------------------------------------------------
        // Update Narrative Only When Story Changes
        // --------------------------------------------------------
        if (
            !signature ||
            signature !== this.lastSignature
        ) {
            this.lastSignature =
                signature;

            titleEl.textContent =
                headline.headline ||
                'Permit News';

            bodyEl.textContent =
                headline.body ||
                'No current permit news available.';

            entryEl.dataset.storyType =
                meta.storyType || 'general';
        }

        // --------------------------------------------------------
        // Permit Snapshot
        // --------------------------------------------------------

        const kpi =
            payload?.kpi || {};

        const workload =
            kpi.workload || {};

        const activeCountEl =
            this.instance.root.querySelector(
                '#permitNewsActiveCount'
            );

        const oldestOpenEl =
            this.instance.root.querySelector(
                '#permitNewsOldestOpen'
            );

        const outstandingFeesEl =
            this.instance.root.querySelector(
                '#permitNewsOutstandingFees'
            );

        const activeRequirementsEl =
            this.instance.root.querySelector(
                '#permitNewsActiveRequirements'
            );

        const jurisdictionEl =
            this.instance.root.querySelector(
                '#permitNewsJurisdiction'
            );

        const stageMixEl =
            this.instance.root.querySelector(
                '#permitNewsStageMix'
            );

        if (activeCountEl) {
            activeCountEl.textContent =
                `${kpi?.atAGlance?.totalActive ?? 0}`;
        }

        if (oldestOpenEl) {

            const oldest =
                workload.oldestOpenApplication;

            oldestOpenEl.textContent =
                oldest
                    ? `WO ${oldest.wo} — ${oldest.ageDays} days`
                    : 'None';
        }

        if (outstandingFeesEl) {

            const feeCount =
                workload.applicationsWithOutstandingFees ?? 0;

            const feeTotal =
                Number(
                    workload.totalOutstandingFees ?? 0
                );

            outstandingFeesEl.textContent =
                `${feeCount} application${feeCount === 1 ? '' : 's'} — $${feeTotal.toFixed(2)}`;
        }

        if (activeRequirementsEl) {
            activeRequirementsEl.textContent =
                `${workload.applicationsWithActiveRequirements ?? 0}`;
        }

        if (jurisdictionEl) {

            const jurisdiction =
                workload.mostActiveJurisdiction;

            jurisdictionEl.textContent =
                jurisdiction?.jurisdiction
                    ? `${jurisdiction.jurisdiction} — ${jurisdiction.count}`
                    : '—';
        }

        if (stageMixEl) {

            const stages =
                kpi.stageBreakdown || {};

            stageMixEl.textContent =
                Object.entries(stages)
                    .map(([stage, count]) => {
                        const shortStage =
                            stage === 'Pre-Submittal'
                                ? 'Pre'
                                : stage === 'Jurisdiction Review'
                                    ? 'Review'
                                    : stage === 'Approval / Issuance'
                                        ? 'Approval'
                                        : stage;

                        return `${count} ${shortStage}`;
                    })
                    .join(' • ') || '—';
        }

        // --------------------------------------------------------
        // Celebration State
        // --------------------------------------------------------
        if (
            meta.celebrate === true &&
            signature &&
            signature !== this.lastCelebratedSignature
        ) {
            this.lastCelebratedSignature =
                signature;

            entryEl.classList.add(
                'permit-news-celebration'
            );

            setTimeout(() => {
                entryEl.classList.remove(
                    'permit-news-celebration'
                );
            }, 5000);
        }

        // --------------------------------------------------------
        // Footer
        // --------------------------------------------------------
        this.renderFooter(
            payload,
            meta
        );
    },

    // On Show
    onShow() {
        if (!lastBoardPayload?.permitNews) return;

        this.update(
            lastBoardPayload
        );
    },

    // On Hide
    onHide() {}
};
// Board Cards (Push Active Permts Card)
BOARD_CARDS.push(ActivePermitsCard);
// Board Cards (Push Today's Highlights Card)
BOARD_CARDS.push(TodaysHighlightsCard);
// Board Cards (Push KPI Card)
BOARD_CARDS.push(KPICard);
// Board Cards (Push Permit News Card)
BOARD_CARDS.push(PermitNewsCard);
// Update All Cards Function
function updateAllCards(payload) {
    lastBoardPayload = payload;
    BOARD_CARDS.forEach(card => {
        if (typeof card.update === 'function') {
            card.update(payload);
        }
    });
}
// #endregion

// #region AUTO-SCROLL

window.SkyOfficeBoard = window.SkyOfficeBoard || {};

window.SkyOfficeBoard.autoScroll = {
    timer: null,
    running: false,
    FPS: 60,

    start(el, duration = DEFAULT_CARD_DURATION_MS) {
        if (!el || this.running) return;
        const distance = el.scrollHeight - el.clientHeight;
        if (distance <= 0) return;
        const frames = Math.max(1, Math.round(duration / (1000 / this.FPS)));
        const speed = distance / frames;
        el.scrollTop = 0;
        this.running = true;

        const step = () => {
            if (!this.running) return;
            el.scrollTop += speed;
            if (el.scrollTop >= distance) {
                el.scrollTop = distance;
                this.running = false;
                return;
            }
            this.timer = requestAnimationFrame(step);
        };
        this.timer = requestAnimationFrame(step);
    },

    stop() {
        if (this.timer) cancelAnimationFrame(this.timer);
        this.timer = null;
        this.running = false;
    }
};

// #endregion

// #region ROTATION CONTROLLER

let currentIndex = 0;
let rotationTimer = null;

function showCard(index) {
    const host = document.getElementById('boardCardHost');
    if (!host) return;

    stopCardCountdown();
    BOARD_CARDS.forEach(c => c.onHide?.());

    host.innerHTML = '';
    const card = BOARD_CARDS[index];
    if (!card) return;

    const element = card.create();
    host.appendChild(element);

    // Start visible countdown in header
    const countdownEl = element.querySelector('.cardCountdown');
    startCardCountdown(card.durationMs || DEFAULT_CARD_DURATION_MS, countdownEl);

    requestAnimationFrame(() => {
        if (lastBoardPayload && typeof card.update === 'function') {
            card.update(lastBoardPayload);
        }
        card.onShow?.();
    });

    rotationTimer = setTimeout(() => {
        currentIndex = (index + 1) % BOARD_CARDS.length;
        showCard(currentIndex);
    }, card.durationMs || DEFAULT_CARD_DURATION_MS);
}

// #endregion

// #region PAGE CONTROLLER

window.SkyOfficeBoard = {
    ...window.SkyOfficeBoard,

    dom: { card: null, weather: null, time: null, interval: null, version: null },

    start() { this.init(); },
    // Initialize DOM references and start rotation
    init() {
        this.dom.pageBody  = document.getElementById('boardCardHost');
        this.dom.weather   = document.getElementById('headerWeather');
        this.dom.time      = document.getElementById('headerTime');
        this.dom.interval  = document.getElementById('headerInterval');
        this.dom.version   = document.getElementById('versionFooter');

        // Safe placeholder only
        if (this.dom.version) {
            this.dom.version.textContent = 'v—';
        }

        if (!this.dom.pageBody) return;

        if (!lastBoardPayload) {
            lastBoardPayload = buildInitialTimePayload();
        }

        showCard(0);
        updateAllCards(lastBoardPayload);

        // If SSE already arrived before init
        if (window.SkyeApp?.lastSSE) {
            lastBoardPayload = window.SkyIndex.lastSSE;
           // updateAllCards(lastBoardPayload);
        }
        
        // =====================================================
        // 📡 GLOBAL SSE START (OFFICE BOARD)
        // =====================================================
        console.log('[OfficeBoard INIT] Starting SSE');

        if (window.SkySSE) {
            window.SkySSE.start();
        } else {
            console.error('[OfficeBoard INIT] SkySSE not found');
        }
    },

    updatePermitTable(activePermits) {
        lastBoardPayload = {
            ...lastBoardPayload,
            activePermits
        };
        updateAllCards(lastBoardPayload);
    },
    
    // SSE Handler
    onSSE(payload) {
        // Update global payload reference
        lastBoardPayload = payload;

        // 📦 Version Footer (authoritative SSE)
        if (this.dom?.version && payload.siteMeta) {
            this.dom.version.innerHTML =
                formatVersionFooter(payload.siteMeta);
        }

        // 🔔 Update notice — deploy signal
        if (payload.siteMeta?.updateOccurred === true) {
            window.OfficeBoardVersion?.show(60000);
        } else {
            window.OfficeBoardVersion?.hide();
        }

        // 🌤 Weather
        if (payload.weather && this.dom?.weather) {
            const temp = payload.weather.temp;
            const cond = payload.weather.condition;
            this.dom.weather.textContent =
                Number.isFinite(temp)
                    ? `${temp}°F — ${cond}`
                    : cond;
        }

        // ⏰ Time (HH:MM:SS AM/PM with leading zeros)
        if (payload.timeDateArray?.currentUnixTime && this.dom?.time) {
            const d = new Date(payload.timeDateArray.currentUnixTime * 1000);

            const hh = String(d.getHours() % 12 || 12).padStart(2, '0');
            const mm = String(d.getMinutes()).padStart(2, '0');
            const ss = String(d.getSeconds()).padStart(2, '0');
            const ampm = d.getHours() >= 12 ? 'PM' : 'AM';

            this.dom.time.textContent = `${hh}:${mm}:${ss} ${ampm}`;
        }

        // ⏳ Interval — label + remaining time (DD HH MM SS, padded, no leading nulls)
        if (payload.currentInterval && this.dom?.interval) {
            const { key, secondsRemainingInterval } = payload.currentInterval;

            const labelMap = {
                beforeWork: 'Before Work',
                worktime:   'Worktime',
                afterWork:  'After Work',
                weekend:    'Weekend',
                holiday:    'Holiday'
            };

            const label = labelMap[key] ?? key;

            if (Number.isFinite(secondsRemainingInterval)) {
                const timeStr = formatIntervalDHMS(secondsRemainingInterval);
                this.dom.interval.textContent = `${label} - ${timeStr}`;
            } else {
                // Fallback: just the label
                this.dom.interval.textContent = label;
            }
        }

        // Continue normal updates
        updateAllCards(payload);
    }

};

// #endregion

// #region REGISTER

window.SkyeApp.registerPage('officeBoard', window.SkyOfficeBoard);

// #endregion