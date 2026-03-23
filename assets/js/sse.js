/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

// Window SkySSE
window.SkySSE = {

    es: null,
    streamId: 0,
    restartTimer: null,

    // 🌐 Start SSE Connection (Authoritative)
    start: function () {

        if (this.es) {
            this.es.close();
        }

        this.streamId++;
        const currentStream = this.streamId;

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

                // 🔐 Auth transition detection
                if (payload.auth !== undefined) {

                    const isAuthenticated = payload.auth.authenticated === true;

                    if (!isAuthenticated && window.SkyState?.authenticated === true) {

                        console.log('[SkySSE] detected logout via stream');

                        this.stop();
                        window.SkyeApp?.handleLogout?.('sse');
                        return;
                    }

                    window.SkyState = window.SkyState || {};
                    window.SkyState.authenticated = isAuthenticated;
                }

                window.SkyeApp?.handleSSE?.(payload);

            } catch (err) {
                console.warn('[SkySSE] parse error', err);
            }
        };

        es.onerror = (err) => {
            console.warn('[SkySSE] connection error', err);

            // Auto-reconnect handled by browser
            // Optional manual restart:
            this.restart();
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

        }, 800);
    },

    // ⛔ Stop stream
    stop: function () {

        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }

        console.log('[SkySSE] stopped');
    },

};