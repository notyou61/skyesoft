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

                    // ✅ ADD IT HERE
                    //console.log('[SSE FULL PAYLOAD]', payload);

                    // 🔍 DEBUG
                    //console.log('[SkySSE RAW AUTH]', {
                    //  incoming: payload.auth,
                    //  prevState: window.SkyState?.authenticated,
                    //  falseCount: window.SkyState?._falseCount
                    //;

                    // 🔐 Auth transition detection
                    if (payload.auth !== undefined) {

                        const isAuthenticated = payload.auth.authenticated === true;

                        window.SkyState = window.SkyState || {};

                        const prev = window.SkyState.authenticated;
                        const initialized = window.SkyState._authInitialized === true;

                        console.log('[SkySSE EVAL]', {
                            isAuthenticated,
                            prevState: prev,
                            initialized,
                            falseCount: window.SkyState._falseCount
                        });

                        // ─────────────────────────────
                        // INITIALIZATION PHASE
                        // ─────────────────────────────
                        if (!initialized) {
                            window.SkyState.authenticated = isAuthenticated;
                            window.SkyState._authInitialized = true;
                            window.SkyState._falseCount = 0;

                            console.log('[SkySSE] auth initialized →', isAuthenticated);
                            return;
                        }

                        // ─────────────────────────────
                        // NORMALIZED STATE HANDLING
                        // ─────────────────────────────
                        window.SkyState._falseCount = window.SkyState._falseCount || 0;

                        if (!isAuthenticated) {

                            window.SkyState._falseCount++;

                            // Ignore transient false
                            if (window.SkyState._falseCount === 1) {
                                console.log('[SkySSE] transient false ignored');
                                return;
                            }

                            // ✅ ONLY logout if user WAS authenticated
                            if (
                                window.SkyState._falseCount >= 2 &&
                                window.SkyState.authenticated === true
                            ) {
                                console.log('[SkySSE] confirmed logout via stream');

                                this.stop();
                                window.SkyeApp?.handleLogout?.('sse');
                                return;
                            }

                        } else {
                            window.SkyState._falseCount = 0;
                        }

                        window.SkyState.authenticated = isAuthenticated;
                    }

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

        if (this.es) {
            this.es.close();
            this.es = null;
        }

        console.log('[SkySSE] stopped');
    }

};