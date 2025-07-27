// 📁 File: assets/js/skyebot.js

//#region 📚 Codex State
let codexData = null; // 🗃️ Will hold Codex glossary/policies
//#endregion

//#region DomContent Loaded Event
document.addEventListener("DOMContentLoaded", () => {

  //#region 🟩 Element Selection & Early Checks
  const form = document.getElementById("promptForm");        // 📝 Chat form element
  const input = document.getElementById("promptInput");       // ⌨️ User input box
  const clearBtn = document.getElementById("clearBtn");       // 🧹 Clear button
  const chatLog = document.getElementById("chatLog");         // 🗂️ Chat log display
  // 🛑 Early exit if any element is missing
  if (!form || !input || !clearBtn || !chatLog) {
    // 🛑 Log error and prevent runtime issues
    console.error("❌ Skyebot setup error: Missing elements."); // 🛑 Prevents runtime errors
    // 🛑 Show an alert to the user
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
  // Show a thinking indicator while processing
  const showThinking = () => {
    const div = document.createElement("div");
    div.id = "thinking";
    div.className = "chat-entry bot-message typing-indicator";
    div.innerHTML = `<span>🤖 Skyebot: Thinking...</span>`;
    chatLog.appendChild(div);
    chatLog.scrollTop = chatLog.scrollHeight;
  };
  // Remove the thinking indicator
  const removeThinking = () => {
    // Const Thinking = document.querySelector("#thinking");
    const thinking = document.getElementById("thinking");
    // If the thinking indicator exists, remove it
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
    // Reset input field
    input.focus();
    // Add user message to conversation history
    conversationHistory.push({ role: "user", content: prompt });
    // Show thinking indicator
    showThinking();
    // 📡 Fetch a fresh SSE snapshot at prompt time!
    let sseSnapshot = {};
    try {
      // Fetch the latest SSE snapshot from the server
      const res = await fetch("/skyesoft/api/getDynamicData.php");
      // Check if the response is ok
      sseSnapshot = await res.json();
      // Debug: log the snapshot
      console.log("🛰️ Using live SSE snapshot:", sseSnapshot);
      // Validate the snapshot structure
      if (!sseSnapshot || !sseSnapshot.timeDateArray) {
        // If the snapshot is empty or malformed, throw an error
        throw new Error("Live data not ready.");
      }
      // Debug: log snapshot at submit
      console.log("🚦 sseSnapshot at submit:", sseSnapshot);
    } catch (err) {
      // If fetching the snapshot fails, log the error
      removeThinking();
      // Add a message to the chat log
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      // Log the error
      return;
    }
    // Try
    try {
      // Send the prompt, conversation history, and SSE snapshot to the backend
      const data = await sendSkyebotPrompt(prompt, conversationHistory, sseSnapshot);
      removeThinking();
      const reply = data.response || "🤖 Sorry, I didn’t understand that.";
      addMessage("bot", reply);
      conversationHistory.push({ role: "assistant", content: reply });

      const action      = data.action ? data.action.toLowerCase().trim() : "";
      const actionType  = data.actionType ? data.actionType.toLowerCase().trim() : "";
      const actionName  = data.actionName ? data.actionName.toLowerCase().trim() : "";

      if (
        (action === "logout") ||
        (actionType === "create" && actionName === "logout")
      ) {
        console.log("🚪 Logout triggered by backend. Redirecting...");
        window.logoutUser();
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
    // Res[ponse from the server]
    const response = await fetch("/skyesoft/api/askOpenAI.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        prompt,
        conversation: conversationHistory,
        sseSnapshot
      }),
    });
    // Return the JSON response
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
    // Clear local storage items related to user session
    localStorage.removeItem('userLoggedIn');
    // localStorage.removeItem('userName');
    localStorage.removeItem('userId');
    // Clear session storage items related to user session
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    // Clear cookies related to user session
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/skyesoft/;";
    // Hide or reset the chat panel
    const chatPanel = document.getElementById('chatPanel') || document.querySelector('.chat-wrapper');
    // If the chat panel exists, hide it
    if (chatPanel) chatPanel.style.display = "none";
    // After state is cleared, update the UI
    if (typeof updateLoginUI === "function") {
      // Call the updateLoginUI function if it exists  
      updateLoginUI();
    } else {
        // As a fallback, you may still want to reload:
        location.reload();
    }
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

  // #region 🚪 Logout Action Logger (Client-Side, MTCO-Style)
  /**
   * Logs a logout action to localStorage actions array.
   * Structure matches login action, uses "actionTypeID": 2 for logout.
   */
  async function logLogoutAction() {
    let latitude = null, longitude = null;

    // #region 🌐 Get Live Geolocation
    try {
      if (navigator.geolocation) {
        const pos = await new Promise((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, {timeout: 5000});
        });
        latitude = pos.coords.latitude;
        longitude = pos.coords.longitude;
      }
    } catch (e) {
      // Location unavailable/denied is acceptable
    }
    // #endregion

    // #region 📝 Build Logout Action Object
    // Get contactID from cookie, fallback to 1 if not set
    const contactID = parseInt(getCookie('skye_contactID'), 10) || 1;
    
    const actions = JSON.parse(localStorage.getItem("actions")) || [];
    const nextId = actions.length > 0
      ? Math.max(...actions.map(a => a.actionID)) + 1
      : 1;
    const now = Date.now();

    const logoutAction = {
      actionID: nextId,
      actionTypeID: 2,                       // 2 = Logout
      actionContactID: contactID,                    // TODO: Replace with dynamic user/contact ID
      actionNote: "User logged out",
      actionGooglePlaceId: "Place ID unavailable",
      actionLatitude: latitude,
      actionLongitude: longitude,
      actionTime: now
    };
    // #endregion

    // #region 💾 Save to Local Storage
    actions.push(logoutAction);
    localStorage.setItem("actions", JSON.stringify(actions));
    console.log("Logout action added:", logoutAction);
    return logoutAction;   // <--- return the object!
    // #endregion
    }
    // #endregion

    // #region 🚪 Logout Handler With Action Logging
    window.logoutUser = async function () {
      const logoutAction = await logLogoutAction();
      // Show the action object to the user in chat:
      addMessage("bot", `✅ Logout logged:\n\`\`\`json\n${JSON.stringify(logoutAction, null, 2)}\n\`\`\``);

      // Your existing session cleanup...
      localStorage.removeItem('userLoggedIn');
      localStorage.removeItem('userId');
      document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
      document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/skyesoft/;";
      const chatPanel = document.getElementById('chatPanel') || document.querySelector('.chat-wrapper');
      if (chatPanel) chatPanel.style.display = "none";
      if (typeof updateLoginUI === "function") {
        updateLoginUI();
      } else {
        location.reload();
      }
    };
    // #endregion

});
//#endregion