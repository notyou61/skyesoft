// File: assets/js/skyebot.js

//#region 📚 Codex State
let codexData = null; // 🗃️ Will hold Codex glossary/policies
//#endregion

document.addEventListener("DOMContentLoaded", () => {
  //#region 🟩 Element Selection & Early Checks
  const form = document.getElementById("promptForm");        // 📝 Chat form element
  const input = document.getElementById("promptInput");       // ⌨️ User input box
  const clearBtn = document.getElementById("clearBtn");       // 🧹 Clear button
  const chatLog = document.getElementById("chatLog");         // 🗂️ Chat log display

  if (!form || !input || !clearBtn || !chatLog) {
    console.error("❌ Skyebot setup error: Missing elements."); // 🛑 Prevents runtime errors
    return;
  }
  //#endregion

  //#region 🟦 State & Context
  let conversationHistory = [{
    role: "assistant",
    content: "Hello! How can I assist you today?"
  }];
  //#endregion

  //#region 📚 Fetch Codex Glossary from Server
  fetch("/skyesoft/docs/codex/codex.json")
    .then(res => res.json())
    .then(json => { codexData = json; })
    .catch(() => { codexData = {}; });
  //#endregion

  //#region 💬 Chat Message Rendering
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
  //#endregion

  //#region 🧹 Clear Chat Logic
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
  //#endregion

  //#region 🚀 Prompt Submission Logic (Snapshot fetched at submit)
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;

    addMessage("user", prompt);
    input.value = "";
    input.focus();

    conversationHistory.push({ role: "user", content: prompt });
    showThinking();

    // 📡 Fetch a fresh SSE snapshot at prompt time!
    let sseSnapshot = {};
    try {
      const res = await fetch("/skyesoft/api/getDynamicData.php");
      sseSnapshot = await res.json();
      console.log("🛰️ Using live SSE snapshot:", sseSnapshot);
      if (!sseSnapshot || !sseSnapshot.timeDateArray) {
        throw new Error("Live data not ready.");
      }
    } catch (err) {
      removeThinking();
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      return;
    }

    try {
      const data = await sendSkyebotPrompt(prompt, conversationHistory, sseSnapshot);
      removeThinking();
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
  //#endregion

  //#region 🛰️ Skyebot Prompt Function (send latest SOT)
  async function sendSkyebotPrompt(prompt, conversationHistory = [], sseSnapshot = {}) {
    console.log("🛰️ Sending prompt, convo, and SSE snapshot:", {prompt, conversationHistory, sseSnapshot});
    const response = await fetch("/skyesoft/api/askOpenAI.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        prompt,
        conversation: conversationHistory,
        sseSnapshot
      }),
    });
    return await response.json();
  }
  //#endregion

  //#region 👋 Startup Message
  addMessage("bot", "Hello! How can I assist you today?");
  //#endregion

  //#region 🔐 Logout Utility
  window.logoutUser = function () {
    console.log("🚪 Logging out user...");
    localStorage.removeItem("userLoggedIn");
    location.reload();
  };
  //#endregion

  //#region 📋 Chat Summary Utility
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
  //#endregion

});
//#endregion
