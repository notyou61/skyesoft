// Skyebot Embedded Prompt Chat
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const clearBtn = document.getElementById("clearBtn");

  // ✅ Confirm required elements exist
  if (!form || !input || !clearBtn) {
    console.error("❌ Skyebot setup error: One or more DOM elements are missing.");
    return;
  }

  // 🧹 Clear chat and input
  clearBtn.addEventListener("click", () => {
    input.value = "";
  });

  // 💬 On form submit
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;

    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    // 👤 Append user entry
    input.value += `\n👤 You [${time}]: ${prompt}\n🤖 Skyebot: Thinking...`;
    input.scrollTop = input.scrollHeight;

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ prompt })
      });

      const data = await res.json();
      const reply = data.response || data.error || "(no response)";
      const replyTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

      // 🔄 Replace the last Skyebot placeholder with real reply
      input.value = input.value.replace(/🤖 Skyebot: Thinking\.\.\.$/, `🤖 Skyebot [${replyTime}]: ${reply}`);

      // 🎯 Optional action response
      switch (data.action) {
        case "logout":
          if (typeof logoutUser === "function") logoutUser();
          break;
        case "versionCheck":
          alert(data.response || "📦 Version info unavailable.");
          break;
      }

    } catch (err) {
      input.value = input.value.replace(/🤖 Skyebot: Thinking\.\.\.$/, `🤖 Skyebot: ❌ Network or API error.`);
    }

    input.scrollTop = input.scrollHeight;
  });
});
