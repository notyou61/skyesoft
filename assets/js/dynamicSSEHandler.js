// 🛰️ dynamicSSEHandler.js – Skyesoft Live SSE Stream Handler (v2.0)
// Purpose: Maintain a continuous EventSource connection to getDynamicData.php
// Compatible with: PHP 5.6 SSE backend (data: {...}\n\n)
// Codex-Aligned: Resilience (auto-reconnect), Transparency (heartbeat log), Efficiency (1s updates)

(function () {
  const endpoint = "/skyesoft/api/getDynamicData.php";
  let es = null;
  let reconnectTimer = null;
  let lastPing = Date.now();

  // 💓 Heartbeat timer — log once per second if data arrives
  setInterval(() => {
    if (window.lastSSEData) {
      const t = new Date().toLocaleTimeString();
      console.log(`[SSE 💓] Stream active — ${t}`);
    } else {
      console.log("[SSE ⏳] Waiting for first payload…");
    }
  }, 1000);

  // 🔄 Initialize EventSource stream
  function startSSE() {
    if (es) es.close();
    console.log(`[SSE] Connecting to ${endpoint} …`);
    es = new EventSource(endpoint);

    // 📡 On data message
    es.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        window.lastSSEData = data;
        lastPing = Date.now();

        // Optional: broadcast event globally
        const evt = new CustomEvent("SSEUpdate", { detail: data });
        window.dispatchEvent(evt);

        // Update visible UI (if function defined)
        if (typeof updateDynamicUI === "function") updateDynamicUI(data);

      } catch (err) {
        console.error("[SSE ❌] Parse error:", err, event.data);
      }
    };

    // ⚠️ On error / connection loss
    es.onerror = (err) => {
      console.warn("[SSE ⚠️] Connection lost:", err);
      es.close();
      if (!reconnectTimer) {
        reconnectTimer = setTimeout(() => {
          reconnectTimer = null;
          startSSE();
        }, 3000); // auto-reconnect after 3 s
      }
    };
  }

  // 🚀 Launch stream
  startSSE();

  // 🧩 Allow manual restart if needed
  window.restartDynamicSSE = () => {
    console.log("[SSE] Manual restart requested.");
    startSSE();
  };

  // 🧠 Optional: helper to get last data snapshot
  window.getDynamicSnapshot = () => window.lastSSEData || null;
})();
