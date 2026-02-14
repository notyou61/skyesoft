/*
  🧩 Skyesoft — skyeModal.js
  🪟 Domain-Aware Editing Engine
  🏛 Canonical Modal Controller for Governed Data Surfaces
  📍 Phoenix, Arizona – MST timezone
*/

// #region 🧩 SkyeModal Editing Engine
(function () {

    const SkyeModal = {

        // #region 🧠 State
        activeNode: null,
        activeDomainKey: null,
        modalEl: null,
        fields: {},
        initialized: false,
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
                            <strong id="skyeModalTitle">Edit</strong>
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

        // #region 🪟 Open (Forced + Diagnosed)
        open(arg) {
            console.log('Raw arg passed to open():', arg);

            if (!arg) {
                console.warn('open() called without argument');
                return;
            }

            const { node, domainKey } = arg;

            console.log('Destructured:', { node, domainKey });

            if (!node) {
                console.warn('open() aborted — node missing');
                return;
            }

            this.activeNode = node;
            this.activeDomainKey = domainKey;

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

        // #region 🧾 Render Form (Domain Aware)
        renderForm() {
            //
            this.fields = {};

            const body = this.modalEl.querySelector('#skyeModalBody');

            body.innerHTML = '';
            this.fields = {}; // 🔥 Reset field registry

            const node = this.activeNode;

            if (!node) return;

            this.modalEl.querySelector('#skyeModalTitle')
                .textContent = `Edit ${node.type}`;

            // 🔥 Always show label field
            body.appendChild(
                this.createInput('Label', 'label', node.label)
            );

            // Roadmap Phase
            if (this.activeDomainKey === 'roadmap' && node.type === 'phase') {

                body.appendChild(
                    this.createSelect(
                        'Status',
                        'status',
                        node.status ?? 'pending',
                        ['complete', 'in-progress', 'pending']
                    )
                );

                body.appendChild(
                    this.createInput(
                        'Icon ID',
                        'iconId',
                        node.iconId ?? '',
                        'number'
                    )
                );
            }

            // Roadmap Task
            if (this.activeDomainKey === 'roadmap' && node.type === 'task') {

                body.appendChild(
                    this.createInput(
                        'Icon ID',
                        'iconId',
                        node.iconId ?? '',
                        'number'
                    )
                );
            }

            console.log('[SkyeModal] Form rendered for:', node);
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

    window.SkyeModal = SkyeModal;

    document.addEventListener('DOMContentLoaded', () => {
        SkyeModal.init();
    });

})();
// #endregion