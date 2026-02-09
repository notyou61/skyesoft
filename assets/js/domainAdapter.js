/* =====================================================================
 *  Skyesoft — domainAdapter.js
 *  Tier-2 UI Infrastructure
 *
 *  Role:
 *   • Adapt authoritative streamed domain data into
 *     a UI-renderable outline model
 *
 *  Guarantees:
 *   • Domain-agnostic
 *   • Stateless
 *   • No rendering
 *   • No mutation
 *   • No persistence
 *
 *  Consumers:
 *   • Command Interface
 *   • Any future streamed list surface
 * ===================================================================== */

/* #region DOMAIN SCHEMA REGISTRY */

/**
 * Declarative schema registry for streamed list domains.
 * Each schema provides mapping instructions only.
 *
 * Icon policy:
 *  • Adapter may emit iconId values (numeric) as semantic hints
 *  • Rendering resolves iconId → glyph via iconMap.json
 *  • No styling, no markup, no emoji hardcoding here
 */
const streamedDomainSchemas = {

    roadmap: {

        // Domain title
        title: (domain) =>
            domain?.meta?.title ?? 'Roadmap',

        // Root collection
        root: (domain) =>
            domain?.phases ?? [],

        // Node type mapping (stable + scalable)
        nodeTypes: {
            phase: {
                type: 'phase',
                iconId: 20 // Roadmap / list domain icon (resolved via iconMap)
            },
            task: {
                type: 'task',
                iconId: 7 // Task / bullet icon (resolved via iconMap)
            }
        },

        // Phase → outline node
        mapNode: (phase, schema) => ({

            id: phase.id,
            type: schema.nodeTypes.phase.type,
            iconId: schema.nodeTypes.phase.iconId,

            label: phase.name,
            status: phase.status,

            // Children (tasks)
            children: (phase.tasks ?? []).map((task, idx) => ({

                id: `${phase.id}:task:${idx}`,
                type: schema.nodeTypes.task.type,
                iconId: schema.nodeTypes.task.iconId,

                label: task
            }))
        })
    }

    // 🔒 Future domains register here without touching adapter logic.
};

/* #endregion */


/* #region DOMAIN ADAPTER */

/**
 * Adapts a streamed domain payload into a universal outline model.
 *
 * @param {string} domainKey
 * @param {object} domainData
 * @returns {{ title: string, nodes: Array } | null}
 */
export function adaptStreamedDomain(domainKey, domainData) {

    const schema = streamedDomainSchemas[domainKey];
    if (!schema || !domainData) return null;

    const rootItems = schema.root(domainData);
    if (!Array.isArray(rootItems)) return null;

    // Adapt
    return {
        title: schema.title(domainData),
        nodes: rootItems.map(item => schema.mapNode(item, schema))
    };
}

/* #endregion */


/* #region SCHEMA REGISTRY ACCESS */

/**
 * Introspection helper (optional, UI-safe).
 * Allows the UI to know which domains are outline-renderable.
 */
export function getAvailableStreamedDomains() {
    return Object.keys(streamedDomainSchemas);
}

/* #endregion */