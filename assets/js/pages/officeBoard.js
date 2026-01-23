// #region GLOBAL REGISTRIES

let jurisdictionRegistry = null;
let permitRegistryMeta = null;
let latestActivePermits = [];
let iconMap = null;

// #endregion


// #region FORMAT HELPERS

function resolveJurisdictionLabel(raw) {
    if (!raw || !jurisdictionRegistry) return raw;

    const norm = String(raw).trim().toUpperCase();

    for (const key in jurisdictionRegistry) {
        const entry = jurisdictionRegistry[key];
        if (!entry) continue;

        if (key.toUpperCase() === norm) return entry.label;

        if (Array.isArray(entry.aliases)) {
            if (entry.aliases.some(a => a.toUpperCase() === norm)) {
                return entry.label;
            }
        }
    }

    return norm.toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
}

function formatStatus(status) {
    if (!status) return '';
    return String(status)
        .toLowerCase()
        .split('_')
        .map(w => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

function formatTimestamp(ts) {
    if (!ts) return '--/--/-- --:--';
    const date = new Date(ts * 1000);
    return date.toLocaleString('en-US', {
        timeZone: 'America/Phoenix',
        month: '2-digit',
        day: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    }).replace(',', '');
}

// #endregion


// #region ICON HELPERS

function getStatusIcon(status) {
    if (!status || !iconMap) return '';

    const keyMap = {
        under_review:   'clock',
        need_to_submit: 'warning',
        submitted:      'clipboard',
        ready_to_issue: 'memo',
        issued:         'shield',
        finaled:        'trophy',
        corrections:    'tools'
    };

    const iconKey = keyMap[status.toLowerCase()];
    if (!iconKey) return '';

    const entry = Object.values(iconMap).find(e =>
        (e.file && e.file.toLowerCase().includes(iconKey)) ||
        (e.alt && e.alt.toLowerCase().includes(iconKey))
    );

    if (!entry) return '';
    if (entry.emoji) return entry.emoji + ' ';

    return `<img src="https://www.skyelighting.com/skyesoft/assets/images/icons/${entry.file}"
                 alt="${entry.alt || ''}"
                 style="width:16px;height:16px;vertical-align:middle;margin-right:4px;">`;
}

// #endregion


// #region FETCH REGISTRIES

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/jurisdictionRegistry.json', { cache: 'no-cache' })
    .then(r => r.json())
    .then(d => jurisdictionRegistry = d)
    .catch(() => jurisdictionRegistry = {});

fetch('https://www.skyelighting.com/skyesoft/data/runtimeEphemeral/permitRegistry.json', { cache: 'no-cache' })
    .then(r => r.json())
    .then(d => permitRegistryMeta = d.meta || null)
    .catch(() => permitRegistryMeta = null);

fetch('https://www.skyelighting.com/skyesoft/data/authoritative/iconMap.json', { cache: 'no-cache' })
    .then(r => r.json())
    .then(d => iconMap = d.icons || {})
    .catch(() => iconMap = {});

// #endregion


// #region CARD FACTORIES

function createActivePermitsCard() {
    const root = document.createElement('section');
    root.className = 'card card-active-permits';
    root.innerHTML = `
        <div class="cardHeader"><h2>📋 Active Permits</h2></div>
        <div class="cardBody">
            <div class="cardContent" id="permitScrollWrap">
                <table class="permit-table">
                    <thead>
                        <tr>
                            <th>WO</th>
                            <th>Customer</th>
                            <th>Jobsite</th>
                            <th>Jurisdiction</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="permitTableBody">
                        <tr><td colspan="5">Loading permits…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="cardFooter" id="permitFooter">Loading…</div>
    `;

    return {
        id: 'activePermits',
        durationMs: 30000,
        root,
        scrollWrap: root.querySelector('#permitScrollWrap'),
        tableBody: root.querySelector('#permitTableBody'),
        footer: root.querySelector('#permitFooter')
    };
}

function createGenericCard({ icon, title, body, footer }) {
    const root = document.createElement('section');
    root.className = 'card boardCard';
    root.innerHTML = `
        <div class="cardHeader">
            <span class="cardIcon">${icon}</span>
            <span class="cardTitle">${title}</span>
        </div>
        <div class="cardBody">${body}</div>
        <div class="cardFooter">${footer}</div>
    `;
    return root;
}

// #endregion


// #region BOARD DEFINITION

const BOARD_SEQUENCE = [
    createActivePermitsCard(),
    {
        id: 'highlights',
        durationMs: 30000,
        root: createGenericCard({
            icon: '🌅',
            title: 'Today’s Highlights',
            body: `
                <div class="entry">📅 Today’s date</div>
                <div class="entry">🌄 Sunrise / 🌇 Sunset</div>
                <div class="entry">🎉 Next Holiday</div>
            `,
            footer: 'Live date and solar info'
        })
    },
    {
        id: 'kpi',
        durationMs: 30000,
        root: createGenericCard({
            icon: '📈',
            title: 'KPI Dashboard',
            body: `<div class="entry">🚧 KPI dashboard coming soon.</div>`,
            footer: 'Metrics update daily'
        })
    },
    {
        id: 'announcements',
        durationMs: 30000,
        root: createGenericCard({
            icon: '📢',
            title: 'Announcements',
            body: `<div class="entry">No announcements posted.</div>`,
            footer: 'Company-wide notices'
        })
    }
];

// #endregion


// #region BOARD CONTROLLER

window.SkyOfficeBoard = {
    index: 0,
    host: null,
    activeCard: null,
    lastPermitSignature: null,

    start() {
        this.host = document.getElementById('boardCardHost');
        this.showNext();
    },

    showNext() {
        if (!this.host) return;

        this.host.innerHTML = '';
        const card = BOARD_SEQUENCE[this.index];
        this.activeCard = card;
        this.host.appendChild(card.root);

        this.index = (this.index + 1) % BOARD_SEQUENCE.length;
        setTimeout(() => this.showNext(), card.durationMs);
    },

    updatePermitTable(activePermits = []) {
        const card = BOARD_SEQUENCE.find(c => c.id === 'activePermits');
        if (!card) return;

        const body = card.tableBody;
        const footer = card.footer;

        const signature = activePermits.map(p => `${p.wo}|${p.status}`).join('|');
        if (signature === this.lastPermitSignature) return;
        this.lastPermitSignature = signature;

        body.innerHTML = '';
        activePermits.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${p.wo}</td>
                <td>${p.customer}</td>
                <td>${p.jobsite}</td>
                <td>${resolveJurisdictionLabel(p.jurisdiction)}</td>
                <td>${getStatusIcon(p.status)}${formatStatus(p.status)}</td>
            `;
            body.appendChild(tr);
        });

        footer.innerHTML = `
            <img src="https://www.skyelighting.com/skyesoft/assets/images/live-streaming.gif"
                 style="width:24px;vertical-align:middle;margin-right:8px;">
            ${activePermits.length} active permits
            ${permitRegistryMeta?.updatedOn ? ` • Updated ${formatTimestamp(permitRegistryMeta.updatedOn)}` : ''}
        `;
    },

    onSSE(payload) {
        this.updatePermitTable(payload.activePermits || []);
    }
};

// #endregion


// #region PAGE REGISTRATION

window.SkyeApp.registerPage('officeBoard', window.SkyOfficeBoard);
document.addEventListener('DOMContentLoaded', () => window.SkyOfficeBoard.start());

// #endregion
