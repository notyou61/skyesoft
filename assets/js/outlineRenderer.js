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
export function renderOutline(container, adapted, presentation, iconMap) {
    if (!container || !adapted) return;

    container.innerHTML = '';
    container.classList.add('outline');

    adapted.nodes.forEach(node => {
        container.appendChild(renderPhase(node, presentation, iconMap));
    });
}
/* #endregion */

/* #region Phase Rendering */
function renderPhase(node, presentation, iconMap) {
    const wrapper = document.createElement('div');
    wrapper.className = 'outline-phase';

    /* ---------- Header ---------- */
    const header = document.createElement('div');
    header.className = 'phase-header';

    let expanded = false;
    let taskList = null;

    /* Caret — ALWAYS rendered (interactive only if children exist) */
    const caret = document.createElement('span');
    caret.className = 'node-caret';

    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    caret.textContent = hasChildren ? '▶' : ''; // visual-only when empty

    header.appendChild(caret);

    /* Icon — ALWAYS after caret (semantic marker, never hardcoded) */
    const icon = renderIcon(node.iconId, iconMap);
    icon.classList.add('phase-icon');
    header.appendChild(icon);

    /* Title */
    const title = document.createElement('span');
    title.className = 'phase-title';
    title.textContent = node.label || '(Untitled)';
    header.appendChild(title);

    /* CRUD Links — minimal, text-only */
    if (presentation?.nodeTypes?.phase?.editable) {

        // UPDATE
        const update = document.createElement('a');
        update.href = '#';
        update.className = 'node-action node-update';
        update.style.marginLeft = '12px';
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

        // DELETE
        const del = document.createElement('a');
        del.href = '#';
        del.className = 'node-action node-delete';
        del.style.marginLeft = '8px';
        del.textContent = 'Delete';

        del.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();

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

    /* Status (render last) */
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    wrapper.appendChild(header);


    /* ---------- Phase Children (Tasks) ---------- */
    if (node.children?.length > 0) {

        taskList = document.createElement('ul');
        taskList.className = 'task-list';
        taskList.style.display = 'none';

        node.children.forEach(task => {

            const li = document.createElement('li');
            li.className = 'task-item';

            /* Task Icon */
            const taskIcon = renderIcon(task.iconId, iconMap);
            taskIcon.classList.add('task-icon');
            li.appendChild(taskIcon);

            /* Task Label */
            const label = document.createElement('span');
            label.className = 'task-label';
            label.textContent = task.label || '(No title)';
            li.appendChild(label);

            taskList.appendChild(li);
        });

        wrapper.appendChild(taskList);

        caret.addEventListener('click', e => {
            e.stopPropagation();

            expanded = !expanded;

            caret.textContent = expanded ? '▼' : '▶';
            taskList.style.display = expanded ? 'block' : 'none';
            wrapper.classList.toggle('expanded', expanded);
        });

    } else {
        // Caret
        caret.style.visibility = 'hidden';
        // No children → no toggle behavior
        header.style.cursor = 'default';
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