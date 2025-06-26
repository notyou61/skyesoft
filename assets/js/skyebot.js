document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const thread = document.getElementById("chatThread");
  const clearBtn = document.getElementById("clearBtn");
  const responseEl = document.getElementById("skyebotResponse");

  if (!form || !input || !thread || !clearBtn || !responseEl) {
    console.error("❌ Skyebot setup error: One or more DOM elements are missing.");
    return;
  }

  const userIcon = '<img class="chat-icon" src="assets/images/user-icon.png" alt="User">';
  const botIcon = '<img class="chat-icon" src="assets/images/skyebot-icon.png" alt="Skyebot">';

  // 🧹 Clear chat
  clearBtn.addEventListener("click", () => {
    thread.innerHTML = "";
    responseEl.textContent = "";
    input.value = "";
  });

  // 💬 Handle form submit
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;

    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // Add user message
    thread.innerHTML += `
      <div class="chat-entry user">
        ${userIcon}
        <div class="chat-bubble">
          <strong>You [${time}]:</strong><br>${marked.parse(prompt)}
        </div>
      </div>
    `;
    input.value = "";
    responseEl.textContent = "🤖 Thinking...";

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prompt })
      });

      const data = await res.json();
      const reply = marked.parse(data.response || data.error || "(no response)");
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Add Skyebot response
      thread.innerHTML += `
        <div class="chat-entry">
          ${botIcon}
          <div class="chat-bubble">
            <strong>Skyebot [${replyTime}]:</strong><br>${reply}
          </div>
        </div>
      `;
      responseEl.textContent = "";

      switch (data.action) {
        case "logout":
          if (typeof logoutUser === "function") logoutUser();
          break;
        case "versionCheck":
          alert(data.response || "📦 Version info unavailable.");
          break;
      }

    } catch (err) {
      thread.innerHTML += `
        <div class="chat-entry">
          ${botIcon}
          <div class="chat-bubble">❌ Network or API error.</div>
        </div>
      `;
    }

    thread.scrollTop = thread.scrollHeight;
  });
});
