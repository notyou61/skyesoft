/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

window.SkySSE = {

    start: function () {

        const es = new EventSource("/api/sse.php");

        es.addEventListener("update", (event) => {
            console.log("📩 SSE Update");
            try {
                const payload = JSON.parse(event.data);
                if (window.SkyeApp?.handleSSE) {
                    window.SkyeApp.handleSSE(payload);
                }
            } catch (e) {
                console.error("❌ SSE JSON parse error:", e);
            }
        });

        es.onerror = (err) => {
            console.warn("⚠ SSE disconnected; retrying in 3s");
        };
    }
};
