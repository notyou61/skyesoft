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

    // 👤 User message
    thread.innerHTML += `
      <div class="chat-entry user">
        <span>👤 <strong>You [${time}]:</strong> ${marked.parseInline(prompt)}</span>
      </div>
    `;
    input.value = "";

    // Show thinking placeholder
    thread.innerHTML += `
      <div class="chat-entry thinking" id="thinkingRow">
        <span>🤖 <strong>Skyebot:</strong> Thinking...</span>
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
      const reply = marked.parseInline(data.response || data.error || "(no response)");
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Replace thinking row with actual response
      const thinking = document.getElementById("thinkingRow");
      if (thinking) {
        thinking.outerHTML = `
          <div class="chat-entry bot">
            <span>🤖 <strong>Skyebot [${replyTime}]:</strong> ${reply}</span>
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
          <div class="chat-entry bot">
            <span>🤖 <strong>Skyebot:</strong> ❌ Network or API error.</span>
          </div>
        `;
      }
    }

    thread.scrollTop = thread.scrollHeight;
  });
});
