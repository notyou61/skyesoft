/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Unified Card Model – 2026 refactored edition (bugfix)
   Phoenix, Arizona – MST timezone
*/

// #region GLOBAL REGISTRIES

let jurisdictionRegistry = null;
let permitRegistryMeta = null;
let latestActivePermits = [];
let iconMap = null;
let lastBoardPayload = null;
let versionsMeta = null;

let countdownTimer = null;
let countdownRemainingMs = 0;
let activeCountdownEl = null;

const PERMIT_STATUSES = [
    'need_to_submit', 'submitted', 'qc_passed', 'under_review', 'corrections',
    'ready_to_issue', 'issued', 'inspections', 'finaled'
];

function resolveJurisdictionLabel(raw) {
    if (!raw || !jurisdictionRegistry) return raw;
    const norm = String(raw).trim().toUpperCase();
    for (const key in jurisdictionRegistry) {
        const entry = jurisdictionRegistry[key];
        if (!entry) continue;
        if (key.toUpperCase() === norm) return entry.label;
        if (Array.isArray(entry.aliases) && entry.aliases.some(a => a.toUpperCase() === norm)) {
            return entry.label;
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

// ── Load registries ────────────────────────────────────────────────────────────

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', { cache: 'no-cache' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
        jurisdictionRegistry = data;
        console.log(`✅ Jurisdiction registry loaded (${Object.keys(data).length} entries)`);
        window.SkyOfficeBoard?.lastPermitSignature && (window.SkyOfficeBoard.lastPermitSignature = null);
    })
    .catch(err => {
        console.error('❌ jurisdictionRegistry.json failed', err);
        jurisdictionRegistry = {};
    });

fetch('https://www.skyelighting.com/skyesoft/data/runtimeEphemeral/permitRegistry.json', { cache: 'no-cache' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
        permitRegistryMeta = data.meta || null;
        console.log('✅ Permit registry meta loaded', permitRegistryMeta);
        if (latestActivePermits.length) window.SkyOfficeBoard?.updatePermitTable?.(latestActivePermits);
    })
    .catch(err => {
        console.error('❌ permitRegistry.json failed', err);
        permitRegistryMeta = null;
    });

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/iconMap.json', { cache: 'no-cache' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
        iconMap = data.icons;
        console.log(`✅ Icon map loaded (${Object.keys(iconMap).length} icons)`);
    })
    .catch(err => {
        console.error('❌ iconMap.json failed', err);
        iconMap = {};
    });

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/versions.json', { cache: 'no-cache' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(data => {
        versionsMeta = data;
        console.log('✅ Versions meta loaded', data);
    })
    .catch(err => {
        console.warn('⚠️ versions.json failed', err);
        versionsMeta = null;
    });

// #endregion HELPERS ─────────────────────────────────────────────────────────────

window.glbVar = window.glbVar || {};
window.glbVar.tips = [];
window.glbVar.tipsLoaded = false;

function getStatusIcon(status) {
    if (!status) return '';
    const s = status.toLowerCase();
    const keyMap = {
        need_to_submit: 'warning', submitted: 'clipboard', qc_passed: 'check',
        under_review: 'clock', corrections: 'tools', ready_to_issue: 'memo',
        issued: 'shield', inspections: 'search', finaled: 'trophy'
    };
    const iconKey = keyMap[s];
    if (!iconKey || !iconMap) return '';

    const entry = Object.values(iconMap).find(e =>
        (e.file?.toLowerCase().includes(iconKey)) || (e.alt?.toLowerCase().includes(iconKey))
    );
    if (!entry) return '';
    if (entry.emoji) return entry.emoji + ' ';
    if (entry.file) {
        return `<img src="https://www.skyelighting.com/skyesoft/assets/images/icons/${entry.file}" alt="${entry.alt || 'icon'}" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">`;
    }
    return '';
}

function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);
    const d = Math.floor(sec / 86400); sec %= 86400;
    const h = Math.floor(sec / 3600);  sec %= 3600;
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    if (d) return `${d}d ${h}h ${m}m ${s}s`;
    if (h) return `${h}h ${m}m ${s}s`;
    if (m) return `${m}m ${s}s`;
    return `${s}s`;
}

function formatTimestamp(ts) {
    if (!ts) return '--/--/-- --:--';
    return new Date(ts * 1000).toLocaleString('en-US', {
        timeZone: 'America/Phoenix',
        month: '2-digit', day: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: true
    }).replace(',', '');
}

function formatPhoenixTimeFromUnix(unix) {
    if (!unix) return '—';
    return new Date(unix * 1000).toLocaleTimeString('en-US', {
        timeZone: 'America/Phoenix',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
}

// ... (rest of helper functions remain the same – getDateFromSSE, calculateDaylight, getLiveDateInfoFromSSE, renderTodaysHighlightsSkeleton, getSeasonSummaryFromUnix, updateHighlightsCard, loadAndRenderSkyesoftTip, renderRandomTip, mapWeatherIcon, renderThreeDayForecast, startCardCountdown, stopCardCountdown, resolveCardFooter, humanizeRelativeTime, applyHighlightsDensity, getSeasonIcon, renderLiveFooter)

// For brevity I'm not repeating every helper function here again — they are unchanged except for the textContent fixes in updateHighlightsCard below.

function updateHighlightsCard(payload = lastBoardPayload) {
    if (!payload) return;
    const unix = payload?.timeDateArray?.currentUnixTime;
    if (!unix) return;

    const now = new Date(unix * 1000);
    const formattedDate = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

    const startOfYear = new Date(now.getFullYear(), 0, 1);
    const dayOfYear = Math.floor((now - startOfYear) / 86400000) + 1;
    const isLeap = (now.getFullYear() % 4 === 0 && now.getFullYear() % 100 !== 0) || (now.getFullYear() % 400 === 0);
    const daysInYear = isLeap ? 366 : 365;
    const daysRemaining = daysInYear - dayOfYear;

    const dateEl   = document.getElementById('todaysDate');
    const dayEl    = document.getElementById('dayOfYear');
    const remEl    = document.getElementById('daysRemaining');
    if (dateEl) dateEl.textContent = formattedDate;
    if (dayEl)  dayEl.textContent  = dayOfYear;
    if (remEl)  remEl.textContent  = daysRemaining;

    const sunriseEl = document.getElementById('sunriseTime');
    const sunsetEl  = document.getElementById('sunsetTime');
    sunriseEl && (sunriseEl.textContent = formatPhoenixTimeFromUnix(payload?.weather?.sunriseUnix) || '—');
    sunsetEl  && (sunsetEl.textContent  = formatPhoenixTimeFromUnix(payload?.weather?.sunsetUnix)  || '—');

    const dlEl = document.getElementById('daylightTime');
    const ntEl = document.getElementById('nightTime');
    const sr = payload?.weather?.sunriseUnix;
    const ss = payload?.weather?.sunsetUnix;
    if (sr && ss && !isNaN(sr) && !isNaN(ss)) {
        let daylightSec = ss - sr;
        if (daylightSec < 0) daylightSec += 86400;
        const nightSec = 86400 - daylightSec;
        dlEl && (dlEl.textContent = formatSmartInterval(daylightSec));
        ntEl && (ntEl.textContent = formatSmartInterval(nightSec));
    } else {
        dlEl && (dlEl.textContent = '—');
        ntEl && (ntEl.textContent = '—');
    }

    const holEl = document.getElementById('nextHoliday');
    const hol = payload?.holidayState?.nextHoliday;
    if (holEl && hol) {
        holEl.textContent = `${hol.name} (${hol.daysAway} days)`;
    }
}

// ── KPI Card (clean & fixed) ──────────────────────────────────────────────────

const KPICard = {
    id: 'kpi-dashboard',
    icon: '📊',
    title: 'Permit KPIs',
    durationMs: 15000,
    instance: null,

    create() {
        this.instance = createGenericCardElement(this);
        this.instance.content.innerHTML = `
            <div class="kpi-grid two-col">
                <div class="kpi-section at-a-glance">
                    <h3>📌 At a Glance</h3>
                    <div class="kpi-row status-grid">
                        ${PERMIT_STATUSES.map(s => `
                            <div class="kpi-item">
                                <div class="kpi-icon">${getStatusIcon(s)}</div>
                                <div class="kpi-label">${formatStatus(s)}</div>
                                <div class="kpi-value" data-kpi-status="${s}">—</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                <div class="kpi-section performance">
                    <h3>📈 Performance</h3>
                    <div class="kpi-metric">
                        <span class="metric-label">Avg Notes / Permit</span>
                        <span id="kpiAvgNotes" class="metric-value">—</span>
                    </div>
                    <div class="kpi-metric">
                        <span class="metric-label">Avg Turnaround</span>
                        <span id="kpiAvgTurnaround" class="metric-value">—</span>
                    </div>
                </div>
            </div>
        `;
        return this.instance.root;
    },

    update(payload) {
        if (!payload?.kpi?.permits) return;

        const breakdown = payload.kpi.permits.statusBreakdown || {};
        PERMIT_STATUSES.forEach(status => {
            const el = document.querySelector(`[data-kpi-status="${status}"]`);
            if (el) {
                const count = Number(breakdown[status]);
                el.textContent = Number.isInteger(count) ? count : '—';
                el.classList.toggle('zero', count === 0);
            }
        });

        const perf = payload.kpi.permits.performance || {};
        const notesEl = document.getElementById('kpiAvgNotes');
        if (notesEl && Number.isFinite(perf.avgNotesPerPermit)) {
            notesEl.textContent = perf.avgNotesPerPermit.toFixed(1);
        }

        const turnEl = document.getElementById('kpiAvgTurnaround');
        if (turnEl && Number.isFinite(perf.avgTurnaroundSeconds)) {
            turnEl.textContent = formatSmartInterval(perf.avgTurnaroundSeconds);
        }
    },

    onShow() {
        const footerText = resolveCardFooter(this.id);
        if (footerText && this.instance?.footer) {
            this.instance.footer.innerHTML = renderLiveFooter({ text: footerText });
        }
    },

    onHide() {}
};

// ── The rest of the file remains unchanged ─────────────────────────────────────

// (ActivePermitsCard, TodaysHighlightsCard, BOARD_CARDS array population,
//  updateAllCards, autoScroll, rotation controller, page controller, registerPage)

BOARD_CARDS.push(KPICard);
// Generic placeholder cards
const GENERIC_CARD_SPECS = [
    { id: 'announcements', icon: '📢', title: 'Announcements' }
];
// Create generic cards from specs
GENERIC_CARD_SPECS.forEach(spec => {
    BOARD_CARDS.push({
        ...spec,
        durationMs: DEFAULT_CARD_DURATION_MS,
        instance: null,

        create() {
            this.instance = createGenericCardElement(this);
            return this.instance.root;
        },

        update() {
            if (this.instance?.content) {
                this.instance.content.innerHTML = `<p>${this.title} content coming soon</p>`;
            }
            // Footer set in onShow() via versions.json
        },

        onShow() {
            const footerText = resolveCardFooter(this.id);
            if (footerText && this.instance?.footer) {
                this.instance.footer.innerHTML = renderLiveFooter({ text: footerText });
            }
        },

        onHide() {}
    });
});
// Universal updater for all cardss
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

    init() {
        this.dom.pageBody  = document.getElementById('boardCardHost');
        this.dom.weather   = document.getElementById('headerWeather');
        this.dom.time      = document.getElementById('headerTime');
        this.dom.interval  = document.getElementById('headerInterval');
        this.dom.version   = document.getElementById('versionFooter');

        if (!this.dom.pageBody) return;

        if (!lastBoardPayload) {
            lastBoardPayload = buildInitialTimePayload();
        }

        showCard(0);

        updateAllCards(lastBoardPayload);

        if (window.SkyeApp?.lastSSE) {
            lastBoardPayload = window.SkyeApp.lastSSE;
            updateAllCards(lastBoardPayload);
        }
    },

    updatePermitTable(activePermits) {
        lastBoardPayload = {
            ...lastBoardPayload,
            activePermits
        };
        updateAllCards(lastBoardPayload);
    },

    onSSE(payload) {
        lastBoardPayload = payload;
        updateAllCards(payload);
    }
};

// #endregion

// #region REGISTER

window.SkyeApp.registerPage('officeBoard', window.SkyOfficeBoard);

// #endregion