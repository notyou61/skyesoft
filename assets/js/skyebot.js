document.getElementById("promptForm").addEventListener("submit", async (e) => {
  e.preventDefault();

  const prompt = document.getElementById("promptInput").value.trim();
  const output = document.getElementById("responseOutput");
  output.textContent = "🤖 Thinking...";

  try {
    const res = await fetch("api/askOpenAI.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ prompt }),
    });

    const data = await res.json();
    output.textContent = data.response || data.error || "(no response)";
  } catch (err) {
    output.textContent = "❌ Network or API error.";
  }
});
