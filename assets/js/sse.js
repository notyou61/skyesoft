/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

// Window SkySSE
window.SkySSE = {

    es: null,
    streamId: 0,
    restartTimer: null,

    // 🌐 Start SSE Connection
    start: function () {

        this.streamId++;

        const currentStream = this.streamId;

        console.log('[SkySSE] starting stream', currentStream);

        // Close any existing stream
        if (this.es) {
            try { this.es.close(); } catch (e) {}
            this.es = null;
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

            // Ignore stale streams
            if (currentStream !== this.streamId) {
                return;
            }

            if (!event.data) return;

            try {

                const payload = JSON.parse(event.data);

                window.SkyeApp?.handleSSE?.(payload);

            } catch(err) {

                console.warn('⚠ SSE parse error', err);

            }

        };
    },

    // 🔁 Restart stream
    restart: function () {

        console.log('[SkySSE] restarting stream');

        // Cancel any pending restart
        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        // Small delay ensures session cookie is committed
        this.restartTimer = setTimeout(() => {

            this.restartTimer = null;
            this.start();

        }, 150);

    },

    // ⛔ Stop stream
    stop: function () {

        // Cancel pending restart
        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        if (this.es) {
            try { this.es.close(); } catch (e) {}
        }

        this.es = null;

        console.log('[SkySSE] stopped');

    }

};