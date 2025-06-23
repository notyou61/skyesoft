document.getElementById("promptForm").addEventListener("submit", async (e) => {
  e.preventDefault();

  const prompt = document.getElementById("promptInput").value.trim();
  const output = document.getElementById("responseOutput");
  output.textContent = "🤖 Thinking...";

  try {
    const res = await fetch("/.netlify/functions/askOpenAI", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ prompt }),
    });

    const data = await res.json();
    output.textContent = data.response || data.error || "(no response)";

    // 🔄 Handle structured actions from backend
    switch (data.action) {
      case "logout":
        sessionStorage.clear();
        location.reload();
        break;

      case "info":
        console.log("ℹ️ Help info shown.");
        break;

      case "versionCheck":
        alert(data.response || "📦 Version info unavailable.");
        break;

      // ⚙️ Future actions (e.g., openModal) can go here
      default:
        break;
    }
  } catch (err) {
    output.textContent = "❌ Network or API error.";
  }
});