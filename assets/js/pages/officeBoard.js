/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Dynamic Card Model – Active Permits only (2026 edition)
*/

/* #region SMART INTERVAL FORMATTER */
function formatSmartInterval(totalSeconds) {
    let sec = Math.max(0, totalSeconds);

    const days    = Math.floor(sec / 86400); sec %= 86400;
    const hours   = Math.floor(sec / 3600);  sec %= 3600;
    const minutes = Math.floor(sec / 60);
    const seconds = sec % 60;

    if (days > 0) {
        return `${days}d ${hours.toString().padStart(2,"0")}h ${minutes.toString().padStart(2,"0")}m ${seconds.toString().padStart(2,"0")}s`;
    }
    if (hours > 0) {
        return `${hours}h ${minutes.toString().padStart(2,"0")}m ${seconds.toString().padStart(2,"0")}s`;
    }
    if (minutes > 0) {
        return `${minutes}m ${seconds.toString().padStart(2,"0")}s`;
    }
    return `${seconds}s`;
}
/* #endregion */

/* #region ACTIVE PERMITS CARD BUILDER */
function createActivePermitsCard() {
    const card = document.createElement("section");
    card.className = "board-card active-permits";

    card.innerHTML = `
        <header class="card-header">
            <div class="card-title">📋 Active Permits</div>
        </header>
        <div class="card-body-divider"></div>
        <div class="card-body">
            <div class="scroll-container permit-scroll" id="permitScrollWrap">
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
                        <tr><td colspan="5">Loading permits…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer-divider"></div>
        <footer class="card-footer" id="permitFooter">Loading…</footer>
    `;

    return {
        root:      card,
        scrollWrap: card.querySelector(".permit-scroll"),
        tableBody: card.querySelector("#permitTableBody"),
        footer:    card.querySelector("#permitFooter")
    };
}
/* #endregion */

/* #region PAGE CONTROLLER */
window.SkyOfficeBoard = {

    dom: {
        pageBody:       null,
        activePermits:  null,   // { root, scrollWrap, tableBody, footer }
        weatherDisplay: null,
        timeDisplay:    null,
        intervalDisplay:null,
        versionFooter:  null
    },

    lastPermitSignature: null,
    prevPermitLength:    0,
    _scrollInitiated:    false,

    /* ────────────────────────────────────────────────
       Auto Scroll Engine
    ──────────────────────────────────────────────── */
    autoScroll: {
        timer: null,
        isRunning: false,
        FPS: 60,

        start(el, durationMs = 30000) {
            if (!el) return;
            this.stop();

            const distance = el.scrollHeight - el.clientHeight;
            if (distance <= 0) return;

            const safeDuration = durationMs * 0.95;
            const frames = Math.max(1, Math.round(safeDuration / (1000 / this.FPS)));
            const speed = Math.max(0.5, distance / frames);

            el.scrollTop = 0;
            this.isRunning = true;

            const step = () => {
                if (!this.isRunning) return;

                const max = el.scrollHeight - el.clientHeight;
                if (el.scrollTop + speed >= max) {
                    el.scrollTop = max;
                    this.isRunning = false;
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
            this.isRunning = false;
        }
    },

    /* ────────────────────────────────────────────────
       Lifecycle
    ──────────────────────────────────────────────── */
    init() {
        this.dom.pageBody = document.getElementById("boardCardHost");
        if (!this.dom.pageBody) {
            console.warn("officeBoard: #pageBody container not found");
            return;
        }

        // Header/footer elements (shared)
        this.dom.weatherDisplay  = document.getElementById("headerWeather");
        this.dom.timeDisplay     = document.getElementById("headerTime");
        this.dom.intervalDisplay = document.getElementById("headerInterval");
        this.dom.versionFooter   = document.getElementById("versionFooter");

        // Build & mount Active Permits card
        const card = createActivePermitsCard();
        this.dom.pageBody.appendChild(card.root);
        this.dom.activePermits = card;

        // Process any already-received SSE data
        if (window.SkyeApp?.lastSSE) {
            this.onSSE(window.SkyeApp.lastSSE);
        }
    },

    start() {
        this.init();
    },

    destroy() {
        this.autoScroll.stop();
        // future: remove event listeners, clear intervals, etc.
    },

    /* ────────────────────────────────────────────────
       Header & Footer
    ──────────────────────────────────────────────── */
    updateHeader(payload) {
        if (!payload) return;

        if (payload.timeDateArray && this.dom.timeDisplay) {
            this.dom.timeDisplay.textContent = payload.timeDateArray.currentLocalTime ?? "--:--:--";
        }

        if (payload.currentInterval && this.dom.intervalDisplay) {
            const iv = payload.currentInterval;
            const labels = {
                beforeWork: "Workday begins in",
                worktime:   "Workday ends in",
                afterWork:  "Next workday begins in",
                weekend:    "Workday resumes in",
                holiday:    "Workday resumes after holiday in"
            };
            const label = labels[iv.key] ?? "Interval ends in";
            this.dom.intervalDisplay.textContent = `${label} ${formatSmartInterval(iv.secondsRemainingInterval)}`;
        }

        if (payload.weather && this.dom.weatherDisplay) {
            const w = payload.weather;
            const temp = Math.round(w.temp ?? 0);
            const cond = (w.condition ?? "--").replace(/^\w/, c => c.toUpperCase());
            this.dom.weatherDisplay.textContent = `${temp}°F – ${cond}`;
        }
    },

    updateFooter(payload) {
        if (payload?.siteMeta && this.dom.versionFooter) {
            this.dom.versionFooter.textContent = `v${payload.siteMeta.siteVersion ?? "0.0.0"}`;
        }
    },

    /* ────────────────────────────────────────────────
       Active Permits Table
    ──────────────────────────────────────────────── */
    updatePermitTable(activePermits) {
        const card = this.dom.activePermits;
        if (!card?.tableBody) return;

        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo || ""}|${p.status || ""}`).join("::")
            : "empty";

        if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        const tbody = card.tableBody;
        tbody.innerHTML = "";

        if (!Array.isArray(activePermits) || activePermits.length === 0) {
            tbody.innerHTML = `<tr class="empty-row"><td colspan="5">No active permits</td></tr>`;
            card.footer.textContent = "No permits found";
            this.prevPermitLength = 0;
            this.autoScroll.stop();
            return;
        }

        const sorted = [...activePermits].sort((a,b) => (parseInt(a.wo,10)||0) - (parseInt(b.wo,10)||0));
        const countChanged = sorted.length !== this.prevPermitLength;

        const fragment = document.createDocumentFragment();

        sorted.forEach(permit => {
            const tr = document.createElement("tr");
            tr.className = "permit-row";
            if (countChanged) tr.classList.add("updated");

            tr.innerHTML = `
                <td>${permit.wo        ?? ""}</td>
                <td>${permit.customer  ?? ""}</td>
                <td>${permit.jobsite   ?? ""}</td>
                <td>${permit.jurisdiction ?? ""}</td>
                <td>${permit.status    ?? ""}</td>
            `;
            fragment.appendChild(tr);
        });

        tbody.appendChild(fragment);

        card.footer.textContent = `${sorted.length} active permit${sorted.length === 1 ? "" : "s"}`;
        this.prevPermitLength = sorted.length;

        // Restart scroll if needed (only when card is visible – currently always since only one card)
        requestAnimationFrame(() => {
            const el = card.scrollWrap;
            if (el.scrollHeight > el.clientHeight) {
                this.autoScroll.start(el, 30000);
            } else {
                this.autoScroll.stop();
            }
        });
    },

    /* ────────────────────────────────────────────────
       SSE Entry Point
    ──────────────────────────────────────────────── */
    onSSE(payload) {
        if (!payload) return;

        this.updateHeader(payload);
        if (Array.isArray(payload.activePermits)) {
            this.updatePermitTable(payload.activePermits);

            // Optional: one-time scroll kickstart on first real data
            if (!this._scrollInitiated && payload.activePermits.length > 0) {
                this._scrollInitiated = true;
                requestAnimationFrame(() => {
                    const el = this.dom.activePermits?.scrollWrap;
                    if (el && el.scrollHeight > el.clientHeight) {
                        this.autoScroll.start(el);
                    }
                });
            }
        }
        this.updateFooter(payload);
    }
};
/* #endregion */

/* #region REGISTER */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */