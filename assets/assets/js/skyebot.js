const promptForm = document.getElementById("promptForm");
const promptInput = document.getElementById("promptInput");
const responseOutput = document.getElementById("responseOutput");
const clearBtn = document.getElementById("clearThread");

const formatTimestamp = () => {
  const now = new Date();
  return now.toLocaleString();
};

const appendToThread = (role, text) => {
  const entry = document.createElement("div");
  entry.className = role === "user" ? "user-entry" : "bot-entry";

  const timestamp = document.createElement("div");
  timestamp.className = "timestamp";
  timestamp.textContent = formatTimestamp();

  const content = document.createElement("div");
  content.className = "markdown";
  content.innerHTML = marked.parse(text);

  entry.appendChild(timestamp);
  entry.appendChild(content);
  responseOutput.appendChild(entry);
  responseOutput.scrollTop = responseOutput.scrollHeight;

  let history = JSON.parse(localStorage.getItem("skyebotThread") || "[]");
  history.push({ role, text, time: new Date().toISOString() });
  localStorage.setItem("skyebotThread", JSON.stringify(history));
};

promptForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const prompt = promptInput.value.trim();
  if (!prompt) return;

  appendToThread("user", prompt);
  promptInput.value = "";
  appendToThread("bot", "🤖 Thinking...");

  try {
    const res = await fetch("/.netlify/functions/askOpenAI", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ prompt }),
    });
    const data = await res.json();
    responseOutput.lastChild.remove();

    if (["clear", "reset"].includes(prompt.toLowerCase())) {
      localStorage.removeItem("skyebotThread");
      responseOutput.innerHTML = "";
      appendToThread("bot", "🧹 Cleared the thread.");
    } else {
      appendToThread("bot", data.response || data.error || "(no response)");
    }
  } catch (err) {
    appendToThread("bot", "❌ Network or API error.");
  }
});

clearBtn.addEventListener("click", () => {
  localStorage.removeItem("skyebotThread");
  responseOutput.innerHTML = "";
  appendToThread("bot", "🧹 Cleared the thread.");
});

window.addEventListener("load", () => {
  const history = JSON.parse(localStorage.getItem("skyebotThread") || "[]");
  history.forEach(({ role, text }) => appendToThread(role, text));
});
