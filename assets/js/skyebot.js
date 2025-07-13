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

  // 🌐 Pull SSE Snapshot from stream JSON
  let sseSnapshot  = {};
  //
  let streamReady = false; // ✅ Properly declared

  async function fetchStreamData() {
    try {
      const res = await fetch("/skyesoft/api/getDynamicData.php");
      sseSnapshot  = await res.json();
      streamReady = true;
    } catch (err) {
      console.warn("⚠️ Unable to fetch stream data:", err.message);
      sseSnapshot  = {};
      streamReady = false;
    }
  }

  fetchStreamData();
  setInterval(fetchStreamData, 5000);

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

    // 🛑 Ensure stream data is ready before proceeding
    if (!sseSnapshot  || !sseSnapshot .timeDateArray) {
      removeThinking();
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      return;
    }

    try {
      const res = await fetch("/skyesoft/api/askOpenAI.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          conversation: conversationHistory,
          prompt,
          sseSnapshot: sseSnapshot 
        })
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

  // #region 📋 Chat Log Summary
  window.getChatSummary = function () {
    if (!conversationHistory || conversationHistory.length < 2) {
      return "📭 No meaningful chat history to summarize yet.";
    }

    const summary = conversationHistory
      .map(entry => {
        const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        return `${entry.role === "user" ? "👤 You" : "🤖 Skyebot"} [${time}]: ${entry.content}`;
      })
      .join("\n");

    console.log("🧾 Chat Summary:\n" + summary);
    return summary;
  };
  // #endregion
});
// #endregion
