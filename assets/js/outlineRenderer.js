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

    // Icon
    header.appendChild(renderIcon(node.iconId, iconMap));

    // Title
    const title = document.createElement('span');
    title.className = 'phase-title';
    title.textContent = node.label;
    header.appendChild(title);

    // Status
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status-badge ${node.status}`;
        status.textContent = statusLabel(node.status);
        header.appendChild(status);
    }

    // Edit
    if (presentation?.nodeTypes?.phase?.editable) {
        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'edit-link';
        edit.textContent = 'Edit';
        header.appendChild(edit);
    }

    wrapper.appendChild(header);

    /* ---------- Tasks ---------- */

    if (node.children?.length) {
        const list = document.createElement('ul');
        list.className = 'task-list';

        node.children.forEach(task => {
            const li = document.createElement('li');
            li.className = 'task-item';

            li.appendChild(renderIcon(task.iconId, iconMap));

            const label = document.createElement('span');
            label.textContent = task.label;
            li.appendChild(label);

            list.appendChild(li);
        });

        wrapper.appendChild(list);
    }

    return wrapper;
}

function renderIcon(iconId, iconMap) {
    const span = document.createElement('span');
    span.className = 'node-icon';
    span.textContent = iconMap?.[iconId] ?? '•';
    return span;
}

function statusLabel(status) {
    switch (status) {
        case 'complete': return '✓ Complete';
        case 'in-progress': return '⏳ In Progress';
        default: return 'Pending';
    }
}
