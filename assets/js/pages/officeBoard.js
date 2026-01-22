/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Dynamic Card Model – Active Permits only (2026 edition)

   Architectural Notes:
   - Card factory defines structure only (no data meaning)
   - SSE drives rendering + meta binding
   - Registry meta is the source of truth for footer stats
   - Table body reflects active / filtered view only
*/

// ────────────────────────────────────────────────
// Global registry reference (loaded async)
// ────────────────────────────────────────────────
let jurisdictionRegistry = null;

fetch('https://skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', {
    cache: 'no-cache'
})
    .then(res => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    })
    .then(data => {
        jurisdictionRegistry = data;
        console.log(`✅ Jurisdiction registry loaded — ${Object.keys(data).length} entries`);

        // Force re-render if permits already rendered before registry arrived
        if (window.SkyOfficeBoard?.lastPermitSignature) {
            window.SkyOfficeBoard.lastPermitSignature = null;
        }
    })
    .catch(err => {
        console.error('❌ Failed to load jurisdictionRegistry.json', err);
        jurisdictionRegistry = {}; // safe fallback
    });

/* #region TIME FORMATTERS */

/* Registry timestamp → MM/DD/YY hh:mm */
function formatTimestampMMDDYY(ts) {
    if (!ts) return "--/--/-- --:--";

    const d = new Date(ts * 1000);

    const mm  = String(d.getMonth() + 1).padStart(2, "0");
    const dd  = String(d.getDate()).padStart(2, "0");
    const yy  = String(d.getFullYear()).slice(-2);
    const hh  = String(d.getHours()).padStart(2, "0");
    const min = String(d.getMinutes()).padStart(2, "0");

    return `${mm}/${dd}/${yy} ${hh}:${min}`;
}

/* Interval countdown formatter (header use) */
function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);

    const days = Math.floor(sec / 86400); sec %= 86400;
    const hours = Math.floor(sec / 3600); sec %= 3600;
    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;

    if (days > 0) return `${days}d ${String(hours).padStart(2,"0")}h ${String(minutes).padStart(2,"0")}m ${String(seconds).padStart(2,"0")}s`;
    if (hours > 0) return `${hours}h ${String(minutes).padStart(2,"0")}m ${String(seconds).padStart(2,"0")}s`;
    if (minutes > 0) return `${minutes}m ${String(seconds).padStart(2,"0")}s`;
    return `${seconds}s`;
}
/* #endregion */

/* #region CARD FACTORY */
function createActivePermitsCard() {
    const card = document.createElement("section");
    card.className = "card card-active-permits";

    card.innerHTML = `
        <div class="cardHeader">
            <h2>📋 Active Permits</h2>
        </div>

        <div class="cardBodyDivider"></div>

        <div class="cardBody">
            <div class="cardContent" id="permitScrollWrap">
                <table class="permit-table">
                    <thead>
                        <tr>
                            <th>WO</th>
                            <th>Customer</th>
                            <th>Jobsite</th>
                            <th>Jurisdiction</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="permitTableBody">
                        <tr>
                            <td colspan="5">Loading permits…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="cardFooterDivider"></div>

        <div class="cardFooter" id="permitFooter">
            Loading…
        </div>
    `;

    return {
        root: card,
        scrollWrap: card.querySelector("#permitScrollWrap"),
        tableBody: card.querySelector("#permitTableBody"),
        footer: card.querySelector("#permitFooter")
    };
}
/* #endregion */

/* #region PAGE CONTROLLER */
window.SkyOfficeBoard = {

    dom: {
        card: null,
        weather: null,
        time: null,
        interval: null,
        version: null
    },

    lastPermitSignature: null,
    prevPermitLength: 0,

    /* #region AUTOSCROLL */
    autoScroll: {
        timer: null,
        running: false,
        FPS: 60,

        start(el, duration = 30000) {
            if (!el) return;
            this.stop();

            const distance = el.scrollHeight - el.clientHeight;
            if (distance <= 0) return;

            const frames = Math.max(1, Math.round((duration * 0.95) / (1000 / this.FPS)));
            const speed = Math.max(0.5, distance / frames);

            el.scrollTop = 0;
            this.running = true;

            const step = () => {
                if (!this.running) return;

                const max = el.scrollHeight - el.clientHeight;
                if (el.scrollTop + speed >= max) {
                    el.scrollTop = max;
                    this.running = false;
                    return;
                }

                el.scrollTop += speed;
                this.timer = requestAnimationFrame(step);
            };

            this.timer = requestAnimationFrame(step);
        },

        stop() {
            if (this.timer) cancelAnimationFrame(this.timer);
            this.timer = null;
            this.running = false;
        }
    },
    /* #endregion */

    /* #region LIFECYCLE */
    start() { this.init(); },

    init() {
        this.dom.pageBody = document.getElementById("boardCardHost");
        if (!this.dom.pageBody) return;

        this.dom.weather  = document.getElementById("headerWeather");
        this.dom.time     = document.getElementById("headerTime");
        this.dom.interval = document.getElementById("headerInterval");
        this.dom.version  = document.getElementById("versionFooter");

        this.dom.card = createActivePermitsCard();
        this.dom.pageBody.appendChild(this.dom.card.root);

        if (window.SkyeApp?.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
    },

    destroy() { this.autoScroll.stop(); },
    /* #endregion */

    /* #region HEADER / GLOBAL FOOTER */
    updateHeader(payload) {
        if (!payload) return;

        if (payload.timeDateArray && this.dom.time) {
            this.dom.time.textContent = payload.timeDateArray.currentLocalTime ?? "--:--:--";
        }

        if (payload.currentInterval && this.dom.interval) {
            const iv = payload.currentInterval;
            const labels = {
                beforeWork: "Workday begins in",
                worktime: "Workday ends in",
                afterWork: "Next workday begins in",
                weekend: "Workday resumes in",
                holiday: "Workday resumes after holiday in"
            };
            const label = labels[iv.key] ?? "Interval ends in";
            this.dom.interval.textContent =
                `${label} ${formatSmartInterval(iv.secondsRemainingInterval)}`;
        }

        if (payload.weather && this.dom.weather) {
            const w = payload.weather;
            this.dom.weather.textContent =
                `${Math.round(w.temp ?? 0)}°F – ${(w.condition ?? "--").replace(/^\w/, c => c.toUpperCase())}`;
        }
    },

    updateFooter(payload) {
        if (payload?.siteMeta && this.dom.version) {
            this.dom.version.textContent = `v${payload.siteMeta.siteVersion ?? "0.0.0"}`;
        }
    },
    /* #endregion */

    /* #region PERMIT TABLE */
    updatePermitTable(activePermits) {

        const body   = this.dom.card?.tableBody;
        const footer = this.dom.card?.footer;
        if (!body) return;

        // Change detection
        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo}|${p.status}|${p.jurisdiction}`).join("::")
            : "empty";

        if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        // Empty state
        if (!Array.isArray(activePermits) || activePermits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No active permits</td></tr>`;
            return;
        }

        const sorted = activePermits.slice().sort(
            (a, b) => (parseInt(a.wo, 10) || 0) - (parseInt(b.wo, 10) || 0)
        );

        body.innerHTML = "";
        const frag = document.createDocumentFragment();

        sorted.forEach(p => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td>${p.wo}</td>
                <td>${p.customer}</td>
                <td>${p.jobsite}</td>
                <td>${p.jurisdiction}</td>
                <td>${p.status}</td>
            `;
            frag.appendChild(tr);
        });

        body.appendChild(frag);

        requestAnimationFrame(() => {
            const el = this.dom.card?.scrollWrap;
            if (el && el.scrollHeight > el.clientHeight) {
                this.autoScroll.start(el, 30000);
            }
        });
    },
    /* #endregion */

    /* #region SSE */
    onSSE(payload) {

        this.updateHeader(payload);

        // ---- Active permits table (filtered view) ----
        if (Array.isArray(payload.activePermits)) {
            this.updatePermitTable(payload.activePermits);
        }

        // ---- Active Permits card footer (registry truth) ----
        const meta = payload.permitRegistry?.meta;
        if (meta && this.dom.card?.footer) {
            const total   = meta.counts?.totalWorkOrders ?? "--";
            const updated = formatTimestampMMDDYY(meta.updatedOn);

            this.dom.card.footer.textContent =
                `Total WOs: ${total} • Updated: ${updated}`;
        }

        this.updateFooter(payload);
    }
    /* #endregion */
};
/* #endregion */

/* #region REGISTER */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */
