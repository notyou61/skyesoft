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
  const actionGooglePlaceId = (lat != null && lng != null)
    ? await resolvePlaceId(lat, lng)
    : null;

  let actionTypeID, actionNote = note || "";
  switch (actionType) {
    case "login":
      actionTypeID = 1;
      actionNote = note || "User logged in";
      break;
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
    case "report":
      actionTypeID = 98;
      actionCRUDType = "Create";
      break;
    default:
      actionTypeID = 99;
      actionNote = note || "Other Skyebot action";
  }

const actionObj = {
  actionTypeID,
  actionType: actionType, // ✅ Add explicit action type
  actionContactID: contactID,
  actionNote: actionNote || "Skyebot action recorded",
  actionLatitude: lat,
  actionLongitude: lng,
  actionGooglePlaceId,
  actionTimestamp: Date.now(),
  ...customData
};


  const result = await logAction(actionObj);

  if (result && result.ok) {
    console.log(`[Skyebot] Action logged (${actionType}):`, result.id);
    return true;
  } else {
    console.log(`Skyebot action '${actionType}' could not be logged.`);
    return false;
  }
}
// #endregion

// #region 🔐 Login Utility (Server-Audited)
window.loginUser = async function (customData = {}) {
  await handleSkyebotAction("login", "User logged in", customData);
  if (typeof updateLoginUI === "function") updateLoginUI(true);
};
// #endregion

// #region ⏺️ Universal Action Logger (hardened)
async function logAction(actionObj) {
  const resp = await fetch('/skyesoft/api/addAction.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(actionObj)
  });

  if (!resp.ok) return { ok: false, error: `HTTP ${resp.status}` };

  let data;
  try { data = await resp.json(); }
  catch { return { ok: false, error: 'Bad JSON' }; }

  const isOk = data?.success === true || data?.status === 'ok';
  const actionId = Number.isFinite(data?.actionID) ? data.actionID : null;

  if (isOk && actionId !== null) {
    console.log('Action logged:', actionId);
    return { ok: true, id: actionId, data };
  }

  const msg = data?.message || data?.error || JSON.stringify(data);
  console.warn('Logging failed:', msg);
  return { ok: false, error: msg, data };
}
// #endregion

// #region 🧹 Modal Reset On Close
function toggleModal() {
  const modal = document.getElementById("skyebotModal");
  const isVisible = modal.style.display === "block";

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
  const modalHeight = modalRect.height;

  for (let i = 0; i < count; i++) {
    const emoji = emojis[Math.floor(Math.random() * emojis.length)];
    const span = document.createElement("span");
    span.textContent = emoji;
    span.className = "skyebot-confetti-emoji";
    const left = Math.random() * (modalRect.width - 30);
    span.style.left = left + "px";
    span.style.top = "-25px";
    span.style.animationDelay = (Math.random() * 0.5) + "s";
    span.style.setProperty('--confetti-distance', `${modalHeight}px`);
    span.style.position = "absolute";
    span.style.pointerEvents = "none";
    chatModal.appendChild(span);
    setTimeout(() => { span.remove(); }, 2600);
  }
}
// #endregion

// #region 🥚 Skyebot Easter Egg Handler
function handleEasterEggs(message) {
  const msg = message.trim().toLowerCase();

  if (msg.includes("push it")) {
    console.log("🟢 Skyebot Easter Egg: 'push it' triggered!");
    showEasterEggResponse("🎶 Yo, it's Skyebot! Pushin' it real good... 🧂🕺💃");
    return true;
  }

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

  return false;
}
window.handleEasterEggs = handleEasterEggs;

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
function showAsciiConfetti(message) {
  const chatLog = document.getElementById("chatLog");
  if (!chatLog) return;
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

// #region 🔗 Report Type Extractor
function extractReportType(prompt) {
  const lowerPrompt = prompt.toLowerCase();
  if (lowerPrompt.includes('time interval standards')) return 'time-interval-standards';
  return 'general';
}
// #endregion

// #region 🔗 Report Path Normalizer
function normalizeReportPath(path) {
  if (!path) return null;
  return path
    .replace("/home/notyou64/public_html", "https://www.skyelighting.com")
    .replace(/\\/g, "%20")
    .replace(/ /g, "%20")
    .replace(/\(/g, "%28")
    .replace(/\)/g, "%29");
}
// #endregion

// #region DOMContentLoaded Event
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("promptForm");
  const input = document.getElementById("promptInput");
  const clearBtn = document.getElementById("clearBtn");
  const chatLog = document.getElementById("chatLog");
  if (!form || !input || !clearBtn || !chatLog) {
    console.error("❌ Skyebot setup error: Missing elements.");
    return;
  }

  let conversationHistory = [{
    role: "assistant",
    content: "Hello! How can I assist you today?"
  }];

  fetch("/skyesoft/docs/codex/codex.json")
    .then(res => res.json())
    .then(json => { codexData = json; })
    .catch(() => { codexData = {}; });

  const addMessage = (role, text) => {
    const entry = document.createElement("div");
    entry.className = `chat-entry ${role === "user" ? "user-message" : "bot-message"}`;
    const time = new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    const parsedText = text.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    entry.innerHTML = `<span>${role === "user" ? "👤 You" : "🤖 Skyebot"} [${time}]: ${parsedText}</span>`;
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

  clearBtn.addEventListener("click", () => {
    chatLog.innerHTML = "";
    input.value = "";
    input.focus();
    addMessage("bot", "Hello! How can I assist you today?");
    conversationHistory = [{ role: "assistant", content: "Hello! How can I assist you today?" }];
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const prompt = input.value.trim();
    if (!prompt) return;
    if (handleEasterEggs(prompt)) {
      input.value = "";
      input.focus();
      return;
    }

    addMessage("user", prompt);
    input.value = "";
    input.focus();
    conversationHistory.push({ role: "user", content: prompt });
    showThinking();

    let sseSnapshot = {};
    try {
      const res = await fetch("/skyesoft/api/getDynamicData.php");
      sseSnapshot = await res.json();
      console.log("🛰️ Using live SSE snapshot:", sseSnapshot);
      if (!sseSnapshot || !sseSnapshot.timeDateArray) throw new Error("Live data not ready.");
      sseSnapshot.localStreamCount = typeof window.activeStreams === "number" ? window.activeStreams : 1;
      console.log("🚦 sseSnapshot at submit:", sseSnapshot);
    } catch (err) {
      removeThinking();
      addMessage("bot", "⏳ Please wait a moment while I load live data…");
      return;
    }

    try {
      const data = await sendSkyebotPrompt(prompt, conversationHistory, sseSnapshot);
      removeThinking();
      const reply = data.response || "🤖 Sorry, I didn’t understand that.";

      let fullReply = reply;

      // 🩹 Prevent duplicate “Open Report” link if already present in the server reply
      const alreadyLinked = /📄\s*\[Open Report\]\(/.test(fullReply);

      if (!alreadyLinked && (data.reportUrl || data.result)) {
        const rawPath = data.reportUrl || data.result;
        const publicUrl = normalizeReportPath(rawPath);

        if (publicUrl) {
          fullReply += ` 📄 [Open Report](${publicUrl})`;
          await handleSkyebotAction(
            "report",
            `Generated report: ${extractReportType(prompt)}`,
            { reportUrl: publicUrl }
          );
        } else {
          fullReply += " ⚠️ Report ready, but link unavailable. Contact support.";
        }
      } else if (data.reportError) {
        fullReply += ` ⚠️ Report generation failed: ${data.reportError}. Please try again.`;
      }

      addMessage("bot", fullReply);
      conversationHistory.push({ role: "assistant", content: fullReply });

      const action = data.action ? data.action.toLowerCase().trim() : "";
      const actionType = data.actionType ? data.actionType.toLowerCase().trim() : "";
      const actionName = data.actionName ? data.actionName.toLowerCase().trim() : "";
      if (actionType === "logout" || action === "logout" ||
          (actionType === "create" && actionName === "logout")) {
        console.log("🚪 Logout triggered by backend. Redirecting...");
        window.logoutUser();
      }

    } catch (err) {
      console.error("Client fetch error:", err.message);
      removeThinking();
      const isReportQuery = prompt.toLowerCase().includes('generate') ||
                            prompt.toLowerCase().includes('report') ||
                            prompt.toLowerCase().includes('sheet') ||
                            prompt.toLowerCase().includes('standards');
      if (isReportQuery) {
        addMessage("bot", "❌ Report generation timed out. Check your connection and retry. (Tip: Try a simpler query first!)");
      } else {
        addMessage("bot", "❌ Network error. Please check your connection and try again.");
      }
    }
  });

  async function sendSkyebotPrompt(prompt, conversationHistory = [], sseSnapshot = {}) {
    const isReportQuery = prompt.toLowerCase().includes('generate') ||
                          prompt.toLowerCase().includes('report') ||
                          prompt.toLowerCase().includes('sheet') ||
                          prompt.toLowerCase().includes('standards');

    const response = await fetch("/skyesoft/api/askOpenAI.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        prompt,
        conversation: conversationHistory,
        sseSnapshot,
        metadata: {
          isReportQuery,
          reportType: isReportQuery ? extractReportType(prompt) : null
        }
      }),
    });
    return await response.json();
  }

  addMessage("bot", "Hello! How can I assist you today?");

  window.logoutUser = function () {
    console.log("🚪 Logging out user...");
    localStorage.removeItem('userLoggedIn');
    localStorage.removeItem('userId');
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/skyesoft/;";
    const chatPanel = document.getElementById('chatPanel') || document.querySelector('.chat-wrapper');
    if (chatPanel) chatPanel.style.display = "none";
    if (typeof updateLoginUI === "function") updateLoginUI();
    else location.reload();
  };

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

  window.logoutUser = async function () {
    await handleSkyebotAction("logout");
    try {
      await fetch("/skyesoft/api/logout.php", { method: "POST", credentials: "same-origin" });
    } catch (err) {
      console.warn("Server logout request failed:", err);
    }
    localStorage.removeItem('userLoggedIn');
    localStorage.removeItem('userId');
    document.cookie = "skyelogin_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    document.cookie = "skye_contactID=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    if (typeof updateLoginUI === "function") updateLoginUI();
    else location.reload();
  };
});
// #endregion
