
let thread = [];

function sendToSkyebot() {
  const input = document.getElementById("skyebot-input").value.trim();
  if (!input) return;

  if (input.toLowerCase() === "/clear") {
    clearSkyebotThread();
    return;
  }

  const timestamp = new Date().toLocaleTimeString();
  thread.push({ role: "user", content: input, time: timestamp });
  appendToThread("user", input, timestamp);

  // Simulate bot reply
  const reply = "You said: " + input;
  const replyTime = new Date().toLocaleTimeString();
  thread.push({ role: "bot", content: reply, time: replyTime });
  appendToThread("bot", reply, replyTime);

  document.getElementById("skyebot-input").value = "";
}

function appendToThread(role, content, time) {
  const threadDiv = document.getElementById("skyebot-thread");
  const div = document.createElement("div");
  div.className = "skyebot-message " + role;
  const formatted = window.marked ? marked.parse(content) : content;
  div.innerHTML = formatted + `<span class="timestamp">${time}</span>`;
  threadDiv.appendChild(div);
  threadDiv.scrollTop = threadDiv.scrollHeight;
}

function clearSkyebotThread() {
  thread = [];
  document.getElementById("skyebot-thread").innerHTML = "";
}

function closeSkyebot() {
  document.getElementById("skyebotModal").style.display = "none";
}
