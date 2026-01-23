/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Dynamic Card Model – Active Permits only (2026 edition)
   Phoenix, Arizona – MST timezone
*/

// #region GLOBAL REGISTRIES

let jurisdictionRegistry = null;
let permitRegistryMeta = null;

// Resolve jurisdiction label from registry
function resolveJurisdictionLabel(raw) {
    if (!raw || !jurisdictionRegistry) return raw;

    const norm = String(raw).trim().toUpperCase();

    for (const key in jurisdictionRegistry) {
        const entry = jurisdictionRegistry[key];
        if (!entry) continue;

        // Direct key match
        if (key.toUpperCase() === norm) {
            return entry.label;
        }

        // Alias match
        if (Array.isArray(entry.aliases)) {
            if (entry.aliases.map(a => a.toUpperCase()).includes(norm)) {
                return entry.label;
            }
        }
    }

    // Fallback: title-case the raw value
    return norm
        .toLowerCase()
        .replace(/\b\w/g, c => c.toUpperCase());
}

function formatStatus(status) {
    if (!status) return '';

    return String(status)
        .toLowerCase()
        .split('_')
        .map(w => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

// Jurisdiction Registry – loads city display labels
fetch('https://skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', { cache: 'no-cache' })
    .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    })
    .then(data => {
        jurisdictionRegistry = data;
        console.log(`✅ Jurisdiction registry loaded — ${Object.keys(data).length} entries`);
        if (window.SkyOfficeBoard?.lastPermitSignature) {
            window.SkyOfficeBoard.lastPermitSignature = null;
        }
    })
    .catch(err => {
        console.error('❌ Failed to load jurisdictionRegistry.json (CORS likely)', err);
        jurisdictionRegistry = {};
    });

// Permit Registry Meta – total work orders + updated timestamp
fetch('https://skyelighting.com/skyesoft/data/runtimeEphemeral/permitRegistry.json', { cache: 'no-cache' })
    .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    })
    .then(data => {
        permitRegistryMeta = data.meta || null;
        console.log('✅ Permit registry meta loaded', permitRegistryMeta);

        // 🔁 Re-render footer if permits already rendered
        if (window.SkyOfficeBoard?.lastRenderedPermits) {
            window.SkyOfficeBoard.updatePermitTable(
                window.SkyOfficeBoard.lastRenderedPermits
            );
        }
    })
    .catch(err => {
        console.error('❌ Failed to load permitRegistry.json (CORS likely)', err);
        permitRegistryMeta = null;
        applyPermitRegistryFallback();
    });

// #endregion

//  #region SMART INTERVAL FORMATTER
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
//  #endregion

// #region TIMESTAMP FORMATTER 
// Phoenix MST (no DST) – consistent MM/DD/YY hh:mm AM/PM
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
//  #endregion

// #region CARD FACTORY
function createActivePermitsCard() {
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

// #region PAGE CONTROLLER
window.SkyOfficeBoard = {
    dom: { card: null, weather: null, time: null, interval: null, version: null },
    lastPermitSignature: null,
    prevPermitLength: 0,

    autoScroll: {
        timer: null, running: false, FPS: 60,
        start(el, duration = 30000) {
            if (!el || this.running) return;
            const distance = el.scrollHeight - el.clientHeight;
            if (distance <= 0) return;
            const frames = Math.max(1, Math.round(duration / (1000 / this.FPS)));
            const speed = distance / frames;
            el.scrollTop = 0; this.running = true;
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
            this.timer = null; this.running = false;
        }
    },

    start() { this.init(); },

    init() {
        this.dom.pageBody = document.getElementById('boardCardHost');
        if (!this.dom.pageBody) return;
        this.dom.weather  = document.getElementById('headerWeather');
        this.dom.time     = document.getElementById('headerTime');
        this.dom.interval = document.getElementById('headerInterval');
        this.dom.version  = document.getElementById('versionFooter');
        this.dom.card = createActivePermitsCard();
        this.dom.pageBody.appendChild(this.dom.card.root);
        if (window.SkyeApp?.lastSSE) this.onSSE(window.SkyeApp.lastSSE);
    },

    updatePermitTable(activePermits) {
        this.lastRenderedPermits = activePermits;
        const body = this.dom.card?.tableBody;
        const footer = this.dom.card?.footer;
        if (!body) return;

        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo}|${p.status}|${p.jurisdiction}`).join('::')
            : 'empty';
        if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        body.innerHTML = '';
        if (!Array.isArray(activePermits) || activePermits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No active permits</td></tr>`;
            if (footer) footer.textContent = 'No permits found';
            this.autoScroll.stop();
            return;
        }

        const sorted = activePermits.slice().sort(
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
                <td>${formatStatus(p.status)}</td>
            `;
            frag.appendChild(tr);
        });
        body.appendChild(frag);

        let footerText = `${sorted.length} active permit${sorted.length !== 1 ? 's' : ''}`;

        if (permitRegistryMeta?.updatedOn) {
            footerText += ` • Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}`;
        }

        if (footer) footer.textContent = footerText;

        requestAnimationFrame(() => {
            this.autoScroll.start(this.dom.card.scrollWrap, 30000);
        });
    },

    onSSE(payload) {
        this.updatePermitTable(payload.activePermits || []);
    }
};
// #endregion

// #region REGISTER
window.SkyeApp.registerPage('officeBoard', window.SkyOfficeBoard);
// #endregion