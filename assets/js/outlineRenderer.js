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

    const wrapper = document.createElement('div');
    wrapper.className = 'outlineNode';

    const header = document.createElement('div');
    header.className = 'outlineHeader';

    const nodeType = node.type;
    const typeConfig = domainConfig?.nodeTypes?.[nodeType] || {};

    /* -----------------------------
       Caret
    ----------------------------- */

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;

    const caret = document.createElement('span');
    caret.className = 'node-caret';
    caret.textContent = hasChildren ? '▶' : '';
    header.appendChild(caret);

    /* -----------------------------
       Icon
    ----------------------------- */

    const icon = renderIcon(node.iconId, iconMap);
    header.appendChild(icon);

    /* -----------------------------
       Title
    ----------------------------- */

    const title = document.createElement('span');
    title.className = 'node-title';
    title.textContent = node.label || node.title || '(Untitled)';
    header.appendChild(title);

    /* -----------------------------
       CRUD Actions (Registry Driven)
    ----------------------------- */

    if (typeConfig.editable) {

        // READ (PDF link)
        if (domainConfig.capabilities?.read) {
            const read = document.createElement('a');
            read.href = '#';
            read.className = 'node-action node-read';
            read.textContent = 'Read';

            read.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                console.log('[Outline] Read:', node.id);
                // later: open PDF link
            });

            header.appendChild(read);
        }

        // UPDATE
        if (domainConfig.capabilities?.update) {
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
        if (domainConfig.capabilities?.delete && typeConfig.deletable) {
            const del = document.createElement('a');
            del.href = '#';
            del.className = 'node-action node-delete';
            del.textContent = 'Delete';

            del.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();

                if (confirm('Are you sure you want to delete this item?')) {
                    header.dispatchEvent(new CustomEvent('outline:delete', {
                        bubbles: true,
                        detail: {
                            nodeId: node.id,
                            nodeType: node.type
                        }
                    }));
                }
            });

            header.appendChild(del);
        }
    }

    wrapper.appendChild(header);

    /* -----------------------------
       Children Rendering
    ----------------------------- */

    if (hasChildren) {

        const childContainer = document.createElement('div');
        childContainer.className = 'outlineChildren';
        childContainer.style.display = 'none';

        node.children.forEach(child => {
            childContainer.appendChild(
                renderNode(child, domainConfig, iconMap)
            );
        });

        wrapper.appendChild(childContainer);

        let expanded = false;

        caret.addEventListener('click', e => {
            e.stopPropagation();
            expanded = !expanded;
            caret.textContent = expanded ? '▼' : '▶';
            childContainer.style.display = expanded ? 'block' : 'none';
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