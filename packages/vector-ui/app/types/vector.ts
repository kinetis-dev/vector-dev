/**
 * The descriptor `GET /vector/schema` publishes, as the layer consumes it.
 * Mirrors packages/vector's ResourceDescriptor::toArray().
 */

export type VectorFieldType =
    | 'id' | 'string' | 'text' | 'markdown' | 'number' | 'money' | 'boolean'
    | 'enum' | 'date' | 'datetime' | 'email' | 'url' | 'password' | 'slug'
    | 'color' | 'json' | 'keyValue' | 'currency' | 'file' | 'image'
    | 'belongsTo' | 'hasOne' | 'hasMany' | 'manyToMany'

export type VectorView = 'index' | 'show' | 'create' | 'update'

export interface VectorEnumOption {
    value: string | number
    label: string
    color: string | null
}

export interface VectorRule {
    constraint: string
    args: unknown[]
}

export interface VectorRelation {
    /** hasOne/hasMany: the target's field that points back at the owner */
    inverse?: string
    kind: 'belongsTo' | 'hasOne' | 'hasMany' | 'manyToMany'
    resource: string
    titleField: string | null
    /** belongsTo: the choices are the target records whose `on` equals this form's `field` */
    dependsOn?: { field: string, on: string, label: string } | null
}

export type VectorOperator = 'eq' | 'ne' | 'lt' | 'lte' | 'gt' | 'gte' | 'in' | 'nin' | 'like' | 'nlike' | 'between' | 'null' | 'nnull'

export interface VectorField {
    name: string
    type: VectorFieldType
    label: string
    sortable: boolean
    searchable: boolean
    filterable: boolean
    readonly: boolean
    /** Editable in place in the list (inlineEditable()). */
    inline?: boolean
    /** hasMany: the owned rows edited inside the parent's form */
    repeater?: { fields: string[], min: number, max: number } | null
    help: string | null
    component: string | null
    views: VectorView[]
    rules: VectorRule[]
    props: Record<string, unknown> | []
    summary: 'sum' | 'avg' | 'min' | 'max' | 'count' | null
    default: unknown
    hasDefault: boolean
    operators: VectorOperator[]
    nullable?: boolean
    valueType?: 'string' | 'int' | 'float' | 'bool' | 'timestamp' | 'date'
    options?: VectorEnumOption[]
    relation?: VectorRelation
    conditions?: VectorFieldConditions
}

/** One comparison against another field of the same form. */
export interface VectorCondition {
    field: string
    op: 'eq' | 'ne' | 'in' | 'nin' | 'null' | 'nnull'
    value: unknown
}

/** Form behaviour that depends on other fields; every condition of a kind must hold. */
export interface VectorFieldConditions {
    visible: VectorCondition[]
    required: VectorCondition[]
    disabled: VectorCondition[]
}

export interface VectorFieldState {
    visible: boolean
    required: boolean
    disabled: boolean
}

export interface VectorAction {
    name: string
    label: string
    icon: string | null
    confirmation: string | null
    destructive: boolean
    standalone: boolean
    showOnIndex: boolean
    showOnDetail: boolean
    fields: VectorField[]
}

export interface VectorActionResponse {
    type: 'message' | 'danger' | 'redirect' | 'url' | 'download' | 'job'
    message: string | null
    url: string | null
    affected: number
    jobId?: number | null
}

export type VectorWidgetType = 'stat' | 'trend' | 'partition' | 'progress' | 'table'

export interface VectorWidget {
    name: string
    label: string
    type: VectorWidgetType
    icon: string | null
    ranges: Record<string, string>
    width: 3 | 4 | 6 | 12
    cacheSeconds: number
}

export interface VectorWidgetData {
    type: VectorWidgetType
    range: string
    cached: boolean
    format?: string
    value?: number | null
    previous?: number | null
    caption?: string | null
    points?: { x: string, y: number }[]
    total?: number | null
    slices?: { label: string, value: number, color?: string | null }[]
    target?: number
    columns?: { key: string, label: string, align?: 'start' | 'end' }[]
    rows?: Record<string, unknown>[]
    link?: string | null
}

export interface VectorDashboard {
    name: string
    label: string
    icon: string | null
    widgets: VectorWidget[]
}

export interface VectorJob {
    id: number
    kind: 'action' | 'export' | 'import'
    resource: string
    name: string
    actorId: string
    status: 'queued' | 'running' | 'done' | 'failed'
    total: number | null
    processed: number
    result: Record<string, unknown> | null
    failure: string | null
    hasFile: boolean
    fileName: string | null
    createdAt: string
    updatedAt: string
    finishedAt: string | null
}

export interface VectorNotification {
    id: number
    kind: 'info' | 'success' | 'warning' | 'error'
    title: string
    body: string | null
    link: string | null
    readAt: string | null
    createdAt: string
}

export interface VectorAuditEntry {
    id: number
    event: string
    actorId: string
    impersonatorId: string | null
    changes: Record<string, { from: unknown, to: unknown }>
    createdAt: string
}

export interface VectorImportPreview {
    headers: string[]
    rows: number
    sample: Record<string, string>[]
    mapping: Record<string, string>
}

/** One condition set, `filter[field][op] = value`, all of which must hold; values as the query string carries them. */
export type VectorFilters = Record<string, Partial<Record<VectorOperator, string>>>

/** Alternatives: any one of these condition sets may hold. */
export type VectorFilterGroup = VectorFilters[]

/**
 * The list grammar's whole filter object: field conditions, `trashed`,
 * and `or` / `and` holding more filter objects — `(a or b) and c` is
 * `{c: …, or: [{a: …}, {b: …}]}`.
 */
export interface VectorFilterQuery {
    [key: string]: Partial<Record<VectorOperator, string>> | string | VectorFilterQuery[] | undefined
}

/** The list grammar a saved view stores and a bulk "all matching" selection sends. */
export interface VectorListState {
    filter?: VectorFilterQuery
    q?: string
    sort?: string
    group?: string
    columns?: string
    perPage?: number
    summaries?: boolean
}

export interface VectorSelection {
    ids?: (number | string)[]
    all?: { filter?: VectorFilterQuery, q?: string }
}

export interface VectorSavedView {
    id: number
    resource: string
    ownerId: string
    name: string
    shared: boolean
    isDefault: boolean
    /** the default of everyone who has none of their own */
    resourceDefault: boolean
    state: VectorListState
    mine: boolean
}

export interface VectorSettingsGroup {
    name: string
    label: string
    icon: string | null
    fields: VectorField[]
    values: Record<string, unknown>
}

export interface VectorBranding {
    title: string
    logoUrl: string | null
    primaryColor: string | null
    primaryColorDark: string | null
    theme: 'system' | 'light' | 'dark' | null
    footerText: string | null
}

export interface VectorSearchResult {
    resource: string
    resourceLabel: string
    id: number | string
    title: string
    /** the resource's searchSubtitle() field, as stored */
    subtitle?: string | null
    /** the image field to fetch the thumbnail from, when the record has one */
    image?: string | null
}

export interface VectorSort {
    field: string
    order: 'asc' | 'desc'
}

/**
 * One node of a resource's layout. Every key is present; the ones a kind
 * does not use are null or false. A `field` node names a field of the
 * resource; the others hold `children`.
 */
export interface VectorLayoutNode {
    kind: 'section' | 'tabs' | 'tab' | 'field'
    label: string | null
    description: string | null
    collapsible: boolean
    collapsed: boolean
    /** A section's or tab's grid columns (1 to 4). */
    columns: number | null
    /** A field's width: 1 or 2 columns, or the whole row; null sizes it by its type. */
    span: 1 | 2 | 'full' | null
    name: string | null
    children: VectorLayoutNode[] | null
}

/** How the form and the show page group the fields, per view. */
export interface VectorLayout {
    form: VectorLayoutNode[]
    show: VectorLayoutNode[]
}

export interface VectorResource {
    slug: string
    label: string
    labelSingular: string
    group: string | null
    icon: string | null
    primaryKey: { field: string, column?: string, type: 'int' | 'string' }
    titleField: string
    version: string | null
    fields: VectorField[]
    table: {
        columns: string[]
        defaultSort: VectorSort[]
        perPage: number
        perPageOptions: number[]
        maxPerPage: number
        search: string[]
        groupable: string[]
        summaries: string[]
        exportLimit: number
        bulkLimit: number
    }
    show: { fields: string[] }
    /** Sections, tabs and spans; null when the resource declares none. Optional for a descriptor from before layout existed. */
    layout?: VectorLayout | null
    softDelete: { field: string } | null
    /** what a global search result shows besides its title */
    searchResult?: { subtitle: string | null, image: string | null }
    actions: VectorAction[]
    widgets: VectorWidget[]
    permissions: { viewAny: boolean, create: boolean, update: boolean, delete: boolean, export: boolean, setDefaultView?: boolean }
}

export interface VectorRecordPermissions {
    update: boolean
    delete: boolean
    restore: boolean
    forceDelete: boolean
    actions: string[]
}

export interface VectorMenuGroup {
    label: string
    resources: string[]
}

export interface VectorSchema {
    version: number
    resources: Record<string, VectorResource>
    menu: VectorMenuGroup[]
    settings: { name: string, label: string, icon: string | null }[]
    dashboards: { name: string, label: string, icon: string | null }[]
    capabilities: { impersonation: boolean, queue: boolean, uploads?: boolean }
    branding: VectorBranding | null
    /** ISO 4217 exponents of the currencies whose minor unit is not a hundredth */
    minorUnits?: Record<string, number>
}

export interface VectorMe {
    id: number | string
    name: string | null
    preferences: Record<string, unknown>
    impersonator: number | string | null
    unreadNotifications: number
}

export interface VectorRelationValue {
    id: number | string
    label: string
}

export interface VectorUploaded {
    key: string
    name: string | null
    size: number
    type: string
}

export interface VectorCountValue {
    count: number
}

export type VectorRecord = Record<string, unknown> & { id: number | string }

export interface VectorPage {
    data: VectorRecord[]
    currentPage: number
    perPage: number
    total: number
    lastPage: number
    summaries?: Record<string, number | null>
}

export function fieldProps(field: VectorField): Record<string, unknown> {
    return Array.isArray(field.props) ? {} : field.props
}
