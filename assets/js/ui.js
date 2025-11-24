/* ============================================================
   #region SAFE DOM HELPERS
============================================================ */
function safeSet(id, v) {
  const el = document.getElementById(id);
  if (el) el.textContent = v;
}
/* #endregion */

/* ============================================================
   #region HIGHLIGHTS + DATE HELPERS
============================================================ */
function getDateInfo() {
  const n = new Date();
  const f = n.toLocaleDateString('en-US', {
    weekday: 'long',
    month: 'long',
    day: 'numeric'
  });
  const y = n.getFullYear();
  const leap = (y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0)) ? 366 : 365;
  const doy = Math.floor((n - new Date(y, 0, 0)) / 86400000);

  return { formattedDate: f, dayOfYear: doy, daysRemaining: leap - doy };
}

function updateHighlightsCard() {
  const d = getDateInfo();
  safeSet("todaysDate", d.formattedDate);
  safeSet("dayOfYear", d.dayOfYear);
  safeSet("daysRemaining", d.daysRemaining);
}
/* #endregion */

/* ============================================================
   #region PERMIT DATA LOADER
============================================================ */
async function loadPermitData() {
  try {
    const r = await fetch("/skyesoft/assets/data/activePermits.json");
    const j = await r.json();
    const tb = document.getElementById("permitTableBody");

    if (!tb) return;
    if (!j.activePermits) {
      tb.innerHTML = `<tr><td colspan="6">Error: no data</td></tr>`;
      return;
    }

    tb.innerHTML = j.activePermits.map(p => `
      <tr>
        <td>${p.wo}</td>
        <td>${p.customer}</td>
        <td>${p.jobsite}</td>
        <td>${p.jurisdiction}</td>
        <td>$${p.fee.toFixed(2)}</td>
        <td class="${p.status.includes("Review") ? "status-review" : "status-ready"}">
          ${p.status}
        </td>
      </tr>
    `).join("");

  } catch (e) {
    // silent fallback
  }
}
/* #endregion */

/* ============================================================
   #region AUTO SCROLL — FULL LEGACY RESTORE
============================================================ */
function autoScrollActivePermits() {
  const c = document.querySelector(".scrollContainer");
  if (!c) return;

  c.scrollTop = 0;
  const dist = c.scrollHeight - c.clientHeight;

  if (dist <= 0) return;

  const buffer = 2000;
  const scrollTime = cards[0].duration - buffer;
  const step = dist / (scrollTime / 30);
  let pos = 0;

  const t = setInterval(() => {
    pos += step;
    c.scrollTop = pos;
    if (pos >= dist) clearInterval(t);
  }, 30);
}
/* #endregion */

/* ============================================================
   #region CARD POPULATION
============================================================ */
function populateCard(card) {
  const h = document.getElementById("bodyHeader");
  const b = document.getElementById("bodyMain");
  const f = document.getElementById("bodyFooter");

  if (h) h.innerHTML = card.header;
  b.innerHTML = `<div class="cardBody">${card.body}</div>`;
  if (f) f.innerHTML = card.footer;
}
/* #endregion */
