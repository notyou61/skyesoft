/*
  🧩 Skyesoft — skyeModal.js
  🪟 Domain-Aware Editing Engine
  🏛 Canonical Modal Controller for Governed Data Surfaces
  📍 Phoenix, Arizona – MST timezone
*/

// #region 🧩 SkyeModal Editing Engine
(function () {
    // #region 🧠 State & Config
    const SkyeModal = {

        // #region 🧠 State
        activeNode: null,
        activeDomainKey: null,
        modalEl: null,
        fields: {},
        initialized: false,
        // #endregion

        // #region 🧩 Canonical CRUD Icon Map
        crudIcons: {
            create: 3,
            read: 20,
            update: 23,  // 💾 Save / Persist
            delete: 72
        },
        // #endregion

        // #region 🎨 Icon Resolver
        resolveIcon(iconId) {

            const iconMap = window.SkyIndex?.iconMap;

            if (!iconMap?.icons) return '';

            const entry = iconMap.icons[String(iconId)];
            return entry?.emoji ?? '';
        },
        // #endregion
        
        // #region 🚀 Init
        init() {

            if (this.initialized) return;

            this.buildModal();
            this.initialized = true;

            // ESC key support
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.modalEl?.style.display === 'block') {
                    this.close();
                }
            });
        },
        // #endregion

        // #region 🏗 Build Modal Shell
        buildModal() {

            const wrapper = document.createElement('div');
            wrapper.id = 'skyeModal';
            wrapper.style.display = 'none';
            wrapper.style.position = 'fixed';
            wrapper.style.inset = '0';
            wrapper.style.background = 'rgba(0,0,0,0.45)';
            wrapper.style.zIndex = '2000';

            wrapper.innerHTML = `
                <div class="bodyPanel" style="max-width:720px; margin:8vh auto; max-height:84vh;">
                    <div class="bodyHeader">
                        <div class="bodyTitle">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span id="skyeModalActionIcon" style="font-size:20px;"></span>
                                <strong id="skyeModalTitle">Edit</strong>
                            </div>
                        </div>
                    </div>

                    <div class="bodyDivider"></div>

                    <div class="bodyContent" id="skyeModalBody"></div>

                    <div class="bodyDivider"></div>

                    <div class="bodyFooter">
                        <button class="btn" id="skyeModalCancel">Cancel</button>
                        <button class="btn" id="skyeModalSave">Save</button>
                    </div>
                </div>
            `;

            document.body.appendChild(wrapper);
            this.modalEl = wrapper;

            wrapper.querySelector('#skyeModalCancel')
                .addEventListener('click', () => this.close());

            wrapper.querySelector('#skyeModalSave')
                .addEventListener('click', () => this.save());

            // Click outside panel closes
            wrapper.addEventListener('click', (e) => {
                if (e.target === wrapper) this.close();
            });
        },
        // #endregion

        // #region 🪟 Open (CRUD-Aware)
        open(arg) {

            console.log('[SkyeModal] open() raw arg:', arg);

            if (!arg || !arg.domainKey) {
                console.warn('[SkyeModal] open() aborted — invalid argument');
                return;
            }

            const {
                node = null,
                domainKey,
                mode = 'update' // 🔥 default behavior
            } = arg;

            // Mode validation
            const allowedModes = ['create', 'read', 'update', 'delete'];
            if (!allowedModes.includes(mode)) {
                console.warn('[SkyeModal] Invalid mode:', mode);
                return;
            }

            // For update/read/delete, node must exist
            if (mode !== 'create' && !node) {
                console.warn('[SkyeModal] open() aborted — node required for mode:', mode);
                return;
            }

            this.activeNode = node;
            this.activeDomainKey = domainKey;
            this.activeMode = mode;   // 🔥 NEW

            console.log('[SkyeModal] Opening modal:', {
                id: node?.id ?? '(new)',
                mode,
                domainKey
            });

            this.renderForm();
            this.modalEl.style.display = 'block';
        },
        // #endregion

        // #region ❌ Close
        close() {
            if (!this.modalEl) return;

            this.modalEl.style.display = 'none';
            this.activeNode = null;
            this.activeDomainKey = null;
            this.fields = {};
        },
        // #endregion

        // #region 🧾 Render Form (CRUD + Domain Aware)
        renderForm() {

            this.fields = {};

            const body = this.modalEl.querySelector('#skyeModalBody');
            body.innerHTML = '';

            const node = this.activeNode;
            const mode = this.activeMode ?? 'update';

            // ----------------------------------------------------
            // 🏷 Header Title + CRUD Icon
            // ----------------------------------------------------

            const typeLabel = node?.type ?? 'node';

            const iconId = this.crudIcons[mode] ?? this.crudIcons.update;
            const iconEl = this.modalEl.querySelector('#skyeModalActionIcon');
            const titleEl = this.modalEl.querySelector('#skyeModalTitle');

            if (iconEl) {
                iconEl.textContent = this.resolveIcon(iconId);
            }

            const capitalized = mode.charAt(0).toUpperCase() + mode.slice(1);

            // Prefer node label over type
            const nodeLabel = node?.label ?? typeLabel;

            titleEl.textContent = `${capitalized} — ${nodeLabel}`;

            // ----------------------------------------------------
            // 🗑 DELETE MODE (Confirmation UI Only)
            // ----------------------------------------------------

            if (mode === 'delete') {

                const deleteIcon = this.resolveIcon(this.crudIcons.delete);

                const wrapper = document.createElement('div');
                wrapper.className = 'formGroup';

                wrapper.innerHTML = `
                    <div style="
                        padding:16px;
                        border:1px solid #c33;
                        background:#fff4f4;
                        border-radius:6px;
                    ">
                        <div style="font-size:18px; margin-bottom:8px;">
                            ${deleteIcon} <strong>Delete ${node?.type ?? 'node'}</strong>
                        </div>

                        <p style="margin:0 0 6px 0; color:#333;">
                            You are about to permanently remove:
                        </p>

                        <p style="margin:0; font-weight:600; color:#000;">
                            ${node?.label ?? '(Unnamed)'}
                        </p>
                    </div>
                `;

                body.appendChild(wrapper);
                return;
            }

            // ----------------------------------------------------
            // 🆕 CREATE MODE (Empty Node Template)
            // ----------------------------------------------------

            const workingNode = mode === 'create'
                ? { type: node?.type ?? 'phase' }
                : node;

            if (!workingNode) return;

            // ----------------------------------------------------
            // 🧱 Shared Fields
            // ----------------------------------------------------

            body.appendChild(
                this.createInput('Label', 'label', workingNode.label ?? '')
            );

            // ----------------------------------------------------
            // 🗺 Roadmap Domain Logic
            // ----------------------------------------------------

            if (this.activeDomainKey === 'roadmap') {

                // Phase
                if (workingNode.type === 'phase') {

                    body.appendChild(
                        this.createSelect(
                            'Status',
                            'status',
                            workingNode.status ?? 'pending',
                            ['complete', 'in-progress', 'pending']
                        )
                    );

                    body.appendChild(
                        this.createInput(
                            'Icon ID',
                            'iconId',
                            workingNode.iconId ?? '',
                            'number'
                        )
                    );
                }

                // Task
                if (workingNode.type === 'task') {

                    body.appendChild(
                        this.createInput(
                            'Icon ID',
                            'iconId',
                            workingNode.iconId ?? '',
                            'number'
                        )
                    );
                }
            }

            // ----------------------------------------------------
            // 👁 READ MODE (Disable All Fields)
            // ----------------------------------------------------

            if (mode === 'read') {

                Object.values(this.fields).forEach(el => {
                    el.disabled = true;
                });

                // Hide Save button in read mode
                const saveBtn = this.modalEl.querySelector('#skyeModalSave');
                if (saveBtn) saveBtn.style.display = 'none';

            } else {

                const saveBtn = this.modalEl.querySelector('#skyeModalSave');
                if (saveBtn) saveBtn.style.display = 'inline-block';
            }

            console.log('[SkyeModal] Form rendered:', {
                mode,
                node: workingNode
            });
        },
        // #endregion

        // #region 🧱 Field Builders
        createInput(label, key, value, type = 'text') {

            const wrapper = document.createElement('div');
            wrapper.className = 'formGroup';

            const input = document.createElement('input');
            input.className = 'form-control';
            input.type = type;
            input.value = value ?? '';

            this.fields[key] = input;

            wrapper.innerHTML = `<label>${label}</label>`;
            wrapper.appendChild(input);

            return wrapper;
        },

        createSelect(label, key, value, options = []) {

            const wrapper = document.createElement('div');
            wrapper.className = 'formGroup';

            const select = document.createElement('select');
            select.className = 'form-control';

            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt;
                o.textContent = opt;
                if (opt === value) o.selected = true;
                select.appendChild(o);
            });

            this.fields[key] = select;

            wrapper.innerHTML = `<label>${label}</label>`;
            wrapper.appendChild(select);

            return wrapper;
        },
        // #endregion

        // #region 💾 Save (Optimistic Update Engine)
        save() {

            if (!this.activeNode) return;

            const updates = {};

            Object.keys(this.fields).forEach(key => {

                let value = this.fields[key].value;

                // Normalize types
                if (key === 'iconId') {
                    value = value === '' ? null : Number(value);
                }

                updates[key] = value;
            });

            console.log('[SkyeModal] Save payload:', {
                id: this.activeNode.id,
                domain: this.activeDomainKey,
                updates
            });

            // 🔥 Apply optimistic mutation
            Object.assign(this.activeNode, updates);

            // 🔁 Re-render active domain surface
            if (window.SkyIndex?.activeDomainKey === this.activeDomainKey) {

                const model = window.SkyIndex.activeDomainModel;

                if (model) {
                    window.SkyIndex.updateDomainSurface(
                        this.activeDomainKey,
                        model
                    );
                }
            }

            this.close();
        }
        // #endregion
    };
    // #endregion

    // #region Expose SkyeModal globally
    window.SkyeModal = SkyeModal;
    document.addEventListener('DOMContentLoaded', () => {
        SkyeModal.init();
    });
    // #endregion

})();
// #endregion