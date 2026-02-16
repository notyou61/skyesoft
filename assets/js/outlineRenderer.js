/* =====================================================================
 *  Skyesoft — outlineRenderer.js
 *  Tier-2 UI Infrastructure
 *
 *  Role:
 *   • Render outline nodes produced by domainAdapter
 *   • Apply presentation + iconMap
 *   • No domain knowledge
 * ===================================================================== */

/* #region Public API */
export function renderOutline(container, adapted, domainConfig, iconMap) {
    if (!container || !adapted) return;

    // Close any open kebab panels before re-render
    document.querySelectorAll('.node-actionsWrap.open')
        .forEach(wrap => {
            wrap.classList.remove('open');
            const p = wrap.querySelector('.node-actionsPanel');
            if (p) p.hidden = true;
        });

    container.innerHTML = '';
    container.classList.add('outline');

    const nodes = Array.isArray(adapted.nodes) ? adapted.nodes : [];

    nodes.forEach(node => {
        container.appendChild(
            renderNode(node, domainConfig, iconMap, 0) // depth = 0
        );
    });
}
/* #endregion */

/* #region Node Rendering */
function renderNode(node, domainConfig, iconMap, depth = 0) {
    const el = document.createElement('div');
    el.className = 'outline-phase';

    const header = document.createElement('div');
    header.className = 'phase-header';

    // ── Caret ────────────────────────────────────────────────
    const caret = document.createElement('span');
    caret.className = 'node-caret';

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    caret.textContent = hasChildren ? '▶' : '';

    header.appendChild(caret);

    // ── Icon ─────────────────────────────────────────────────
    const icon = renderIcon(node.iconId, iconMap);
    icon.classList.add('node-icon');
    header.appendChild(icon);

    // ── Title ────────────────────────────────────────────────
    const title = document.createElement('span');
    title.className = 'phase-title';
    title.textContent = node.label || node.title || '(Untitled)';
    header.appendChild(title);

    // ── Status Badge ─────────────────────────────────────────
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    // ── Actions (Kebab menu) – only on primary nodes ─────────
    const isPrimaryNode = depth === 0;
    const capabilities = domainConfig?.capabilities || {};

    if (isPrimaryNode && (capabilities.read || capabilities.update || capabilities.delete)) {
        const actionsWrap = document.createElement('span');
        actionsWrap.className = 'node-actionsWrap';

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'node-actionsToggle';
        toggle.textContent = '⋯';
        toggle.setAttribute('aria-label', 'Node actions');

        const panel = document.createElement('span');
        panel.className = 'node-actionsPanel';
        panel.hidden = true;

        // Toggle behavior
        toggle.addEventListener('click', e => {
            //
            console.log('Kebab clicked');
            // Prevent Default
            e.preventDefault();
            e.stopPropagation();

            const isOpen = !panel.hidden;

            // Close ALL open panels first
            document.querySelectorAll('.node-actionsWrap.open')
                .forEach(wrap => {
                    wrap.classList.remove('open');
                    const p = wrap.querySelector('.node-actionsPanel');
                    if (p) p.hidden = true;
                });

            // Re-open this one if it was previously closed
            if (!isOpen) {
                panel.hidden = false;
                actionsWrap.classList.add('open');
            }
        });


        // READ link
        if (capabilities.read && node.pdfPath) {
            const read = document.createElement('a');
            read.href = node.pdfPath;
            read.target = '_blank';
            read.rel = 'noopener noreferrer';           // security best practice
            read.className = 'node-action node-read';
            read.textContent = 'Read';
            panel.appendChild(read);
        }

        // UPDATE link
        if (capabilities.update) {
            const update = document.createElement('a');
            update.href = '#';
            update.className = 'node-action node-update';
            update.textContent = 'Update';
            panel.appendChild(update);
        }

        // DELETE link
        if (capabilities.delete) {
            const del = document.createElement('a');
            del.href = '#';
            del.className = 'node-action node-delete';
            del.textContent = 'Delete';
            panel.appendChild(del);
        }

        actionsWrap.appendChild(toggle);
        actionsWrap.appendChild(panel);
        header.appendChild(actionsWrap);
    }

    el.appendChild(header);

    // ── Children ─────────────────────────────────────────────
    if (hasChildren) {
        const childContainer = document.createElement('div');
        childContainer.className = 'outlineChildren';
        childContainer.style.display = 'none';

        node.children.forEach(child => {
            childContainer.appendChild(
                renderNode(child, domainConfig, iconMap, depth + 1)
            );
        });

        el.appendChild(childContainer);

        let expanded = false;

        caret.addEventListener('click', e => {
            e.stopPropagation();
            expanded = !expanded;

            caret.textContent = expanded ? '▼' : '▶';
            childContainer.style.display = expanded ? 'block' : 'none';
            el.classList.toggle('expanded', expanded);
        });
    } else {
        caret.style.visibility = 'hidden';
    }

    return el;
}
/* #endregion */

/* #region Render Icon */
function renderIcon(iconId, iconMap) {

    var key = (iconId === 0 || iconId) ? String(iconId) : null;

    var glyph = (key && iconMap?.icons && iconMap.icons[key]?.emoji)
        ? iconMap.icons[key].emoji
        : null;

    var span = document.createElement('span');
    span.className = 'node-icon';
    span.textContent = glyph || '';

    return span;
}
/* #endregion */

/* #region Status Label */
function statusLabel(status) {
    switch (status?.toLowerCase()) {
        case 'complete':    return '✓ Complete';
        case 'in-progress': return '⏳ In Progress';
        case 'blocked':     return '⚠ Blocked';
        case 'failed':      return '✗ Failed';
        default:            return '🕒Pending';
    }
}
/* #endregion */