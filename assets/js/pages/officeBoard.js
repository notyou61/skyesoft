/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Unified Card Model – 2026 refactored edition
   Phoenix, Arizona – MST timezone
*/

// #region GLOBAL REGISTRIES (unchanged)

let jurisdictionRegistry = null;
let permitRegistryMeta = null;
let latestActivePermits = [];
let iconMap = null;
let lastBoardPayload = null; // 🔁 cache most recent SSE payload

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

fetch('https://www.skyelighting.com/skyesoft/data/runtimeEphemeral/permitRegistry.json', { cache: 'no-cache' })
    .then(res => res.ok ? res.json() : Promise.reject(`HTTP ${res.status}`))
    .then(data => {
        permitRegistryMeta = data.meta || null;
        console.log('✅ Permit registry meta loaded', permitRegistryMeta);
        if (latestActivePermits.length > 0) window.SkyOfficeBoard?.updatePermitTable?.(latestActivePermits);
    })
    .catch(err => {
        console.error('❌ Failed to load permitRegistry.json', err);
        permitRegistryMeta = null;
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

// #endregion

// #region HELPERS

// ─────────────────────────────────────────────
// Skyesoft Tips Controller (single source of truth)
// ─────────────────────────────────────────────
window.glbVar = window.glbVar || {};
window.glbVar.tips = [];
window.glbVar.tipsLoaded = false;

function getStatusIcon(status) {
    if (!status) return '';
    const s = status.toLowerCase();
    const keyMap = {
        'under_review':     'clock',
        'need_to_submit':   'warning',
        'submitted':        'clipboard',
        'ready_to_issue':   'memo',
        'issued':           'shield',
        'finaled':          'trophy',
        'corrections':      'tools'
    };
    const iconKey = keyMap[s];
    if (!iconKey || !iconMap) return '';
    const entry = Object.values(iconMap).find(e =>
        (e.file && e.file.toLowerCase().includes(iconKey)) ||
        (e.alt && e.alt.toLowerCase().includes(iconKey))
    );
    if (!entry) return '';
    if (entry.emoji) return entry.emoji + ' ';
    if (entry.file) {
        const url = `https://www.skyelighting.com/skyesoft/assets/images/icons/${entry.file}`;
        return `<img src="${url}" alt="${entry.alt || 'status icon'}" style="width:16px; height:16px; vertical-align:middle; margin-right:4px;">`;
    }
    return '';
}

function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);
    const days    = Math.floor(sec / 86400); sec %= 86400;
    const hours   = Math.floor(sec / 3600);  sec %= 3600;
    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;
    if (days > 0)    return `${days}d ${hours}h ${minutes}m ${seconds}s`;
    if (hours > 0)   return `${hours}h ${minutes}m ${seconds}s`;
    if (minutes > 0) return `${minutes}m ${seconds}s`;
    return `${seconds}s`;
}

function formatTimestamp(ts) {
    if (!ts) return '--/--/-- --:--';
    const date = new Date(ts * 1000);
    const opts = {
        timeZone: 'America/Phoenix',
        month: '2-digit', day: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: true
    };
    return date.toLocaleString('en-US', opts).replace(',', '');
}

function getDateFromSSE(payload) {
    const ts = payload?.timeDateArray?.currentUnixTime;
    if (!ts) return null;
    return new Date(ts * 1000);
}

function calculateDaylight(sunrise, sunset) {
    if (!sunrise || !sunset) return null;
    const toMinutes = t => {
        const d = new Date(`1970-01-01 ${t}`);
        return d.getHours() * 60 + d.getMinutes();
    };
    const minutes = toMinutes(sunset) - toMinutes(sunrise);
    return minutes > 0 ? formatSmartInterval(minutes * 60) : null;
}

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

function renderTodaysHighlightsSkeleton() {
    return `
        <div class="entry">
            📅 <span id="todaysDate">—</span>
            &nbsp;|&nbsp;
            🗓️ Day <span id="dayOfYear">—</span>
            (<span id="daysRemaining">—</span> remaining)
        </div>

        <div class="entry">
            🌄 Sunrise: <span id="sunriseTime">—</span>
            &nbsp;|&nbsp;
            🌇 Sunset: <span id="sunsetTime">—</span>
        </div>

        <div class="entry">
            🕒 Daylight: <span id="daylightTime">—</span>
            &nbsp;|&nbsp;
            🌌 Night: <span id="nightTime">—</span>
        </div>

        <div class="entry">
            🎉 Next Holiday: <span id="nextHoliday">—</span>
        </div>

        <hr>

        <div class="entry" id="skyesoftTips">
            💡 Skyesoft Tip: —
        </div>
    `;
}

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

// Updated: accepts element parameter (preferred) or falls back to getElementById
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
        // Optional fallback display
        el.textContent = '💡 Skyesoft Tip: Double-check drawings before submission.';
    })
    .finally(() => {
        window.glbVar.tipsLoading = false;
    });
}

function renderRandomTip(el) {
    const tips = window.glbVar.tips;
    if (!tips.length) return;

    const tip = tips[Math.floor(Math.random() * tips.length)];
    if (!tip?.text) return;

    el.textContent = `💡 Skyesoft Tip: ${tip.text}`;
}

function buildInitialTimePayload() {
    const now = new Date();
    return {
        time: {
            now: Math.floor(now.getTime() / 1000)
        }
    };
}

function formatPhoenixTimeFromUnix(unixSeconds) {
    if (!unixSeconds) return '—';
    return new Date(unixSeconds * 1000).toLocaleTimeString('en-US', {
        timeZone: 'America/Phoenix',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

// #endregion

// #region LIVE FOOTER HELPER (unchanged)

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

const DEFAULT_CARD_DURATION_MS = 15000;

// #endregion

// #region CARD FACTORY (unchanged)

function createActivePermitsCardElement() {
    const card = document.createElement('section');
    card.className = 'card card-active-permits';
    card.innerHTML = `
        <div class="cardHeader"><h2>📋 Active Permits</h2></div>
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

function createGenericCardElement(spec) {
    const card = document.createElement('section');
    card.className = `card card-${spec.id}`;
    card.innerHTML = `
        <div class="cardHeader">
            <h2>${spec.icon || '✨'} ${spec.title}</h2>
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

const BOARD_CARDS = [];

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

        if (signature === this.lastSignature) {
            if (footer && permitRegistryMeta?.updatedOn) {
                footer.innerHTML = renderLiveFooter({
                    text: `${permits.length} active permit${permits.length !== 1 ? 's' : ''} • Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}`
                });
            }
            return;
        }

        this.lastSignature = signature;
        body.innerHTML = '';

        if (permits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No active permits</td></tr>`;
            footer && (footer.textContent = 'No permits found');
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
                <td>${getStatusIcon(p.status)}${formatStatus(p.status)}</td>
            `;
            frag.appendChild(tr);
        });

        body.appendChild(frag);

        if (footer) {
            let text = `${sorted.length} active permit${sorted.length !== 1 ? 's' : ''}`;
            if (permitRegistryMeta?.updatedOn) text += ` • Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}`;
            footer.innerHTML = renderLiveFooter({ text });
        }

        requestAnimationFrame(() => {
            if (this.instance?.scrollWrap) {
                window.SkyOfficeBoard.autoScroll.start(this.instance.scrollWrap, this.durationMs);
            }
        });
    },

    onShow() {},
    onHide() {
        window.SkyOfficeBoard.autoScroll.stop();
    }
};

BOARD_CARDS.push(ActivePermitsCard);

const TodaysHighlightsCard = {
    id: 'todays-highlights',
    icon: '🌅',
    title: 'Today’s Highlights',
    durationMs: DEFAULT_CARD_DURATION_MS,

    instance: null,
    tipElement: null,  // ← NEW: direct reference to the tip <div>

    create() {
        this.instance = createGenericCardElement(this);
        this.instance.content.innerHTML = renderTodaysHighlightsSkeleton();

        // Capture reference immediately — synchronous and reliable
        this.tipElement = this.instance.content.querySelector('#skyesoftTips');

        if (!this.tipElement) {
            console.warn("[TodaysHighlightsCard] #skyesoftTips not found after create()");
        }

        return this.instance.root;
    },

    update(payload) {
        if (!payload) return;
        updateHighlightsCard(payload);
    },

    onShow() {
        if (this.tipElement) {
            loadAndRenderSkyesoftTip(this.tipElement);
        } else {
            console.warn("[TodaysHighlightsCard.onShow] tipElement is null — element missing");
        }
    },

    onHide() {}
};

BOARD_CARDS.push(TodaysHighlightsCard);

const GENERIC_CARD_SPECS = [
    { id: 'kpi-dashboard', icon: '📈', title: 'Key Performance Indicators' },
    { id: 'announcements', icon: '📢', title: 'Announcements' }
];

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
            if (this.instance?.footer) {
                this.instance.footer.innerHTML = renderLiveFooter({
                    text: `Updated ${formatTimestamp(Date.now() / 1000)}`
                });
            }
        },

        onShow() {},
        onHide() {}
    });
});

function updateAllCards(payload) {
    lastBoardPayload = payload;
    BOARD_CARDS.forEach(card => {
        if (typeof card.update === 'function') {
            card.update(payload);
        }
    });
}

// #endregion

// #region AUTO-SCROLL (unchanged)

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

    BOARD_CARDS.forEach(c => c.onHide?.());

    host.innerHTML = '';
    const card = BOARD_CARDS[index];
    if (!card) return;

    const element = card.create();
    host.appendChild(element);

    requestAnimationFrame(() => {
        if (lastBoardPayload && typeof card.update === 'function') {
            console.log(`[showCard] Replaying payload to ${card.id}`);
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