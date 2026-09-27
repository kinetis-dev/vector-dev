import type { VectorField, VectorResource, VectorSchema } from '#vector/app/types/vector'

/** A descriptor field as the server publishes it, with the defaults a plain text column has. */
export function field(overrides: Partial<VectorField> & Pick<VectorField, 'name'>): VectorField {
    return {
        type: 'string',
        label: overrides.name.charAt(0).toUpperCase() + overrides.name.slice(1),
        sortable: false,
        searchable: false,
        filterable: false,
        readonly: false,
        help: null,
        component: null,
        views: ['index', 'show', 'create', 'update'],
        rules: [],
        props: [],
        summary: null,
        default: null,
        hasDefault: false,
        operators: [],
        nullable: true,
        ...overrides,
    }
}

/** A rule as the descriptor names it: the framework constraint's class and its arguments. */
export function rule(name: string, ...args: unknown[]) {
    return { constraint: `Kinetis\\Validation\\Constraints\\${name}`, args }
}

/** A resource descriptor: `tags` with an id, a required name and a colour unless fields are given. */
export function resource(overrides: Partial<VectorResource> = {}): VectorResource {
    const slug = overrides.slug ?? 'tags'

    return {
        slug,
        label: 'Tags',
        labelSingular: 'Tag',
        group: 'Catalog',
        icon: 'mdi-tag-outline',
        primaryKey: { field: 'id', type: 'int' },
        titleField: 'name',
        version: null,
        fields: [
            field({ name: 'id', type: 'id', label: 'ID', readonly: true, nullable: false }),
            field({ name: 'name', nullable: false, rules: [rule('NotBlank'), rule('MaxLength', 60)] }),
            field({ name: 'color', type: 'color', nullable: false }),
        ],
        table: {
            columns: ['id', 'name', 'color'],
            defaultSort: [{ field: 'id', order: 'asc' }],
            perPage: 25,
            perPageOptions: [10, 25, 50],
            maxPerPage: 100,
            search: ['name'],
            groupable: [],
            summaries: [],
            exportLimit: 10000,
            bulkLimit: 1000,
        },
        show: { fields: ['id', 'name', 'color'] },
        softDelete: null,
        actions: [],
        widgets: [],
        permissions: { viewAny: true, create: true, update: true, delete: true, export: true },
        ...overrides,
    }
}

/** A schema holding the given resources, one menu group per resource group. */
export function schema(resources: VectorResource[], overrides: Partial<VectorSchema> = {}): VectorSchema {
    const groups = new Map<string, string[]>()
    for (const r of resources) groups.set(r.group ?? 'Resources', [...(groups.get(r.group ?? 'Resources') ?? []), r.slug])

    return {
        version: 1,
        resources: Object.fromEntries(resources.map(r => [r.slug, r])),
        menu: [...groups.entries()].map(([label, slugs]) => ({ label, resources: slugs })),
        settings: [],
        dashboards: [],
        capabilities: { impersonation: false, queue: false },
        branding: null,
        ...overrides,
    }
}
