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
 *
 * Icon policy:
 *  •  Adapter passes through instance-level semantic iconId values
 *  • Rendering resolves iconId → glyph via iconMap.json
 *  • No styling, markup, or emoji hardcoding here
 */
const streamedDomainSchemas = {

    roadmap: {

        /* ---------------------------------
        * Domain metadata
        * --------------------------------- */

        title: (domain) =>
            domain?.summary?.meta?.title ?? 'Roadmap',

        /* ---------------------------------
        * Root selector
        * --------------------------------- */

        root: (domain) =>
            Array.isArray(domain?.summary?.phases)
                ? domain.summary.phases
                : [],

        /* ---------------------------------
        * Mapping: Phase → Outline Node
        * --------------------------------- */

        mapNode: (phase) => {

            if (!phase || !phase.id) return null;

            return {
                id: phase.id,
                type: 'phase',

                iconId: phase.icon != null ? Number(phase.icon) : null,

                label: phase.name ?? '',
                status: phase.status ?? null,

                children: Array.isArray(phase.tasks)
                    ? phase.tasks.map((task, idx) => {

                        if (typeof task === 'string') {
                            return {
                                id: `${phase.id}:task:${idx}`,
                                type: 'task',
                                label: task,
                                iconId: null
                            };
                        }

                        return {
                            id: `${phase.id}:task:${idx}`,
                            type: 'task',
                            label: task.text ?? '',
                            iconId: Number.isInteger(task.icon) ? task.icon : null
                        };

                    })
                    : []
            };
        }
    }

    // 🔒 Future domains (entities, permits, violations, etc.)
    // are registered here without touching adapter logic.
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

    const nodes = rootItems
        .map(item => schema.mapNode(item))
        .filter(Boolean); // defensive: drop invalid nodes

    return {
        domainKey,
        title: schema.title(domainData),
        nodes
    };

}
/* #endregion */

/* #region SCHEMA REGISTRY ACCESS */
/**
 * Introspection helper (UI-safe).
 * Allows the UI to discover which domains are outline-renderable.
 */
export function getAvailableStreamedDomains() {
    return Object.keys(streamedDomainSchemas);
}

/* #endregion */