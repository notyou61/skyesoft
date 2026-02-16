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
function renderNode(node, domainConfig, iconMap, depth = 0) {

    const wrapper = document.createElement('div');
    wrapper.className = 'outlineNode';
    wrapper.style.paddingLeft = `${depth * 18}px`;

    const header = document.createElement('div');
    header.className = 'outlineHeader';

    /* ---------- Caret ---------- */
    const caret = document.createElement('span');
    caret.className = 'node-caret';

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    caret.textContent = hasChildren ? '▶' : '';

    header.appendChild(caret);

    /* ---------- Icon ---------- */
    if (node.iconId) {
        const icon = renderIcon(node.iconId, iconMap);
        icon.classList.add('node-icon');
        header.appendChild(icon);
    }

    /* ---------- Title ---------- */
    const title = document.createElement('span');
    title.className = 'node-title';
    title.textContent = node.label || node.title || '(Untitled)';
    header.appendChild(title);

    /* ---------- CRUD ---------- */
    const canRead   = domainConfig?.capabilities?.read   === true;
    const canUpdate = domainConfig?.capabilities?.update === true;
    const canDelete = domainConfig?.capabilities?.delete === true;

    if (canRead || canUpdate || canDelete) {

        const actions = document.createElement('span');
        actions.className = 'node-actions';

        // READ
        if (canRead && node.pdfPath) {
            const read = document.createElement('a');
            read.href = node.pdfPath;
            read.target = '_blank';
            read.className = 'node-action node-read';
            read.textContent = 'Read';
            read.addEventListener('click', e => e.stopPropagation());
            actions.appendChild(read);
        }

        // UPDATE
        if (canUpdate) {
            const update = document.createElement('span');
            update.className = 'node-action node-update';
            update.textContent = 'Update';

            update.addEventListener('click', e => {
                e.stopPropagation();
                header.dispatchEvent(new CustomEvent('outline:update', {
                    bubbles: true,
                    detail: {
                        nodeId: node.id,
                        nodeType: node.type
                    }
                }));
            });

            actions.appendChild(update);
        }

        // DELETE
        if (canDelete) {
            const del = document.createElement('span');
            del.className = 'node-action node-delete';
            del.textContent = 'Delete';

            del.addEventListener('click', e => {
                e.stopPropagation();

                const confirmed = confirm(
                    `Delete "${node.label || node.title}"?`
                );

                if (!confirmed) return;

                header.dispatchEvent(new CustomEvent('outline:delete', {
                    bubbles: true,
                    detail: {
                        nodeId: node.id,
                        nodeType: node.type
                    }
                }));
            });

            actions.appendChild(del);
        }

        header.appendChild(actions);
    }

    /* ---------- Status ---------- */
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    wrapper.appendChild(header);

    /* ---------- Children ---------- */
    if (hasChildren) {

        const childContainer = document.createElement('div');
        childContainer.className = 'outlineChildren';
        childContainer.style.display = 'none';

        node.children.forEach(child => {
            childContainer.appendChild(
                renderNode(child, domainConfig, iconMap, depth + 1)
            );
        });

        wrapper.appendChild(childContainer);

        caret.addEventListener('click', e => {
            e.stopPropagation();

            const expanded = childContainer.style.display === 'block';
            childContainer.style.display = expanded ? 'none' : 'block';
            caret.textContent = expanded ? '▶' : '▼';
        });

    } else {
        caret.style.visibility = 'hidden';
    }

    return wrapper;
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