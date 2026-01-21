/* Skyesoft — officeBoard.js
   Office Bulletin Board Controller
   Dynamic Card Model – Active Permits only (2026 edition)
*/

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

/* #region CARD FACTORY */
function createActivePermitsCard() {
    const card = document.createElement("section");
    card.className = "board-card active-permits";

    card.innerHTML = `
        <header class="card-header">
            <div class="card-title">📋 Active Permits</div>
        </header>

        <div class="card-body-divider"></div>

        <div class="card-body">
            <div class="scroll-container permit-scroll">
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
                    <tbody>
                        <tr><td colspan="5">Waiting for data…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer-divider"></div>
        <footer class="card-footer">—</footer>
    `;

    return {
        root: card,
        scrollWrap: card.querySelector(".permit-scroll"),
        tableBody: card.querySelector("tbody"),
        footer: card.querySelector(".card-footer")
    };
}
/* #endregion */

/* #region PAGE CONTROLLER */
window.SkyOfficeBoard = {

    /* #region DOM CACHE */
    dom: {
        //host: null,
        card: null,
        weather: null,
        time: null,
        interval: null,
        version: null
    },
    /* #endregion */

    lastPermitSignature: null,
    prevPermitLength: 0,
    scrollStarted: false,

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
        
        if (!this.dom.pageBody) {                           // ← change here
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
        
        console.log(
            "Active Permits card injected",
            this.dom.card.root
        );
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

    /* #region ACTIVE PERMITS */
    updatePermitTable(activePermits) {
        const card = this.dom.card;
        if (!card) return;

        const signature = Array.isArray(activePermits)
            ? activePermits.map(p => `${p.wo}|${p.status}`).join("::")
            : "empty";

        //if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        const body = card.tableBody;
        body.innerHTML = "";

        if (!Array.isArray(activePermits) || activePermits.length === 0) {
            body.innerHTML = `<tr><td colspan="5">No active permits</td></tr>`;
            card.footer.textContent = "No permits found";
            this.autoScroll.stop();
            this.prevPermitLength = 0;
            return;
        }

        const sorted = [...activePermits].sort((a,b) => (parseInt(a.wo,10)||0) - (parseInt(b.wo,10)||0));
        const countChanged = sorted.length !== this.prevPermitLength;

        const frag = document.createDocumentFragment();

        sorted.forEach(p => {
            const tr = document.createElement("tr");
            tr.className = "permit-row";
            if (countChanged) tr.classList.add("updated");

            tr.innerHTML = `
                <td>${p.wo ?? ""}</td>
                <td>${p.customer ?? ""}</td>
                <td>${p.jobsite ?? ""}</td>
                <td>${p.jurisdiction ?? ""}</td>
                <td>${p.status ?? ""}</td>
            `;
            frag.appendChild(tr);
        });

        body.appendChild(frag);
        `${sorted.length} active permit${sorted.length === 1 ? "" : "s"}`
        this.prevPermitLength = sorted.length;

        requestAnimationFrame(() => {
            if (card.scrollWrap.scrollHeight > card.scrollWrap.clientHeight) {
                this.autoScroll.start(card.scrollWrap);
            }
        });
    },
    /* #endregion */

    /* #region SSE */
    onSSE(payload) {

        console.log(
            "OfficeBoard SSE received",
            payload.activePermits?.length ?? "missing / not present"
        );

        // Early debug: show structure of activePermits if it exists
        if (payload.activePermits) {
            const permits = payload.activePermits;
            console.log("activePermits type:", Object.prototype.toString.call(permits));

            if (permits.length > 0) {
                const first = permits[0];
                console.log("First permit sample:", first);
                console.log("Has expected keys?", 
                    'wo'          in first &&
                    'customer'    in first &&
                    'jobsite'     in first &&
                    'jurisdiction'in first &&
                    'status'      in first
                );
            } else {
                console.log("activePermits is empty (length 0)");
            }
        } else {
            console.log("No activePermits property in payload");
        }

        this.updateHeader(payload);

        // Normalized handling for rendering
        let permitsToRender = null;

        if (Array.isArray(payload.activePermits)) {
            permitsToRender = payload.activePermits;
        } else if (payload.activePermits && typeof payload.activePermits.length === 'number') {
            // array-like (NodeList, arguments, typed array, etc.)
            console.log("Converting array-like object to real array");
            permitsToRender = Array.from(payload.activePermits);
        }

        if (permitsToRender) {
            this.updatePermitTable(permitsToRender);
        } else {
            console.warn("activePermits is not usable (not array or array-like) → table not updated");
            // Optional: force "No active permits" state if desired
            // this.updatePermitTable([]);
        }

        this.updateFooter(payload);
    }
    /* #endregion */
};
/* #endregion */

/* #region REGISTER */
window.SkyeApp.registerPage("officeBoard", window.SkyOfficeBoard);
/* #endregion */