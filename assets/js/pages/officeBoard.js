/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Dynamic Card Model – Active Permits only (2026 edition)
*/

// ────────────────────────────────────────────────
// Global registries (loaded async)
// ────────────────────────────────────────────────
let jurisdictionRegistry = null;
let permitRegistryMeta = null;

// Jurisdiction Registry
fetch('https://skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', {
    cache: 'no-cache'
})
    .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    })
    .then(data => {
        jurisdictionRegistry = data;
        console.log(`✅ Jurisdiction registry loaded — ${Object.keys(data).length} entries`);
        if (window.SkyOfficeBoard?.lastPermitSignature) {
            window.SkyOfficeBoard.lastPermitSignature = null;
        }
    })
    .catch(err => {
        console.error('❌ Failed to load jurisdictionRegistry.json', err);
        jurisdictionRegistry = {};
    });

// Permit Registry Meta
fetch('https://skyelighting.com/skyesoft/data/runtimeEphemeral/permitRegistry.json', {
    cache: 'no-cache'
})
    .then(response => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    })
    .then(data => {
        permitRegistryMeta = data.meta || null;
        console.log("✅ Permit registry meta loaded", permitRegistryMeta);
        if (window.SkyOfficeBoard?.lastPermitSignature) {
            window.SkyOfficeBoard.lastPermitSignature = null;
        }
    })
    .catch(err => {
        console.error('❌ Failed to load permitRegistry.json', err);
        permitRegistryMeta = null;
    });

// ────────────────────────────────────────────────
// TEMPORARY WORKAROUND: Hard-code known values until CORS is fixed
// Remove this block once server sends Access-Control-Allow-Origin header
// ────────────────────────────────────────────────
if (!permitRegistryMeta) {
    permitRegistryMeta = {
        counts: {
            totalWorkOrders: 144   // ← update this number manually when it changes
        },
        updatedOn: 1768594417      // ← your last known timestamp from the JSON file
        // You can add more fields later if needed, e.g. statusBreakdown
    };
    console.warn(
        "Using temporary hardcoded permitRegistryMeta due to CORS block. " +
        "Footer will show total and updated time."
    );
}
    

/* #region SMART INTERVAL FORMATTER */
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

/* #region TIMESTAMP FORMATTER (for permit registry updatedOn) */
function formatTimestamp(ts) {
    if (!ts) return "--/--/-- --:--";
    const date = new Date(ts * 1000);
    return date.toLocaleString("en-US", {
        timeZone: "America/Phoenix",
        month: "numeric",
        day: "numeric",
        year: "2-digit",
        hour: "numeric",
        minute: "2-digit",
        hour12: true
    }).replace(/,/, '');
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
    start() {
        this.init();
    },

    init() {
        this.dom.pageBody = document.getElementById("boardCardHost");
        
        if (!this.dom.pageBody) {
            console.warn("officeBoard: boardCardHost not found");
            return;
        }

        this.dom.weather  = document.getElementById("headerWeather");
        this.dom.time     = document.getElementById("headerTime");
        this.dom.interval = document.getElementById("headerInterval");
        this.dom.version  = document.getElementById("versionFooter");

        this.dom.card = createActivePermitsCard();
        this.dom.pageBody.appendChild(this.dom.card.root);

        if (window.SkyeApp?.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
        
        console.log("Active Permits card injected", this.dom.card.root);
    },

    destroy() {
        this.autoScroll.stop();
    },
    /* #endregion */

    /* #region HEADER / FOOTER */
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
            this.dom.interval.textContent = `${label} ${formatSmartInterval(iv.secondsRemainingInterval)}`;
        }

        if (payload.weather && this.dom.weather) {
            const w = payload.weather;
            const temp = Math.round(w.temp ?? 0);
            const cond = (w.condition ?? "--").replace(/^\w/, c => c.toUpperCase());
            this.dom.weather.textContent = `${temp}°F – ${cond}`;
        }
    },

    updateFooter(payload) {
        if (payload?.siteMeta && this.dom.version) {
            this.dom.version.textContent = `v${payload.siteMeta.siteVersion ?? "0.0.0"}`;
        }
    },
    /* #endregion */

    /* #region PERMIT TABLE */
    updatePermitTable: function (activePermits) {

        const tableBody = this.dom.card?.tableBody;
        const footer    = this.dom.card?.footer;

        if (!tableBody) {
            console.warn("Permit table body not found");
            return;
        }

        function toTitleCase(text) {
            if (!text) return "";
            return String(text)
                .replace(/[_-]+/g, " ")
                .trim()
                .split(/\s+/)
                .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
                .join(" ");
        }

        function formatJurisdiction(key) {
            const raw = (key ?? "").toString().trim();
            if (!raw) return "";

            const lower = raw.toLowerCase();

            if (!jurisdictionRegistry) {
                console.warn("jurisdictionRegistry not ready yet — using title case");
                return toTitleCase(raw);
            }

            const entry = jurisdictionRegistry[lower];
            if (entry?.label?.trim()) {
                return entry.label;
            }

            return toTitleCase(raw);
        }

        function formatStatus(key) {
            const raw = (key ?? "").toString().trim();
            if (!raw) return "";

            const lower = raw.toLowerCase();

            const statusMap = {
                "under_review":     "Under Review",
                "ready_to_issue":   "Ready to Issue",
                "need_to_submit":   "Need to Submit",
                "applicant_resubmit": "Applicant Resubmit",
                "plans_approved":   "Plans Approved",
                "final_fees":       "Final Fees",
                "issued":           "Issued",
                "finaled":          "Finaled",
                "submitted":        "Submitted",
                "corrections":      "Corrections"
            };

            return statusMap[lower] || toTitleCase(raw);
        }

        // Change detection
        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo ?? ''}|${p.status ?? ''}|${p.jurisdiction ?? ''}`).join("::")
            : "empty";

        if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        // Empty state
        if (!Array.isArray(activePermits) || activePermits.length === 0) {
            tableBody.innerHTML = `<tr class="empty-row"><td colspan="5">No active permits</td></tr>`;
            if (footer) footer.textContent = "No permits found";
            this.prevPermitLength = 0;
            return;
        }

        // Render
        const sorted = activePermits.slice().sort((a, b) =>
            (parseInt(a.wo, 10) || 0) - (parseInt(b.wo, 10) || 0)
        );

        const lengthChanged = sorted.length !== this.prevPermitLength;

        tableBody.innerHTML = "";
        const fragment = document.createDocumentFragment();

        sorted.forEach(p => {
            const row = document.createElement("tr");
            row.classList.add("permit-row");
            if (lengthChanged) row.classList.add("updated");

            row.innerHTML = `
                <td>${p.wo ?? ""}</td>
                <td>${p.customer ?? ""}</td>
                <td>${p.jobsite ?? ""}</td>
                <td>${formatJurisdiction(p.jurisdiction)}</td>
                <td>${formatStatus(p.status)}</td>
            `;

            fragment.appendChild(row);
        });

        tableBody.appendChild(fragment);

        // Enhanced footer with total + updated timestamp
        if (footer) {
            const activeCount = sorted.length;
            let footerText = `${activeCount} active permit${activeCount === 1 ? "" : "s"}`;

            if (permitRegistryMeta?.counts?.totalWorkOrders != null) {
                footerText += ` • ${permitRegistryMeta.counts.totalWorkOrders} total`;
            }

            if (permitRegistryMeta?.updatedOn) {
                const ts = permitRegistryMeta.updatedOn * 1000;
                const minutesAgo = (Date.now() - ts) / 60000;
                let freshness = '🟢';
                if (minutesAgo > 60) freshness = '🟡';
                if (minutesAgo > 1440) freshness = '🔴';

                footerText += ` • ${freshness} Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}`;
            }

            footer.textContent = footerText;
        }

        this.prevPermitLength = sorted.length;

        // Auto-scroll
        requestAnimationFrame(() => {
            const scrollEl = this.dom.card?.scrollWrap;
            if (scrollEl && scrollEl.scrollHeight > scrollEl.clientHeight) {
                this.autoScroll.start(scrollEl, 30000);
            }
        });
    },
    /* #endregion */

    /* #region SSE */
    onSSE(payload) {
        console.log("OfficeBoard SSE received", payload.activePermits?.length ?? "missing");

        if (payload.activePermits) {
            const permits = payload.activePermits;
            console.log("activePermits type:", Object.prototype.toString.call(permits));

            if (Array.isArray(permits) && permits.length > 0) {
                const first = permits[0];
                console.log("First permit sample:", first);
                console.log("Has expected keys?", 
                    'wo' in first && 'customer' in first && 'jobsite' in first &&
                    'jurisdiction' in first && 'status' in first
                );
            }
        }

        this.updateHeader(payload);

        let permitsToRender = null;

        if (Array.isArray(payload.activePermits)) {
            permitsToRender = payload.activePermits;
        }

        if (permitsToRender) {
            this.updatePermitTable(permitsToRender);
        } else {
            console.warn("No usable activePermits array → clearing table");
            this.updatePermitTable([]);
        }

        this.updateFooter(payload);
    }
    /* #endregion */
};
/* #endregion */

/* #region REGISTER */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */