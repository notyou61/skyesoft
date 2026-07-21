/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
   (Persistent-stream architecture: never stops the stream on logout)
*/

window.SkySSE = {

    es: null,
    streamId: 0,
    restartTimer: null,

    // 🌐 Start SSE Connection (Authoritative)
    start: function () {

        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        this.streamId++;
        const currentStream = this.streamId;

        // 🔥 Close existing EventSource
        if (this.es) {
            this.es.close();
            this.es = null;
        }

        try {

            const es = new EventSource('/skyesoft/api/sse.php', {
                withCredentials: true
            });

            this.es = es;

            es.onopen = () => {
                console.log('[SkySSE] connected');
            };

            es.onmessage = (event) => {

                if (currentStream !== this.streamId) return;

                try {

                    const payload = JSON.parse(event.data);

                    // 🔥 STORE LAST PAYLOAD (single authoritative place)
                    window.SkyeApp = window.SkyeApp || {};
                    window.SkyeApp.lastSSE = payload;

                    // 🔐 Lightweight auth state mirror only
                    if (payload.auth !== undefined) {
                        window.SkyState = window.SkyState || {};
                        window.SkyState.authenticated = payload.auth.authenticated === true;
                    }

                    // 🔥 SINGLE call into the global handler
                    window.SkyeApp?.handleSSE?.(payload);

                } catch (err) {
                    console.warn('[SkySSE] parse error', err);
                }
            };

            es.onerror = (err) => {
                console.warn('[SkySSE] connection error', err);
            };

        } catch (err) {
            console.warn('[SkySSE] stream error', err);
        }
    },

    // 🔁 Restart stream (safe restart)
    restart: function () {

        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        // Small delay ensures session cookie commit
        this.restartTimer = setTimeout(() => {
            this.restartTimer = null;
            console.log('[SkySSE] restart timer fired');
            this.start();
        }, 800);
    },

    // ⛔ Stop stream (only for explicit teardown, never on idle logout)
    stop: function () {

        if (this.es) {
            this.es.close();
            this.es = null;
        }

        console.log('[SkySSE] stopped');
    }

};