/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/
window.SkySSE = {
    start: function () {

        console.log('SkySSE starting...');

        // Close prior connection if any (prevents duplicates)
        if (this.es) {
            try { this.es.close(); } catch (e) {}
        }

        const es = new EventSource('/skyesoft/api/sse.php', { withCredentials: true });
        this.es = es;

        es.onopen = () => {
            console.log('✅ SSE OPEN');
        };

        es.onerror = (err) => {
            console.warn('⚠ SSE ERROR / reconnecting', err);
        };

        es.onmessage = (event) => {
            console.log("📩 SSE Update");
            try {
                const payload = JSON.parse(event.data);
                window.SkyeApp?.handleSSE?.(payload);
            } catch (e) {
                console.error("❌ SSE JSON parse error:", e, event.data?.slice?.(0, 200));
            }
        };
    }
};