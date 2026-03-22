/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

// Window SkySSE
window.SkySSE = {

    es: null,
    streamId: 0,
    restartTimer: null,

    // 🌐 Start SSE Connection (fetch-based with credentials)
    start: async function () {

        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        this.streamId++;
        const currentStream = this.streamId;

        if (this.es) {
            try { this.es.abort?.(); } catch {}
            this.es = null;
        }

        const controller = new AbortController();
        this.es = controller;

        try {

            const res = await fetch('/skyesoft/api/sse.php', {
                method: 'GET',
                credentials: 'include', // 🔥 GUARANTEED COOKIE
                headers: {
                    'Accept': 'text/event-stream'
                },
                signal: controller.signal
            });

            const reader = res.body.getReader();
            const decoder = new TextDecoder();

            let buffer = '';

            while (true) {

                const { done, value } = await reader.read();

                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                const parts = buffer.split("\n\n");
                buffer = parts.pop();

                for (const part of parts) {

                    if (currentStream !== this.streamId) return;

                    const line = part.split("\n").find(l => l.startsWith("data:"));
                    if (!line) continue;

                    const jsonStr = line.replace(/^data:\s*/, '');

                    try {

                        const payload = JSON.parse(jsonStr);

                        // 🔐 Auth State Transition
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
                }
            }

        } catch (err) {
            console.warn('[SkySSE] fetch stream error', err);
        }
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