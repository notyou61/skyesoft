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

        // Ensure no restart timer remains
        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        // Increment authoritative stream ID
        this.streamId++;

        const currentStream = this.streamId;

        // Close any existing stream
        if (this.es) {
            try {
                this.es.close();
            } catch (e) {
                console.warn('[SkySSE] previous stream close failed', e);
            }
            this.es = null;
        }

        const es = new EventSource('/skyesoft/api/sse.php', { withCredentials: true });
        this.es = es;

        es.onopen = () => {
            //console.log('[SkySSE] OPEN', currentStream);
            console.log('[SkySSE] connected');
        };

        es.onerror = (err) => {
            //console.warn('[SkySSE] ERROR / reconnecting', err);
        };

        es.onmessage = (event) => {

            // Ignore messages from stale streams
            if (currentStream !== this.streamId) {
                return;
            }

            if (!event.data) return;

            try {
                // Parse JSON payload
                const payload = JSON.parse(event.data);
                //
                console.log('[SkySSE MESSAGE]', payload);

                // Handle auth projection (if present)
                if (payload.auth !== undefined) {
                    // Console log auth projection for debugging (can be removed in production)
                    //console.log('[SkySSE] auth projection', payload.auth, 'session:', payload.sessionId);
                }

                // Existing handler
                window.SkyeApp?.handleSSE?.(payload);

                // Temporary direct handler
                window.SkyIndex?.handleSSE?.(payload);

            } catch (err) {

                console.warn('[SkySSE] SSE parse error', err);

            }

        };
    },

    // 🔁 Restart stream (safe restart)
    restart: function () {

        //console.log('[SkySSE] restarting stream');

        // Cancel any pending restart
        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        // Small delay ensures session cookie commit
        this.restartTimer = setTimeout(() => {

            //
            this.restartTimer = null;
            //
            console.log('[SkySSE] restart timer fired');
            //
            this.start();

        }, 650);
    },

    // ⛔ Stop stream
    stop: function () {

        // Cancel pending restart
        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        if (this.es) {
            try {
                this.es.close();
            } catch (e) {
                console.warn('[SkySSE] stop close failed', e);
            }
        }

        this.es = null;

        console.log('[SkySSE] stopped');
    }

};