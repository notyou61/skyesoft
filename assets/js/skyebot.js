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
      // 🧹 Logout Action — Trigger global logout handler if available
      case "logout":
        if (typeof logoutUser === "function") {
          logoutUser(); // Clears localStorage, hides dashboard, shows login UI
          console.log("👋 User logged out successfully.");
        } else {
          console.warn("⚠️ logoutUser function not available."); // Fallback warning if function is undefined
        }
        break;
      // ℹ️ Info Action — Placeholder for help/info command feedback
      case "info":
        console.log("ℹ️ Help info shown.");
        break;
      // 🧪 Version Check — Show version info or fallback message
      case "versionCheck":
        alert(data.response || "📦 Version info unavailable.");
        break;
      // ⚙️ Default / Future Actions — Extendable for custom commands (e.g., openModal, showSettings)
      default:
        break;
    }
  } catch (err) {
    output.textContent = "❌ Network or API error.";
  }
});