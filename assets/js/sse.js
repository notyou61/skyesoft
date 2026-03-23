/* Skyesoft — sse.js
   SSE Engine → Push JSON Updates to Global App Handler
*/

// Window SkySSE
window.SkySSE = {

    es: null,
    streamId: 0,
    restartTimer: null,

    // 🌐 Start SSE Connection (Authoritative)
    start: async function () {

        if (this.restartTimer) {
            clearTimeout(this.restartTimer);
            this.restartTimer = null;
        }

        this.streamId++;
        const currentStream = this.streamId;

        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }

        const controller = new AbortController();
        this.controller = controller;

        try {

            const res = await fetch('/skyesoft/api/sse.php', {
                method: 'GET',
                credentials: 'include', // 🔥 THIS is what fixes everything
                headers: {
                    'Accept': 'text/event-stream'
                },
                cache: 'no-store',
                signal: controller.signal
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder('utf-8');

            let buffer = '';

            while (true) {

                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                const parts = buffer.split("\n\n");
                buffer = parts.pop();

                for (const part of parts) {

                    if (currentStream !== this.streamId) return;

                    const line = part.split("\n").find(l => l.startsWith("data: "));
                    if (!line) continue;

                    try {

                        const payload = JSON.parse(line.replace("data: ", ""));

                        console.log('[SkySSE RAW AUTH]', {
                            incoming: payload.auth,
                            prevState: window.SkyState?.authenticated,
                            falseCount: window.SkyState?._falseCount
                        });

                        // 🔐 Auth transition detection
                        if (payload.auth !== undefined) {

                            const isAuthenticated = payload.auth.authenticated === true;

                            console.log('[SkySSE EVAL]', {
                                isAuthenticated,
                                prevState: window.SkyState?.authenticated,
                                falseCount: window.SkyState?._falseCount
                            });

                            window.SkyState = window.SkyState || {};

                            // 🔥 Track transient false count
                            window.SkyState._falseCount = window.SkyState._falseCount || 0;

                            if (!isAuthenticated) {

                                window.SkyState._falseCount++;

                                // 🔥 IGNORE first false (race condition)
                                if (window.SkyState._falseCount === 1 && window.SkyState.authenticated === true) {
                                    console.log('[SkySSE] transient false (race) ignored');
                                    return;
                                }

                                // 🔓 REAL LOGOUT (confirmed)
                                if (window.SkyState._falseCount >= 2) {
                                    console.log('[SkySSE] confirmed logout via stream');

                                    this.stop();
                                    window.SkyeApp?.handleLogout?.('sse');
                                    return;
                                }

                            } else {
                                // ✅ Reset on true
                                window.SkyState._falseCount = 0;
                            }

                            // ✅ Update state
                            window.SkyState.authenticated = isAuthenticated;
                        }

                        window.SkyeApp?.handleSSE?.(payload);

                    } catch (err) {
                        console.warn('[SkySSE] parse error', err);
                    }
                }
            }

        } catch (err) {
            // Ignore abort errors (expected on stop/restart)
            if (err?.name === 'AbortError') {
                return;
            }
            console.warn('[SkySSE] stream error', err);
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

        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }

        console.log('[SkySSE] stopped');
    },

};