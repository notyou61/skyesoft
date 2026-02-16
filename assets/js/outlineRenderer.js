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
    wrapper.style.paddingLeft = `${depth * 18}px`; // INDENTATION

    const header = document.createElement('div');
    header.className = 'outlineHeader';

    const nodeType   = node.type;
    const typeConfig = domainConfig?.nodeTypes?.[nodeType] || {};
    const caps       = domainConfig?.capabilities || {};

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;

    /* CARET */
    const caret = document.createElement('span');
    caret.className = 'node-caret';
    caret.textContent = hasChildren ? '▶' : '';
    header.appendChild(caret);

    /* ICON */
    const icon = renderIcon(node.iconId, iconMap);
    icon.classList.add('node-icon');
    header.appendChild(icon);

    /* TITLE */
    const title = document.createElement('span');
    title.className = 'node-title';
    title.textContent = node.label || node.title || '(Untitled)';
    header.appendChild(title);

    /* ---------------------------
       CRUD LINKS (Tight)
    --------------------------- */

    if (typeConfig.editable) {

        if (caps.read) {
            const read = document.createElement('span');
            read.className = 'node-action node-read';
            read.textContent = 'Read';
            read.addEventListener('click', e => {
                e.stopPropagation();
                console.log('Read:', node.id);
            });
            header.appendChild(read);
        }

        if (caps.update) {
            const update = document.createElement('span');
            update.className = 'node-action node-update';
            update.textContent = 'Update';
            update.addEventListener('click', e => {
                e.stopPropagation();
                header.dispatchEvent(new CustomEvent('outline:update', {
                    bubbles: true,
                    detail: { nodeId: node.id, nodeType: node.type }
                }));
            });
            header.appendChild(update);
        }

        if (caps.delete && typeConfig.deletable) {
            const del = document.createElement('span');
            del.className = 'node-action node-delete';
            del.textContent = 'Delete';
            del.addEventListener('click', e => {
                e.stopPropagation();
                if (confirm('Delete this item?')) {
                    header.dispatchEvent(new CustomEvent('outline:delete', {
                        bubbles: true,
                        detail: { nodeId: node.id, nodeType: node.type }
                    }));
                }
            });
            header.appendChild(del);
        }
    }

    wrapper.appendChild(header);

    /* ---------------------------
       CHILDREN
    --------------------------- */

    if (hasChildren) {

        const childrenContainer = document.createElement('div');
        childrenContainer.style.display = 'none';

        node.children.forEach(child => {
            childrenContainer.appendChild(
                renderNode(child, domainConfig, iconMap, depth + 1)
            );
        });

        wrapper.appendChild(childrenContainer);

        let expanded = false;

        caret.addEventListener('click', e => {
            e.stopPropagation();
            expanded = !expanded;
            caret.textContent = expanded ? '▼' : '▶';
            childrenContainer.style.display = expanded ? 'block' : 'none';
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