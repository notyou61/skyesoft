// Skyebot Chatbot Script
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const thread = document.getElementById("chatThread");
  const clearBtn = document.getElementById("clearBtn");

  // ✅ Ensure all required elements are present
  if (!form || !input || !thread || !clearBtn) {
    console.error("❌ Skyebot setup error: One or more DOM elements are missing.");
    return;
  }

  // 🧑‍💻 Icons
  const userIcon = '<img class="chat-icon" src="assets/images/user-icon.png" alt="User">';
  const botIcon  = '<img class="chat-icon" src="assets/images/skyebot-icon.png" alt="Skyebot">';

  // 🧹 Clear chat
  clearBtn.addEventListener("click", () => {
    thread.innerHTML = "";
    input.value = "";
  });

  // 💬 Handle form submission
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

    // Show thinking placeholder
    thread.innerHTML += `
      <div class="chat-entry thinking" id="thinkingRow">
        ${botIcon}
        <div class="chat-bubble">🤖 Thinking...</div>
      </div>
    `;
    thread.scrollTop = thread.scrollHeight;

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prompt })
      });

      const data = await res.json();
      const reply = marked.parse(data.response || data.error || "(no response)");
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Replace thinking row with actual response
      const thinking = document.getElementById("thinkingRow");
      if (thinking) {
        thinking.outerHTML = `
          <div class="chat-entry">
            ${botIcon}
            <div class="chat-bubble">
              <strong>Skyebot [${replyTime}]:</strong><br>${reply}
            </div>
          </div>
        `;
      }

      // Optional action handling
      switch (data.action) {
        case "logout":
          if (typeof logoutUser === "function") logoutUser();
          break;
        case "versionCheck":
          alert(data.response || "📦 Version info unavailable.");
          break;
      }

    } catch (err) {
      const thinking = document.getElementById("thinkingRow");
      if (thinking) {
        thinking.outerHTML = `
          <div class="chat-entry">
            ${botIcon}
            <div class="chat-bubble">❌ Network or API error.</div>
          </div>
        `;
      }
    }

    thread.scrollTop = thread.scrollHeight;
  });
});
