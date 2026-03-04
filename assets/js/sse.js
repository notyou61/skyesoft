/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

console.log("SkySSE starting...");

// Define global SkySSE object
window.SkySSE = {
    // Initialize SSE connection and listeners
    start: function () {
        // Initialize EventSource
        const es = new EventSource('/skyesoft/api/sse.php', { withCredentials: true });
        // Listen for messages
        es.onmessage = (event) => {
            console.log("📩 SSE Update");
            try {
                const payload = JSON.parse(event.data);
                if (window.SkyeApp?.handleSSE) {
                    window.SkyeApp.handleSSE(payload);
                }
            } catch (e) {
                console.error("❌ SSE JSON parse error:", e);
            }
        };
    }
};