// File: assets/js/skyebot.js

//#region 📚 Codex State
let codexData = null; // 🗃️ Will hold Codex glossary/policies
//#endregion

//#region DomContent Loaded Event
document.addEventListener("DOMContentLoaded", () => {
    // Log here, runs when the DOM is ready
  console.log("✅ DOMContentLoaded event in skyebot.js");
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
    // Prevent default form submission
    e.preventDefault();
    // Get user input and validate
    const prompt = input.value.trim();
    // If no input, do nothing
    if (!prompt) return;
    // Add user message to chat log
    addMessage("user", prompt);
    // Clear input field and focus
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
      // Debug: log snapshot at submit
      console.log("🚦 sseSnapshot at submit:", sseSnapshot);
    } catch (err) {
      removeThinking();
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      return;
    }
    // Try
    try {
      // Send the prompt, conversation history, and SSE snapshot to the backend
      const data = await sendSkyebotPrompt(prompt, conversationHistory, sseSnapshot);
      // Debug: log the data returned by the backend
      console.log("Bot response data:", data);
      // Add the bot's response to the chat log
      console.log("[DEBUG] About to check logout:", data, typeof window.logoutUser);
      //
      removeThinking();
      const reply = data.response || "🤖 Sorry, I didn’t understand that.";
      addMessage("bot", reply);
      conversationHistory.push({ role: "assistant", content: reply });
      // --- Debug: show the data returned by the backend ---
      console.log("Bot response data:", data);
      console.log("[DEBUG] About to check logout:", data, typeof window.logoutUser);
      // --- Logout or version check handling (case-insensitive and robust) ---
      const action      = data.action ? data.action.toLowerCase().trim() : "";
      const actionType  = data.actionType ? data.actionType.toLowerCase().trim() : "";
      const actionName  = data.actionName ? data.actionName.toLowerCase().trim() : "";
      console.log("[DEBUG] Checking logout:", { action, actionType, actionName, logoutUser: typeof window.logoutUser });
      // If the action is logout or actionType is create and actionName is logout
      if (
        (action === "logout") ||
        (actionType === "create" && actionName === "logout")
      ) {
        console.log("🚪 Logout triggered by backend. Redirecting...");
        window.logoutUser();
      } else {
        console.log("[DEBUG] Logout condition NOT met.", { action, actionType, actionName, logoutUser: typeof window.logoutUser });
      }
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
    // Console log for debugging
    console.log("🚪 Logging out user...");
    //
    localStorage.removeItem('userLoggedIn');
    //
    localStorage.removeItem('userId');
    //
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    //
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/skyesoft/;";
    // Redirect to login
    //window.location.href = "/skyesoft/login.html";
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