// Enhanced Skyebot Chat Interface – v2025.06.25
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

  const addMessage = (role, text) => {
    const entry = document.createElement("div");
    entry.className = `chat-entry ${role === "user" ? "user-message" : "bot-message"}`;
    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
        body: JSON.stringify({ conversation: conversationHistory })
      });

      const data = await res.json();
      const reply = data.response || data.error || "(no response)";
      removeThinking();
      addMessage("bot", reply);

      conversationHistory.push({ role: "assistant", content: reply });

      if (data.action === "logout" && typeof logoutUser === "function") logoutUser();
      if (data.action === "versionCheck") alert(data.response || "📦 Version info unavailable.");

    } catch (err) {
      removeThinking();
      addMessage("bot", "❌ Network or API error.");
    }
  });

  // Start with a welcome message
  addMessage("bot", "Hello! How can I assist you today?");
});
