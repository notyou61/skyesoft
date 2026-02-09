/* =====================================================================
 *  Skyesoft — outlineRenderer.js
 *  Tier-2 UI Infrastructure
 *
 *  Role:
 *   • Render an adapted outline model to the DOM
 *
 *  Guarantees:
 *   • Domain-agnostic
 *   • Stateless
 *   • No data mutation
 *   • Presentation-registry governed
 * ===================================================================== */

export function renderOutline(
    container,
    adaptedDomain,
    presentationRegistry,
    iconMap
) {
    if (!container || !adaptedDomain) return;

    container.innerHTML = '';

    const domainKey = adaptedDomain.domainKey ?? 'roadmap';
    const domainPresentation = presentationRegistry.domains[domainKey];

    adaptedDomain.nodes.forEach(node => {
        container.appendChild(
            renderNode(node, domainPresentation, iconMap)
        );
    });
}

/* ------------------------------------------------------------------ */

function renderNode(node, presentation, iconMap) {

    const nodeType = node.type;
    const rules = resolvePresentation(presentation, nodeType);

    const wrapper = document.createElement('div');
    wrapper.className = `outline-node ${nodeType}`;

    /* Header */
    const header = document.createElement('div');
    header.className = 'outline-header';

    /* Icon */
    if (node.iconId && iconMap[node.iconId]) {
        const icon = document.createElement('span');
        icon.className = 'outline-icon';
        icon.textContent = iconMap[node.iconId];
        header.appendChild(icon);
    }

    /* Label */
    const label = document.createElement('span');
    label.className = 'outline-label';
    label.textContent = node.label;
    header.appendChild(label);

    /* Status */
    if (node.status) {
        const status = document.createElement('span');
        status.className = `status ${node.status}`;
        status.textContent =
            node.status === 'complete' ? '✓ Complete' :
            node.status === 'in-progress' ? '⏳ In Progress' :
            'Pending';
        header.appendChild(status);
    }

    /* Edit */
    if (rules.editable && nodeType === 'phase') {
        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'edit-link';
        edit.textContent = 'Edit';
        edit.onclick = e => window.editPhase(e, node);
        header.appendChild(edit);
    }

    wrapper.appendChild(header);

    /* Children */
    if (node.children?.length) {
        const list = document.createElement('ul');
        list.className = 'outline-children';
        list.style.display = rules.defaultExpanded ? 'block' : 'none';

        node.children.forEach(child => {
            const li = document.createElement('li');
            li.className = 'outline-child';

            if (child.iconId && iconMap[child.iconId]) {
                const icon = document.createElement('span');
                icon.className = 'outline-icon';
                icon.textContent = iconMap[child.iconId];
                li.appendChild(icon);
            }

            const text = document.createElement('span');
            text.textContent = child.label;
            li.appendChild(text);

            list.appendChild(li);
        });

        if (rules.collapsible) {
            header.onclick = () => {
                list.style.display =
                    list.style.display === 'block' ? 'none' : 'block';
            };
        }

        wrapper.appendChild(list);
    }

    return wrapper;
}

/* ------------------------------------------------------------------ */

function resolvePresentation(domainConfig, nodeType) {
    return {
        ...domainConfig.defaults,
        ...domainConfig.nodeTypes[nodeType]
    };
}
