// 📁 File: assets/js/skyebot.js

// #region 📚 Codex State
let codexData = null; // 🗃️ Will hold Codex glossary/policies
// #endregion

// #region 🧠 Skyebot Action Router
async function handleSkyebotAction(actionType, note, customData = {}) {
    const contactID = parseInt(getCookie('skye_contactID'), 10) || 1;
    const getLocationAsync = () => new Promise(resolve => {
        if (!navigator.geolocation) return resolve({ lat: null, lng: null });
        navigator.geolocation.getCurrentPosition(
            pos => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
            () => resolve({ lat: null, lng: null }),
            { enableHighAccuracy: true, timeout: 5000 }
        );
    });
    const { lat, lng } = await getLocationAsync();

    let actionTypeID;
    let actionNote = note || "";
    switch (actionType) {
        case "logout":
            actionTypeID = 2;
            actionNote = note || "User logged out";
            break;
        case "add":
            actionTypeID = 3;
            actionNote = note || "Added record";
            break;
        case "edit":
            actionTypeID = 4;
            actionNote = note || "Edited record";
            break;
        case "delete":
            actionTypeID = 5;
            actionNote = note || "Deleted record";
            break;
        default:
            actionTypeID = 99;
            actionNote = note || "Other Skyebot action";
    }

    // Compose the action object for AJAX log
    const actionObj = {
        actionTypeID,
        actionContactID: contactID,
        actionNote,
        actionLatitude: lat,
        actionLongitude: lng,
        actionTime: Date.now(),
        ...customData
    };

    // Always log to backend!
    const result = await logAction(actionObj);

    if (result && result.success) {
        console.log(`[Skyebot] Action logged (${actionType}):`, result.actionID);
        // Optionally notify/chat
    } else {
        alert(`Skyebot action '${actionType}' could not be logged.`);
    }
}
// #endregion

// #region ⏺️ Universal Action Logger

/**
 * Posts any action to the backend audit log.
 * @param {Object} actionObj - Structured action data (see schema below)
 * @returns {Promise} Resolves with server response or error
 */
function logAction(actionObj) {
    // Ensure actionObj contains all required fields
    // You can add defaults/checks here if needed
    return fetch('/skyesoft/api/addAction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(actionObj)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Optionally show a toast or chat bubble here
            console.log('Action logged:', data.actionID || actionObj);
        } else {
            console.warn('Logging failed:', data.error || data);
        }
        return data;
    })
    .catch(err => {
        console.error('AJAX logAction error:', err);
        return { success: false, error: err };
    });
}
//#endregion

// #region 🧹 Modal Reset On Close
function toggleModal() {
  const modal = document.getElementById("skyebotModal");
  const isVisible = modal.style.display === "block";
  // #region 🧹 Modal Reset On Close
  if (isVisible) {
    const chatLog = document.getElementById("chatLog");
    const promptInput = document.getElementById("promptInput");
    const fileInput = document.getElementById("fileUpload");
    const fileInfo = document.getElementById("fileInfo");
    if (chatLog) {
      chatLog.innerHTML = "";
      const welcome = document.createElement("div");
      welcome.className = "chat-entry bot-message";
      const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
      welcome.innerHTML = `<span>🤖 Skyebot [${time}]: Hello! How can I assist you today?</span>`;
      chatLog.appendChild(welcome);
    }
    if (promptInput) promptInput.value = "";
    if (fileInput) fileInput.value = "";
    if (fileInfo) fileInfo.textContent = "No files selected";
  }
  // #endregion
  modal.style.display = isVisible ? "none" : "block";
  document.body.classList.toggle("modal-open", !isVisible);
}
// #endregion

// #region 🎉 Skyebot Animated Emoji Confetti
function showAnimatedEmojiConfetti(count = 18) {
    const emojis = ["🎉", "✨", "🧂", "💾", "🎊", "🌟", "🔥", "🥳", "💡"];
    const chatModal = document.getElementById("skyebotModal");
    if (!chatModal) return;
    const modalRect = chatModal.getBoundingClientRect();

    for (let i = 0; i < count; i++) {
        const emoji = emojis[Math.floor(Math.random() * emojis.length)];
        const span = document.createElement("span");
        span.textContent = emoji;
        span.className = "skyebot-confetti-emoji";

        // Random horizontal position within the modal
        const left = Math.random() * (modalRect.width - 30);
        span.style.left = left + "px";
        // Random delay for a burst effect
        span.style.top = "-25px";
        span.style.animationDelay = (Math.random() * 0.5) + "s";

        // Place it inside the modal (relative to modal's top/left)
        span.style.position = "absolute";
        span.style.pointerEvents = "none";
        chatModal.appendChild(span);

        // Remove after animation
        setTimeout(() => {
            span.remove();
        }, 1400);
    }
}
// #endregion

// #region 🥚 Skyebot Easter Egg Handler

/**
 * Checks a user message for special Easter egg triggers.
 * If a phrase is matched, performs a fun action and returns true.
 * Otherwise returns false (normal processing).
 * @param {string} message - The user's chat input
 * @returns {boolean}
 */
function handleEasterEggs(message) {
    const msg = message.trim().toLowerCase();

    // 'push it' Easter egg
    if (msg.includes("push it")) {
        // Show a fun animated emoji confetti
        showAnimatedEmojiConfetti();
        // Show a fun confetti burst
        showEasterEggResponse("🎶 Yo, it's Skyebot! Pushin' it real good... 🧂🕺💃");
        // Return true to indicate an Easter egg was triggered
        return true;
    }

    // 'fortune' Easter egg
    if (msg === "/fortune") {
        const fortunes = [
            "🚀 Success is just a commit away.",
            "💡 Today’s bug is tomorrow’s feature.",
            "🧂 Keep pushing it!",
            "🎲 Luck favors the persistent debugger.",
            "🍪 You will find a semicolon where you least expect it."
        ];
        const fortune = fortunes[Math.floor(Math.random() * fortunes.length)];
        showEasterEggResponse(fortune);
        return true;
    }

    // More fun triggers can go here!

    return false; // No Easter egg matched
}

/**
 * Displays an Easter egg response in the chat log.
 * @param {string} text
 */
function showEasterEggResponse(text) {
    const chatLog = document.getElementById("chatLog");
    if (chatLog) {
        const entry = document.createElement("div");
        entry.className = "chat-entry bot-message easter-egg";
        entry.innerHTML = `<span>${text}</span>`;
        chatLog.appendChild(entry);
        chatLog.scrollTop = chatLog.scrollHeight;
    }
}
// #endregion

// #region 🎉 ASCII Confetti Drop (Skyebot Easter Egg)
/**
 * Appends a burst of ASCII confetti to the chat log.
 * @param {string} [message] - Optional message to show with confetti
 */
function showAsciiConfetti(message) {
    const chatLog = document.getElementById("chatLog");
    if (!chatLog) return;
    // Fun ASCII confetti burst
    const confettiArt = [
        "✨  *  .   .  ✦   *  ✨",
        " . *  🌟   .  * ✨ .  .",
        "🎉  ✨  *   .  ✦   🎊  *",
        "  *  .  ✨   .  *  🎉   "
    ].join("<br>");

    const entry = document.createElement("div");
    entry.className = "chat-entry bot-message easter-egg";
    entry.innerHTML = `<pre style="font-size:1.2em;line-height:1.1;">${confettiArt}</pre>` +
                      (message ? `<div>${message}</div>` : "");
    chatLog.appendChild(entry);
    chatLog.scrollTop = chatLog.scrollHeight;
}
// #endregion

// #region DomContent Loaded Event
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
    // 🥚 Easter Egg: If triggered, return early!
    if (handleEasterEggs(prompt)) {
        input.value = "";
        input.focus();
        return; // Don't run the rest of the handler
    }
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
  
  // #region 🚪 Skyebot Universal Logout Handler (Server-Audited, LGBAS/MTCO)
  window.logoutUser = async function () {
      // #region ⏺️ Server-Side Action Logging (Universal)
      // Logs this logout action server-side for audit/compliance
      await handleSkyebotAction("logout");
      // #endregion

      // #region 🧹 Session Cleanup & UI Reset
      // Clear all relevant session and user state (localStorage, cookies, UI)
      localStorage.removeItem('userLoggedIn');
      localStorage.removeItem('userId');
      document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
      document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/skyesoft/;";
      // Hide or reset the chat panel, if present
      const chatPanel = document.getElementById('chatPanel') || document.querySelector('.chat-wrapper');
      if (chatPanel) chatPanel.style.display = "none";
      // Update the UI to reflect logged-out state
      if (typeof updateLoginUI === "function") {
          updateLoginUI();
      } else {
          location.reload();
      }
      // #endregion
  };
  // #endregion
});
// #endregion