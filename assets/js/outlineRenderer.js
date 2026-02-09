/* =====================================================================
 *  Skyesoft — outlineRenderer.js
 *  Tier-3 UI Rendering Layer
 *
 *  Role:
 *   • Render an adapted outline model into the DOM
 *
 *  Guarantees:
 *   • Domain-agnostic
 *   • Stateless
 *   • Pure rendering (no data mutation)
 *   • No authoritative assumptions
 *
 *  Inputs:
 *   • Adapted outline (from domainAdapter)
 *   • Presentation registry
 *   • Icon map
 *
 *  This is the ONLY place where:
 *   • icons appear
 *   • hierarchy appears
 *   • collapsibility appears
 * ===================================================================== */

/* #region PUBLIC API */

/**
 * Render a semantic outline into a container.
 *
 * @param {HTMLElement} container
 * @param {{ title: string, nodes: Array }} outline
 * @param {object} presentationRegistry
 * @param {object} iconMap
 */
export function renderOutline(container, outline, presentationRegistry, iconMap) {
    if (!container || !outline) return;

    container.innerHTML = '';

    if (outline.title) {
        const titleEl = document.createElement('h2');
        titleEl.textContent = outline.title;
        container.appendChild(titleEl);
    }

    const domainConfig =
        presentationRegistry?.domains?.roadmap ?? null;

    outline.nodes.forEach(node => {
        container.appendChild(
            renderNode(node, domainConfig, iconMap)
        );
    });
}

/* #endregion */


/* #region NODE RENDERING */

function renderNode(node, domainConfig, iconMap, depth = 0) {
    const nodeTypeConfig =
        domainConfig?.nodeTypes?.[node.type] ?? {};

    const resolved = {
        collapsible: nodeTypeConfig.collapsible ?? false,
        defaultExpanded: nodeTypeConfig.defaultExpanded ?? true,
        editable: nodeTypeConfig.editable ?? false
    };

    const wrapper = document.createElement('div');
    wrapper.className = `outline-node depth-${depth}`;

    /* Header row */
    const header = document.createElement('div');
    header.className = 'outline-header';

    /* Icon */
    if (node.iconId != null && iconMap[node.iconId]) {
        const iconSpan = document.createElement('span');
        iconSpan.className = 'outline-icon';
        iconSpan.textContent = iconMap[node.iconId];
        header.appendChild(iconSpan);
    }

    /* Label */
    const labelSpan = document.createElement('span');
    labelSpan.className = 'outline-label';
    labelSpan.textContent = node.label;
    header.appendChild(labelSpan);

    /* Status */
    if (node.status) {
        const statusSpan = document.createElement('span');
        statusSpan.className = `outline-status ${node.status}`;
        statusSpan.textContent = formatStatus(node.status);
        header.appendChild(statusSpan);
    }

    /* Edit */
    if (resolved.editable) {
        const edit = document.createElement('a');
        edit.href = '#';
        edit.className = 'edit-link';
        edit.textContent = 'Edit';
        edit.onclick = e => {
            e.preventDefault();
            e.stopPropagation();
            window.editPhase?.(e, node);
        };
        header.appendChild(edit);
    }

    wrapper.appendChild(header);

    /* Children */
    if (Array.isArray(node.children) && node.children.length) {
        const childrenContainer = document.createElement('div');
        childrenContainer.className = 'outline-children';
        childrenContainer.style.display =
            resolved.defaultExpanded ? 'block' : 'none';

        node.children.forEach(child => {
            childrenContainer.appendChild(
                renderNode(child, domainConfig, iconMap, depth + 1)
            );
        });

        if (resolved.collapsible) {
            header.style.cursor = 'pointer';
            header.onclick = () => {
                childrenContainer.style.display =
                    childrenContainer.style.display === 'none'
                        ? 'block'
                        : 'none';
            };
        }

        wrapper.appendChild(childrenContainer);
    }

    return wrapper;
}

/* #endregion */


/* #region HELPERS */

function formatStatus(status) {
    switch (status) {
        case 'complete': return '✓ Complete';
        case 'in-progress': return '⏳ In Progress';
        case 'pending': return 'Pending';
        default: return status;
    }
}

/* #endregion */
