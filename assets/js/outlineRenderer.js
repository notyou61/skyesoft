/* =====================================================================
 *  Skyesoft — outlineRenderer.js
 *  Tier-2 UI Infrastructure
 *
 *  Role:
 *   • Render outline nodes produced by domainAdapter
 *   • Apply presentation + iconMap
 *   • No domain knowledge
 * ===================================================================== */

export function renderOutline(container, adapted, presentation, iconMap) {
    if (!container || !adapted) return;

    container.innerHTML = '';
    container.classList.add('outline');

    adapted.nodes.forEach(node => {
        container.appendChild(renderPhase(node, presentation, iconMap));
    });
}

function renderPhase(node, presentation, iconMap) {
    const wrapper = document.createElement('div');
    wrapper.className = 'outline-phase';

    /* ---------- Header ---------- */

    const header = document.createElement('div');
    header.className = 'phase-header';

    let expanded = false;           // collapsed by default
    let taskList = null;
    let caret = null;

    // Caret (only shown when there are children)
    if (node.children?.length > 0) {
        caret = document.createElement('span');
        caret.className = 'node-caret';
        caret.textContent = '▶';
        header.appendChild(caret);
    }

    // Icon
    header.appendChild(renderIcon(node.iconId, iconMap));

    // Title
    const title = document.createElement('span');
    title.className = 'phase-title';
    title.textContent = node.label || '(Untitled)';
    header.appendChild(title);

    // Status
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    // Edit link (stops propagation so it doesn't toggle collapse)
    if (presentation?.nodeTypes?.phase?.editable) {
        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'edit-link';
        edit.textContent = 'Edit';
        edit.addEventListener('click', e => {
            e.stopPropagation();
            // TODO: later → real edit handler / modal / form
        });
        header.appendChild(edit);
    }

    wrapper.appendChild(header);

    /* ---------- Tasks / Children ---------- */

    if (node.children?.length > 0) {
        taskList = document.createElement('ul');
        taskList.className = 'task-list';
        taskList.style.display = 'none'; // collapsed by default

        node.children.forEach(task => {
            const li = document.createElement('li');
            li.className = 'task-item';

            li.appendChild(renderIcon(task.iconId, iconMap));

            const label = document.createElement('span');
            label.textContent = task.label || '(No title)';
            li.appendChild(label);

            taskList.appendChild(li);
        });

        wrapper.appendChild(taskList);

        /* ---------- Toggle Logic ---------- */

        header.style.cursor = 'pointer';
        header.addEventListener('click', () => {
            expanded = !expanded;

            if (caret) {
                caret.textContent = expanded ? '▼' : '▶';
            }

            taskList.style.display = expanded ? 'block' : 'none';

            // Optional: helps with CSS transitions / styling
            wrapper.classList.toggle('expanded', expanded);
        });
    }

    return wrapper;
}

/* ---------- Utilities ---------- */

function renderIcon(iconId, iconMap) {
    const span = document.createElement('span');
    span.className = 'node-icon';
    span.textContent = iconMap?.[iconId] ?? '•';
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