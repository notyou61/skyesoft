async function loadSandboxData() {
  try {
    const res = await fetch("/skyesoft/bulletinBoards/sandbox/officeBoardSandboxData.json");
    if (!res.ok) throw new Error(res.statusText);
    const data = await res.json();
    renderCards(data.cards);
  } catch (err) {
    document.getElementById("cardContainer").innerHTML = "<p>⚠️ Unable to load sandbox data.</p>";
    console.error(err);
  }
}

function renderCards(cards) {
  const container = document.getElementById("cardContainer");
  container.innerHTML = cards.map(card => `
    <div class="card">
      <h2>${card.header}</h2>
      <div>${card.body}</div>
      <footer><small>${card.footer}</small></footer>
    </div>
  `).join("");
}

function updateClock() {
  document.getElementById("currentTime").textContent = new Date().toLocaleTimeString();
}

setInterval(updateClock, 1000);
updateClock();
loadSandboxData();
