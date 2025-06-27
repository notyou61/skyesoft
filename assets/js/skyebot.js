// Skyebot Embedded Prompt Chat (fixed version)
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const clearBtn = document.getElementById("clearBtn");

  if (!form || !input || !clearBtn) {
    console.error("❌ Skyebot setup error: Missing elements.");
    return;
  }

  // Clear everything
  clearBtn.addEventListener("click", () => {
    input.value = "";
    input.focus();
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const prompt = input.value.trim();
    if (!prompt) return;

    const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // 🧹 Remove any prior bot Thinking line
    input.value = input.value.replace(/🤖 Skyebot.*Thinking.*\n?/g, '');

    // Add user entry
    input.value += `\n👤 You [${now}]: ${prompt}\n🤖 Skyebot: Thinking...\n`;

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prompt })
      });

      const data = await res.json();
      const reply = data.response || data.error || "(no response)";
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // Replace "Thinking..." with reply
      input.value = input.value.replace(
        /🤖 Skyebot: Thinking\.\.\./,
        `🤖 Skyebot [${replyTime}]: ${reply}`
      );

      // Auto-focus and line break for next prompt
      input.value += `\n\n`;
      input.focus();

      // Handle optional actions
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

    input.scrollTop = input.scrollHeight;
  });
});
