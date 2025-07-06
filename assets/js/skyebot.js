// File: assets/js/skyebot.js

// #region 🧠 Skyebot Chat Setup
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const clearBtn = document.getElementById("clearBtn");
  const chatLog = document.getElementById("chatLog");

  if (!form || !input || !clearBtn || !chatLog) {
    console.error("❌ Skyebot setup error: Missing elements.");
    return;
  }

  // Conversation memory for context
  let conversationHistory = [{
    role: "assistant",
    content: "Hello! How can I assist you today?"
  }];

  // #region 💬 Chat Display Functions
  const addMessage = (role, text) => {
    const entry = document.createElement("div");
    entry.className = `chat-entry ${role === "user" ? "user-message" : "bot-message"}`;
    const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    entry.innerHTML = `<span>${role === "user" ? "👤 You" : "🤖 Skyebot"} [${time}]: ${text}</span>`;
    chatLog.appendChild(entry);
    chatLog.scrollTop = chatLog.scrollHeight;
  };

  const showThinking = () => {
    const div = document.createElement("div");
    div.id = "thinking";
    div.className = "chat-entry bot-message typing-indicator";
    div.innerHTML = `<span>🤖 Skyebot: Thinking...</span>`;
    chatLog.appendChild(div);
    chatLog.scrollTop = chatLog.scrollHeight;
  };

  const removeThinking = () => {
    const thinking = document.getElementById("thinking");
    if (thinking) thinking.remove();
  };
  // #endregion

  // #region 🧹 Clear Chat
  clearBtn.addEventListener("click", () => {
    chatLog.innerHTML = "";
    input.value = "";
    input.focus();
    addMessage("bot", "Hello! How can I assist you today?");
    conversationHistory = [{
      role: "assistant",
      content: "Hello! How can I assist you today?"
    }];
  });
  // #endregion

  // #region 🚀 Prompt Submission
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;

    addMessage("user", prompt);
    input.value = "";
    input.focus();

    conversationHistory.push({ role: "user", content: prompt });

    showThinking();

    try {
      const res = await fetch("/.netlify/functions/askOpenAI", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ conversation: conversationHistory, prompt })
      });

      const data = await res.json();
      removeThinking();

      if (!res.ok) {
        console.error("Server error:", data.error || "Unknown error");
        addMessage("bot", "❌ Sorry, something went wrong. Please try again.");
        return;
      }

      const reply = data.response || "🤖 Sorry, I didn’t understand that.";
      addMessage("bot", reply);

      conversationHistory.push({ role: "assistant", content: reply });

      if (data.action === "logout" && typeof window.logoutUser === "function") window.logoutUser();
      if (data.action === "versionCheck") alert(data.response || "📦 Version info unavailable.");

    } catch (err) {
      console.error("Client fetch error:", err.message);
      removeThinking();
      addMessage("bot", "❌ Network error. Please check your connection and try again.");
    }
  });
  // #endregion

  // #region 👋 Initial Message
  addMessage("bot", "Hello! How can I assist you today?");
  // #endregion
  // 🔐 Logout utility function
  window.logoutUser = function () {
    console.log("🚪 Logging out user...");
    localStorage.removeItem("userLoggedIn");
    location.reload();
  };
});
// #endregion