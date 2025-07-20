// File: assets/js/skyebot.js

//#region 📚 Codex State
let codexData = null; // 🗃️ Will hold Codex glossary/policies
//#endregion

//#region 🧠 Skyebot Main Chat Loader
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
  // 🧵 Local memory of conversation for AI context
  let conversationHistory = [{
    role: "assistant",
    content: "Hello! How can I assist you today?"
  }];

  // 🌐 Holds latest live SSE snapshot
  let sseSnapshot = {};
  // ✅ Tracks if stream is ready to use
  let streamReady = false;
  //#endregion

  //#region 📚 Fetch Codex Glossary from Server
fetch("/skyesoft/docs/codex/codex.json")
  .then(res => res.json())
  .then(json => { codexData = json; })
  .catch(() => { codexData = {}; });
//#endregion

  //#region 🟧 Live Stream Polling and Skyebot Prompt

  // 🔄 Fetch SSE stream (site SOT) on interval
  async function fetchStreamData() {
    try {
      const res = await fetch("/skyesoft/api/getDynamicData.php"); // 📡 API call to PHP backend
      sseSnapshot = await res.json();                              // 🗃️ Save snapshot
      streamReady = true;                                          // 🟢 Ready!
    } catch (err) {
      console.warn("⚠️ Unable to fetch stream data:", err.message); // ⚠️ Warn on failure
      sseSnapshot = {};
      streamReady = false;
    }
  }
  fetchStreamData();                     // ⏩ Run immediately
  setInterval(fetchStreamData, 1000);    // 🔁 Repeat every 1 second

  // 🌟 Skyebot Prompt Function — Always sends the latest SOT!
  async function sendSkyebotPrompt(prompt, conversationHistory = []) {
    if (!streamReady) {
      return { response: "Live stream not ready." };
    }
    const response = await fetch("/skyesoft/api/askOpenAI.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        prompt,
        conversation: conversationHistory,
        sseSnapshot   // 👈 Includes all live data!
      }),
    });
    return await response.json();
  }
//#endregion

  //#region 💬 Chat Message Rendering
  // 🟦 Add a chat message to the log
  const addMessage = (role, text) => {
    const entry = document.createElement("div");
    entry.className = `chat-entry ${role === "user" ? "user-message" : "bot-message"}`;
    const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    entry.innerHTML = `<span>${role === "user" ? "👤 You" : "🤖 Skyebot"} [${time}]: ${text}</span>`;
    chatLog.appendChild(entry);
    chatLog.scrollTop = chatLog.scrollHeight;
  };

  // 🟨 Show "Thinking..." indicator
  const showThinking = () => {
    const div = document.createElement("div");
    div.id = "thinking";
    div.className = "chat-entry bot-message typing-indicator";
    div.innerHTML = `<span>🤖 Skyebot: Thinking...</span>`;
    chatLog.appendChild(div);
    chatLog.scrollTop = chatLog.scrollHeight;
  };

  // 🟪 Remove "Thinking..." indicator
  const removeThinking = () => {
    const thinking = document.getElementById("thinking");
    if (thinking) thinking.remove();
  };
  //#endregion

  //#region 🧹 Clear Chat Logic
  clearBtn.addEventListener("click", () => {
    chatLog.innerHTML = "";      // 🗑️ Clear all chat bubbles
    input.value = "";            // 🔄 Clear input box
    input.focus();               // 🎯 Focus input for fast typing
    addMessage("bot", "Hello! How can I assist you today?"); // 🤖 Reset welcome
    conversationHistory = [{
      role: "assistant",
      content: "Hello! How can I assist you today?"
    }];
  });
  //#endregion

  //#region 🚀 Prompt Submission Logic
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;              // 🛑 Skip if prompt is empty

    addMessage("user", prompt);       // 👤 Show user’s message
    input.value = "";                 // 🔄 Clear input box
    input.focus();

    conversationHistory.push({ role: "user", content: prompt }); // 🧵 Track for context

    showThinking();                   // 🤖 Show typing/AI processing

    // 🛑 Wait for stream data before querying AI
    if (!sseSnapshot || !sseSnapshot.timeDateArray) {
      removeThinking();
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      return;
    }

    try {
      // 📨 Send prompt, convo, and stream to backend AI API
      const res = await fetch("/skyesoft/api/askOpenAI.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          conversation: conversationHistory,
          prompt,
          sseSnapshot: sseSnapshot,
          codex: codexData   // ✅ NOW INCLUDED!
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
      addMessage("bot", reply);                           // 🤖 Show AI reply
      conversationHistory.push({ role: "assistant", content: reply }); // 🧵 Save in convo

      // 🛠️ Handle special actions from server
      if (data.action === "logout" && typeof window.logoutUser === "function") window.logoutUser();
      if (data.action === "versionCheck") alert(data.response || "📦 Version info unavailable.");

    } catch (err) {
      console.error("Client fetch error:", err.message);
      removeThinking();
      addMessage("bot", "❌ Network error. Please check your connection and try again.");
    }
  });
  //#endregion

  //#region 👋 Startup Message
  addMessage("bot", "Hello! How can I assist you today?"); // 🤖 Greet at load
  //#endregion

  //#region 🔐 Logout Utility
  // Make logout available globally for agentic actions
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
    // 📝 Format history for quick copy/export
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