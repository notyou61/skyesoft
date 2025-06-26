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

  // 🧹 Clear chat thread
  clearBtn.addEventListener("click", () => {
    thread.innerHTML = "";
    responseEl.textContent = "";
    input.value = "";
  });

  // 💬 Handle threaded prompt submission
  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const prompt = input.value.trim();
    if (!prompt) return;

    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // Append user message
    thread.innerHTML += `
      <div><strong>You [${time}]:</strong><br>${marked.parse(prompt)}</div><hr>
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
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const reply = marked.parse(data.response || data.error || "(no response)");

      // Append AI response
      thread.innerHTML += `
        <div><strong>Skyebot [${replyTime}]:</strong><br>${reply}</div><hr>
      `;
      responseEl.textContent = "";

      // 🔄 Handle structured actions
      switch (data.action) {
        case "logout":
          if (typeof logoutUser === "function") logoutUser();
          break;
        case "versionCheck":
          alert(data.response || "📦 Version info unavailable.");
          break;
        default:
          break;
      }

    } catch (err) {
      thread.innerHTML += `<div><strong>Skyebot:</strong> ❌ Network or API error.</div><hr>`;
    }

    thread.scrollTop = thread.scrollHeight;
  });
});
