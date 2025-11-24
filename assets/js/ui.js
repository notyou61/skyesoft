/* #region UI Helpers */

window.safeSet = function (id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
};

window.populateCard = function(card) {
    const headerEl = document.getElementById("bodyHeader");
    const bodyEl   = document.getElementById("bodyMain");
    const footerEl = document.getElementById("bodyFooter");

    if (headerEl) headerEl.innerHTML = card.header;
    if (bodyEl)   bodyEl.innerHTML   = `<div class="cardBody">${card.body}</div>`;
    if (footerEl) footerEl.innerHTML = card.footer;
};

window.autoScrollPermits = function(durationMs) {
    const container = document.querySelector(".scrollContainer");
    if (!container) return;

    container.scrollTop = 0;
    const dist = container.scrollHeight - container.clientHeight;
    if (dist <= 0) return;

    const buffer = 2000;
    const scrollTime = durationMs - buffer;
    const step = dist / (scrollTime / 30);
    let pos = 0;

    const timer = setInterval(() => {
        pos += step;
        container.scrollTop = pos;
        if (pos >= dist) clearInterval(timer);
    }, 30);
};

/* #endregion */