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

    container.innerHTML = '';
    container.classList.add('outline');

    const nodes = Array.isArray(adapted.nodes) ? adapted.nodes : [];

    nodes.forEach(node => {
        container.appendChild(
            renderNode(node, domainConfig, iconMap)
        );
    });
}
/* #endregion */

/* #region Node Rendering */
function renderNode(node, domainConfig, iconMap) {

    const el = document.createElement('div');
    el.className = 'outline-phase';

    const header = document.createElement('div');
    header.className = 'phase-header';

    /* ---------- Caret ---------- */

    const caret = document.createElement('span');
    caret.className = 'node-caret';

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    caret.textContent = hasChildren ? '▶' : '';

    header.appendChild(caret);

    /* ---------- Icon ---------- */

    const icon = renderIcon(node.iconId, iconMap);
    icon.classList.add('node-icon');
    header.appendChild(icon);

    /* ---------- Title ---------- */

    const title = document.createElement('span');
    title.className = 'phase-title';
    title.textContent = node.label || node.title || '(Untitled)';
    header.appendChild(title);

    /* ---------- CRUD ---------- */

    const capabilities = domainConfig?.capabilities || {};

    // READ (PDF link)
    if (capabilities.read && node.pdfPath) {
        const read = document.createElement('a');
        read.href = node.pdfPath;
        read.target = '_blank';
        read.className = 'node-action node-read';
        read.textContent = 'Read';
        header.appendChild(read);
    }

    // UPDATE
    if (capabilities.update) {
        const update = document.createElement('a');
        update.href = '#';
        update.className = 'node-action node-update';
        update.textContent = 'Update';

        update.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();

            header.dispatchEvent(new CustomEvent('outline:update', {
                bubbles: true,
                detail: {
                    nodeId: node.id,
                    nodeType: node.type
                }
            }));
        });

        header.appendChild(update);
    }

    // DELETE
    if (capabilities.delete) {
        const del = document.createElement('a');
        del.href = '#';
        del.className = 'node-action node-delete';
        del.textContent = 'Delete';

        del.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();

            if (!confirm('Are you sure you want to delete this item?')) {
                return;
            }

            header.dispatchEvent(new CustomEvent('outline:delete', {
                bubbles: true,
                detail: {
                    nodeId: node.id,
                    nodeType: node.type
                }
            }));
        });

        header.appendChild(del);
    }

    /* ---------- Status Badge ---------- */

    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    el.appendChild(header);

    /* ---------- Children ---------- */

    if (hasChildren) {

        const childContainer = document.createElement('div');
        childContainer.className = 'outlineChildren';
        childContainer.style.display = 'none';

        node.children.forEach(child => {
            childContainer.appendChild(
                renderNode(child, domainConfig, iconMap)
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

/* #region Utilities */
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

function statusLabel(status) {
    switch (status?.toLowerCase()) {
        case 'complete':    return '✓ Complete';
        case 'in-progress': return '⏳ In Progress';
        case 'blocked':     return '⚠ Blocked';
        case 'failed':      return '✗ Failed';
        default:            return 'Pending';
    }
}
/* #endregion */