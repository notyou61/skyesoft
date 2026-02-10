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

    /* Status */
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    /* Edit */
    if (presentation?.nodeTypes?.phase?.editable) {
        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'edit-link';
        edit.textContent = 'Edit';
        edit.addEventListener('click', e => {
            e.stopPropagation();
            // modal hook later
        });
        header.appendChild(edit);
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
            li.textContent = task.label || '(No title)';
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

    // Ensure we can resolve both numeric and string keys
    var key = (iconId === 0 || iconId) ? String(iconId) : null;
    var glyph = (key && iconMap && iconMap[key]) ? iconMap[key] : null;

    // 🔎 DEBUG: icon resolution trace
    console.log('ICON RESOLVE', {
        iconId,
        key,
        glyph,
        iconMap
    });

    var span = document.createElement('span');
    span.className = 'node-icon';

    // HARD fallback so missing data is visible
    span.textContent = glyph ? glyph : '⬚';

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