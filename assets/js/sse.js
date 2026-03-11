/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

window.SkySSE = {

    es: null,
    streamId: 0,

    // 🌐 Start SSE Connection
    start: function () {

        this.streamId++;

        const currentStream = this.streamId;

        console.log('[SkySSE] starting stream', currentStream);

        if (this.es) {
            try { this.es.close(); } catch (e) {}
        }

        const es = new EventSource('/skyesoft/api/sse.php', { withCredentials: true });
        this.es = es;

        es.onopen = () => {
            console.log('[SkySSE] OPEN', currentStream);
        };

        es.onerror = (err) => {
            console.warn('[SkySSE] ERROR / reconnecting', err);
        };

        es.onmessage = (event) => {

        if (!event.data) return;

        try {

            const payload = JSON.parse(event.data);

            //console.log("📩 SSE Update", payload);

            window.SkyeApp?.handleSSE?.(payload);

        } catch(err) {

            console.warn("⚠ SSE parse error", err);

        }

        };
    },

    // 🔁 Restart stream
    restart: function () {

        console.log('[SkySSE] restarting stream');

        this.start();
    },

    // ⛔ Stop stream
    stop: function () {

        if (this.es) {
            try { this.es.close(); } catch (e) {}
        }

        this.es = null;

        console.log('[SkySSE] stopped');

    }

};