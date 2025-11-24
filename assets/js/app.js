/* #region Main Controller */

let cardIndex = 0;

function updateClock() {
    if (!serverData) return;

    const now = new Date(Date.now() + serverTimeOffset);
    const h = now.getHours();
    const m = now.getMinutes().toString().padStart(2,'0');
    const s = now.getSeconds().toString().padStart(2,'0');
    const am = (h>=12 ? "PM" : "AM");

    safeSet("currentTime", `${(h%12)||12}:${m}:${s} ${am}`);

    if (serverData.intervalsArray) {
        let sec = Math.max(0, serverData.intervalsArray.secondsRemainingToInterval);
        const hh = Math.floor(sec/3600).toString().padStart(2,'0');
        const mm = Math.floor((sec%3600)/60).toString().padStart(2,'0');
        const ss = (sec%60).toString().padStart(2,'0');

        const i = serverData.intervalsArray;
        let label="", emoji="";

        if (i.dayType !== "Workday") { label="Office closed"; emoji="🟦"; }
        else if (i.intervalName === "Worktime") { label="Workday ends in"; emoji="🟩"; }
        else if (i.intervalName === "Before Worktime") { label="Workday begins in"; emoji="🟨"; }
        else { label="Workday resumes tomorrow"; emoji="🔴"; }

        const msg = label.includes("in")
            ? `${emoji} ${label} ${hh}h ${mm}m ${ss}s`
            : `${emoji} ${label}`;

        safeSet("intervalRemainingData", msg);

        serverData.intervalsArray.secondsRemainingToInterval = sec - 1;
    }
}

async function loadPermitData() {
    try {
        const r = await fetch("/skyesoft/assets/data/activePermits.json");
        const j = await r.json();
        const tbody = document.getElementById("permitTableBody");
        if (!tbody) return;

        if (!j.activePermits) {
            tbody.innerHTML = `<tr><td colspan="6">Error: no data found</td></tr>`;
            return;
        }

        tbody.innerHTML = j.activePermits.map(p => `
          <tr>
            <td>${p.wo}</td>
            <td>${p.customer}</td>
            <td>${p.jobsite}</td>
            <td>${p.jurisdiction}</td>
            <td>$${p.fee.toFixed(2)}</td>
            <td class="${p.status.includes("Review")?"status-review":"status-ready"}">
              ${p.status}
            </td>
          </tr>
        `).join("");

    } catch {
        safeSet("permitTableBody", `<tr><td colspan="6">Error loading permit data</td></tr>`);
    }
}

function updateHighlights() {
    const n = new Date();
    const f = n.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric'});
    const y = n.getFullYear();
    const leap = (y%4===0 && (y%100!==0 || y%400===0)) ? 366 : 365;
    const doy = Math.floor((n-new Date(y,0,0))/86400000);

    safeSet("todaysDate", f);
    safeSet("dayOfYear", doy);
    safeSet("daysRemaining", leap - doy);
}

function rotateCards() {
    const card = SKYECARDS[cardIndex];
    populateCard(card);

    if (card.id === "activePermits") {
        setTimeout(loadPermitData, 20);
        setTimeout(() => autoScrollPermits(card.duration), 400);
    }

    if (card.id === "todaysHighlights") {
        setTimeout(updateHighlights, 20);
    }

    cardIndex = (cardIndex+1) % SKYECARDS.length;
    setTimeout(rotateCards, card.duration);
}

window.addEventListener("DOMContentLoaded", () => {
    loadLiveData();
    setInterval(loadLiveData, 15000);
    setInterval(updateClock, 1000);
    rotateCards();
});

/* #endregion */