// Skyebot Embedded Prompt Chat
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const clearBtn = document.getElementById("clearBtn");

  if (!form || !input || !clearBtn) {
    console.error("❌ Skyebot setup error: One or more DOM elements are missing.");
    return;
  }

  // 🧹 Clear chat and input
  clearBtn.addEventListener("click", () => {
    input.value = "";
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;

    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // Format user prompt
    const userEntry = `👤 You [${time}]: ${prompt}`;
    input.value += `\n${userEntry}\n🤖 Skyebot: Thinking...`;

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prompt })
      });

      const data = await res.json();
      const reply = data.response || data.error || "(no response)";
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Replace thinking with Skyebot response
      input.value = input.value.replace(
        /🤖 Skyebot: Thinking\.\.\./,
        `🤖 Skyebot [${replyTime}]: ${reply}`
      );

      // Append clean line for next prompt
      input.value += `\n\n`;
      input.scrollTop = input.scrollHeight;

      // Optional actions
      switch (data.action) {
        case "logout":
          if (typeof logoutUser === "function") logoutUser();
          break;
        case "versionCheck":
          alert(data.response || "📦 Version info unavailable.");
          break;
      }

    } catch (err) {
      input.value = input.value.replace(
        /🤖 Skyebot: Thinking\.\.\./,
        `🤖 Skyebot: ❌ Network or API error.`
      );
    }

    // Focus back to textarea
    input.focus();
  });
});
