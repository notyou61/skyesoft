// ======================================================================
// Skyesoft — workspace.js
// Modal Workspace (navigation stack + page host)
// Codex-aligned: registry, rich stack entries, explicit back titles
// ======================================================================

window.SkyWorkspace = {

    // ── State ────────────────────────────────────────────────────────
    stack: [],              // rich page descriptors
    modalEl: null,          // #skyWorkspaceModal
    _keyHandler: null,
    _registry: Object.create(null),  // pageType → async renderer(page, ctx)

    // ── Page Registry ────────────────────────────────────────────────

    /**
     * Register a page renderer.
     * @param {string} pageType  e.g. 'entity', 'locations', 'location'
     * @param {Function} renderer  async (page, ctx) => { titleHtml, bodyHtml, actionsHtml? }
     */
    registerPage(pageType, renderer) {
        if (!pageType || typeof renderer !== 'function') {
            console.warn('[SkyWorkspace] registerPage requires pageType + function');
            return;
        }
        this._registry[pageType] = renderer;
    },

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Open (or reset) the workspace on a root page.
     * @param {Object} page
     *   pageType, objectType?, objectId?, title?, parentTitle?, state?
     */
    open(page) {
        if (!page?.pageType) {
            console.warn('[SkyWorkspace] open() requires pageType');
            return;
        }
        this.stack = [this._normalize(page)];
        this._ensureModal();
        this.render();
    },

    /**
     * Push a page onto the stack and re-render.
     * Caller should set parentTitle when a back label is needed.
     */
    push(page) {
        if (!page?.pageType) return;
        this.stack.push(this._normalize(page));
        this.render();
    },

    /**
     * Pop one level. Closes when at root.
     */
    pop() {
        if (this.stack.length <= 1) {
            this.close();
            return;
        }
        this.stack.pop();
        this.render();
    },

    /**
     * Replace the top page (no stack growth).
     */
    replace(page) {
        if (!page?.pageType) return;
        const normalized = this._normalize(page);
        if (this.stack.length === 0) {
            this.stack = [normalized];
        } else {
            this.stack[this.stack.length - 1] = normalized;
        }
        this.render();
    },

    /**
     * Close workspace and clear stack.
     */
    close() {
        if (this.modalEl) {
            this.modalEl.remove();
            this.modalEl = null;
        }
        if (this._keyHandler) {
            document.removeEventListener('keydown', this._keyHandler);
            this._keyHandler = null;
        }
        this.stack = [];
    },

    /**
     * Re-render the top of the stack.
     */
    async render() {
        if (!this.modalEl) this._ensureModal();

        const top = this.stack[this.stack.length - 1];
        if (!top) {
            this.close();
            return;
        }

        const dialog = this.modalEl.querySelector('[role="dialog"]');
        if (!dialog) return;

        dialog.innerHTML = `
            <div style="padding:28px 18px; text-align:center; color:#666;">
                Loading…
            </div>
        `;

        const ctx = {
            push:      (p) => this.push(p),
            pop:       ()  => this.pop(),
            replace:   (p) => this.replace(p),
            close:     ()  => this.close(),
            stack:     this.stack,
            workspace: this
        };

        try {
            const renderer = this._registry[top.pageType];
            if (typeof renderer !== 'function') {
                throw new Error(`No renderer registered for pageType: ${top.pageType}`);
            }

            const result = await renderer(top, ctx);
            // { titleHtml, bodyHtml, actionsHtml? }

            const canGoBack = this.stack.length > 1;
            // Explicit back title from the page that was pushed (or its parentTitle)
            const backLabel = canGoBack
                ? (top.parentTitle || 'Back')
                : null;

            dialog.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;
                            padding:14px 18px; border-bottom:1px solid #e8e8e8; background:#fafafa;">
                    <div style="display:flex; align-items:center; gap:9px; min-width:0; flex:1;">
                        ${canGoBack ? `
                            <button type="button" onclick="SkyWorkspace.pop()"
                                    style="border:0; background:transparent; color:#117a8b; cursor:pointer;
                                           font-size:0.95em; font-weight:600; padding:0 4px 0 0; white-space:nowrap;">
                                ← ${this._escape(backLabel)}
                            </button>
                        ` : ''}
                        <div style="min-width:0; overflow:hidden;">
                            ${result.titleHtml || ''}
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                        ${result.actionsHtml || ''}
                        <button type="button" onclick="SkyWorkspace.close()"
                                aria-label="Close"
                                style="border:0; background:transparent; color:#666; cursor:pointer;
                                       font-size:1.5rem; line-height:1; margin-left:4px;">×</button>
                    </div>
                </div>
                <div style="padding:16px 18px 18px; max-height:70vh; overflow-y:auto;">
                    ${result.bodyHtml || ''}
                </div>
            `;

        } catch (err) {
            console.error('[SkyWorkspace] render failed:', err);
            dialog.innerHTML = `
                <div style="padding:24px 18px; color:#c0392b; text-align:center;">
                    ${this._escape(err.message || 'Failed to load page.')}
                </div>
                <div style="padding:0 18px 18px; text-align:center;">
                    <button type="button" onclick="SkyWorkspace.pop()"
                            style="padding:6px 14px; border:1px solid #ccc; border-radius:6px; background:#fff; cursor:pointer;">
                        Back
                    </button>
                </div>
            `;
        }
    },

    // ── Internals ────────────────────────────────────────────────────

    _normalize(page) {
        return {
            pageType:    page.pageType,
            objectType:  page.objectType  ?? page.pageType,
            objectId:    page.objectId    ?? null,
            title:       page.title       ?? null,
            parentTitle: page.parentTitle ?? null,
            state:       page.state       ?? {}
        };
    },

    _ensureModal() {
        if (this.modalEl && document.body.contains(this.modalEl)) return;

        const existing = document.getElementById('skyWorkspaceModal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'skyWorkspaceModal';
        modal.className = 'modal-backdrop';
        modal.style.cssText = `
            position:fixed; inset:0; z-index:10000;
            display:flex; align-items:center; justify-content:center;
            padding:20px; background:rgba(0,0,0,0.58);
        `;

        modal.innerHTML = `
            <div role="dialog" aria-modal="true"
                 style="width:100%; max-width:680px; background:#fff; border-radius:8px;
                        box-shadow:0 18px 48px rgba(0,0,0,0.28); overflow:hidden;">
            </div>
        `;

        modal.addEventListener('click', (e) => {
            if (e.target === modal) this.close();
        });

        this._keyHandler = (e) => {
            if (e.key === 'Escape') this.pop();
        };
        document.addEventListener('keydown', this._keyHandler);

        document.body.appendChild(modal);
        this.modalEl = modal;
    },

    _escape(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
};