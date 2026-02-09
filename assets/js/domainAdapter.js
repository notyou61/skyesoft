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
 */
const streamedDomainSchemas = {

    roadmap: {
        title: (domain) =>
            domain?.meta?.title ?? 'Roadmap',

        root: (domain) =>
            domain?.phases ?? [],

        mapNode: (phase) => ({
            id: phase.id,
            label: phase.name,
            status: phase.status,
            children: (phase.tasks ?? []).map((task, idx) => ({
                id: `${phase.id}:task:${idx}`,
                label: task
            }))
        })
    }

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

    return {
        title: schema.title(domainData),
        nodes: rootItems.map(schema.mapNode)
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
