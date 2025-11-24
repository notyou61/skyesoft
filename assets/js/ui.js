// ===============================================================
//  UI UTILITIES  (Legacy Behavior Restored)
// ===============================================================

// #region Safe DOM
function safeSet(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}
// #endregion


// ===============================================================
//  DATE / HIGHLIGHTS
// ===============================================================

// #region Date Info Block
function getDateInfo() {
    const n = new Date();
    const f = n.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric'
    });

    const year = n.getFullYear();
    const doy = Math.floor((n - new Date(year, 0, 0)) / 86400000);
    const leap = (year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) ? 366 : 365;

    return {
        formattedDate: f,
        dayOfYear: doy,
        daysRemaining: leap - doy
    };
}

function updateHighlightsCard() {
    const d = getDateInfo();
    safeSet("todaysDate", d.formattedDate);
    safeSet("dayOfYear", d.dayOfYear);
    safeSet("daysRemaining", d.daysRemaining);
}
// #endregion



// ===============================================================
//  PERMIT TABLE — LOAD + SCROLL
// ===============================================================

// #region Load Active Permits (JSON)
async function loadPermitData() {
    try {
        const res = await fetch("/skyesoft/assets/data/activePermits.json");
        const json = await res.json();
        const tbody = document.getElementById("permitTableBody");
        if (!tbody) return;

        if (!json.activePermits) {
            tbody.innerHTML = `<tr><td colspan="6">Error: no data found</td></tr>`;
            return;
        }

        tbody.innerHTML = json.activePermits.map(p => `
            <tr>
                <td>${p.wo}</td>
                <td>${p.customer}</td>
                <td>${p.jobsite}</td>
                <td>${p.jurisdiction}</td>
                <td>$${p.fee.toFixed(2)}</td>
                <td class="${p.status.includes("Review") ? "status-review" : "status-ready"}">${p.status}</td>
            </tr>
        `).join("");

    } catch (e) {
        safeSet("permitTableBody", `<tr><td colspan="6">Error loading permit data</td></tr>`);
    }
}
// #endregion


// #region Timed Auto-Scroll (Legacy Behavior)
function autoScrollActivePermits(durationMs) {
    const container = document.querySelector(".scrollContainer");
    if (!container) return;

    container.scrollTop = 0;

    const dist = container.scrollHeight - container.clientHeight;
    if (dist <= 0) return;

    // Legacy: stop scrolling ~2 seconds before card changes
    const buffer = 2000;
    const scrollTime = Math.max(500, durationMs - buffer);

    const step = dist / (scrollTime / 30);
    let pos = 0;

    const t = setInterval(() => {
        pos += step;
        container.scrollTop = pos;
        if (pos >= dist) clearInterval(t);
    }, 30);
}
// #endregion



// ===============================================================
//  CARD DISPLAY ENGINE
// ===============================================================

// #region Card Array Import Support
//let cards = [];   // this will be populated by cards.js
let cardIndex = 0;
// #endregion


// #region Populate Card
function populateCard(card) {
    const headerEl = document.getElementById("bodyHeader");
    const mainEl = document.getElementById("bodyMain");
    const footerEl = document.getElementById("bodyFooter");

    if (headerEl) headerEl.innerHTML = card.header;
    if (mainEl)   mainEl.innerHTML   = `<div class="cardBody">${card.body}</div>`;
    if (footerEl) footerEl.innerHTML = card.footer;
}
// #endregion



// ===============================================================
//  EXPORTS
// ===============================================================
window.UI = {
    safeSet,
    loadPermitData,
    autoScrollActivePermits,
    populateCard,
    updateHighlightsCard
};
