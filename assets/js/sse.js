/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/
window.SkySSE = {
    // 🌐 Start SSE Connection
    start: function () {

        console.log('SkySSE starting...');

        // Close prior connection if any
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

                window.SkyeApp?.onSSE?.(payload);

            } catch (e) {

                console.error("❌ SSE JSON parse error:", e);

            }
        };
    }
    // 🔄 Hard restart (used after login/logout)
    //restart: function () {

    //    console.log('SkySSE restarting...');

    //    if (this.es) {
   //         try { this.es.close(); } catch (e) {}
    //        this.es = null;
    //    }

    //    this.start();
    //}

};