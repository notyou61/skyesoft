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

// #region HELPERS (unchanged)

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

// #endregion

// #region CARD FACTORY (unchanged logic, now used inside card spec)

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

// #endregion

// #region CARD REGISTRY + UNIVERSAL UPDATER

const BOARD_CARDS = [];

const ActivePermitsCard = {
    id: 'active-permits',
    durationMs: 30000,

    instance: null,
    lastSignature: null,  // moved here from global for better isolation

    create() {
        this.instance = createActivePermitsCardElement();
        return this.instance.root;
    },

    update(payload) {
        if (!payload?.activePermits) return;
        latestActivePermits = payload.activePermits || [];

        const body = this.instance?.tableBody;
        const footer = this.instance?.footer;
        if (!body) return;

        const signature = Array.isArray(payload.activePermits)
            ? payload.activePermits.map(p => `${p.wo}|${p.status}|${p.jurisdiction}`).join('::')
            : 'empty';

        if (signature === this.lastSignature) return;
        this.lastSignature = signature;

        body.innerHTML = '';
        if (latestActivePermits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No active permits</td></tr>`;
            footer && (footer.textContent = 'No permits found');
            // No scroll needed when empty
            return;
        }

        const sorted = latestActivePermits.slice().sort(
            (a,b) => (parseInt(a.wo,10)||0) - (parseInt(b.wo,10)||0)
        );

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
            const liveIcon = `
                <img src="https://www.skyelighting.com/skyesoft/assets/images/live-streaming.gif" 
                     alt="Live" style="width:24px;height:24px;vertical-align:middle;margin-right:8px;">
            `;
            text = `${liveIcon}${text}`;
            if (permitRegistryMeta?.updatedOn) {
                text += ` • Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}`;
            }
            footer.innerHTML = text;
        }

        // ────────────────────────────────────────────────
        //  FIXED SCROLL START – moved here so it runs AFTER render
        // ────────────────────────────────────────────────
        requestAnimationFrame(() => {
            if (this.instance?.scrollWrap) {
                window.SkyOfficeBoard.autoScroll.start(
                    this.instance.scrollWrap,
                    this.durationMs
                );
            }
        });
        // ────────────────────────────────────────────────
    },

    onShow() {
        // Keep this for future cards that might need setup on visibility
        // But scroll start is now handled in update() where data is fresh
    },

    onHide() {
        window.SkyOfficeBoard.autoScroll.stop();
    }
};

BOARD_CARDS.push(ActivePermitsCard);

function updateAllCards(payload) {
    BOARD_CARDS.forEach(card => {
        if (typeof card.update === 'function') {
            card.update(payload);
        }
    });
}

// #endregion

// #region AUTO-SCROLL (moved here, still global for simplicity)

window.SkyOfficeBoard = window.SkyOfficeBoard || {};

window.SkyOfficeBoard.autoScroll = {
    timer: null,
    running: false,
    FPS: 60,

    start(el, duration = 30000) {
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

// #region ROTATION CONTROLLER (simple version — only one card today)

let currentIndex = 0;
let rotationTimer = null;

function showCard(index) {
    const host = document.getElementById('boardCardHost');
    if (!host) return;

    // Cleanup previous card
    BOARD_CARDS.forEach(c => c.onHide?.());

    host.innerHTML = '';
    const card = BOARD_CARDS[index];
    if (!card) return;

    const element = card.create();
    host.appendChild(element);

    card.onShow?.();

    rotationTimer = setTimeout(() => {
        currentIndex = (index + 1) % BOARD_CARDS.length;
        showCard(currentIndex);
    }, card.durationMs || 30000);
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

        showCard(0);

        if (window.SkyeApp?.lastSSE) {
            updateAllCards(window.SkyeApp.lastSSE);
        }
    },

    updatePermitTable(activePermits) {
        updateAllCards({ activePermits });
    },

    onSSE(payload) {
        updateAllCards(payload);
    }
};

// #endregion

// #region REGISTER

window.SkyeApp.registerPage('officeBoard', window.SkyOfficeBoard);

// #endregion