import type { VectorActionResponse, VectorAuditEntry, VectorFilterQuery, VectorImportPreview, VectorPage, VectorRecord, VectorRecordPermissions, VectorRelationValue, VectorSelection, VectorSort, VectorUploaded, VectorWidgetData } from '#vector/app/types/vector'

export interface VectorListOptions {
    page?: number
    perPage?: number
    sort?: VectorSort[]
    filter?: VectorFilterQuery
    q?: string
    group?: string
    summaries?: boolean
    columns?: string[]
}

export interface VectorRecordResponse {
    data: VectorRecord
    permissions: VectorRecordPermissions
}

/** `filter[status][eq]=active`: the bracketed keys PHP reads back as a nested array. */
export function filterQuery(filter: VectorFilterQuery | undefined, prefix = 'filter'): Record<string, string> {
    const query: Record<string, string> = {}

    for (const [key, spec] of Object.entries(filter ?? {})) {
        if (spec === undefined) continue

        if (Array.isArray(spec)) {
            // or / and: filter objects of their own, by position
            spec.forEach((child, index) => Object.assign(query, filterQuery(child, `${prefix}[${key}][${index}]`)))
        } else if (typeof spec === 'string') {
            query[`${prefix}[${key}]`] = spec
        } else {
            for (const [op, value] of Object.entries(spec)) {
                if (value !== undefined) query[`${prefix}[${key}][${op}]`] = value
            }
        }
    }

    return query
}

/** The record endpoints for one resource. */
export function useVectorResource(slug: MaybeRefOrGetter<string>) {
    const api = useVectorApi()

    function base() {
        return `/vector/r/${encodeURIComponent(toValue(slug))}`
    }

    function listQuery(options: VectorListOptions): Record<string, string | number> {
        const query: Record<string, string | number> = {}

        if (options.page) {
            query.page = options.page
        }

        if (options.perPage) {
            query.perPage = options.perPage
        }

        if (options.sort && options.sort.length > 0) {
            query.sort = options.sort.map(s => (s.order === 'desc' ? '-' : '') + s.field).join(',')
        }

        if (options.q) {
            query.q = options.q
        }

        if (options.group) {
            query.group = options.group
        }

        if (options.summaries) {
            query.summaries = '1'
        }

        if (options.columns && options.columns.length > 0) {
            query.columns = options.columns.join(',')
        }

        return { ...query, ...filterQuery(options.filter) }
    }

    function list(options: VectorListOptions = {}): Promise<VectorPage> {
        return api<VectorPage>(base(), { query: listQuery(options) })
    }

    function related(id: string | number, relation: string, options: VectorListOptions = {}): Promise<VectorPage> {
        return api<VectorPage>(`${base()}/${encodeURIComponent(String(id))}/${encodeURIComponent(relation)}`, { query: listQuery(options) })
    }

    function show(id: string | number): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}`)
    }

    function create(values: Record<string, unknown>): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(base(), { method: 'POST', body: values })
    }

    function update(id: string | number, values: Record<string, unknown>, version?: string | number | null): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}`, {
            method: 'PATCH',
            body: values,
            headers: version === undefined || version === null ? {} : { 'If-Match': `"${version}"` },
        })
    }

    async function remove(id: string | number): Promise<void> {
        await api(`${base()}/${encodeURIComponent(String(id))}`, { method: 'DELETE' })
    }

    function removeMany(ids: (string | number)[]): Promise<{ deleted: number }> {
        return api<{ deleted: number }>(`${base()}/batch`, { method: 'POST', body: { action: 'delete', ids } })
    }

    function batch(action: 'delete' | 'restore' | 'forceDelete' | 'update', selection: VectorSelection, fields?: Record<string, unknown>): Promise<{ affected: number }> {
        return api<{ affected: number }>(`${base()}/batch`, { method: 'POST', body: { action, ...selection, ...(fields ? { fields } : {}) } })
    }

    /** The list's changeset: different values for different records, in one transaction. */
    function changes(list: { id: number | string, version?: number, values: Record<string, unknown> }[]): Promise<{ data: VectorRecord[] }> {
        return api<{ data: VectorRecord[] }>(`${base()}/changes`, { method: 'POST', body: { changes: list } })
    }

    /** Stores one file for a file or image field; the answer's key is the value to write. */
    function upload(field: string, file: File): Promise<VectorUploaded> {
        const body = new FormData()
        body.append('file', file)
        return api<VectorUploaded>(`${base()}/fields/${encodeURIComponent(field)}/upload`, { method: 'POST', body })
    }

    /** A record's stored file, fetched with the API's credentials (an <img src> could not send a bearer token). */
    function fileBlob(id: string | number, field: string): Promise<Blob> {
        return api<Blob>(`${base()}/${encodeURIComponent(String(id))}/files/${encodeURIComponent(field)}`, { responseType: 'blob' })
    }

    async function downloadFile(id: string | number, field: string, fallbackName: string): Promise<void> {
        const response = await api.raw(`${base()}/${encodeURIComponent(String(id))}/files/${encodeURIComponent(field)}`, { responseType: 'blob' })
        saveBlob(response._data as Blob, filenameOf(response.headers.get('content-disposition')) ?? fallbackName)
    }

    function attach(id: string | number, relation: string, ids: (string | number)[]): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}/${encodeURIComponent(relation)}/attach`, { method: 'POST', body: { ids } })
    }

    function detach(id: string | number, relation: string, ids: (string | number)[]): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}/${encodeURIComponent(relation)}/detach`, { method: 'POST', body: { ids } })
    }

    async function forceRemove(id: string | number): Promise<void> {
        await api(`${base()}/${encodeURIComponent(String(id))}`, { method: 'DELETE', query: { force: '1' } })
    }

    function restore(id: string | number): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}/restore`, { method: 'POST' })
    }

    /**
     * Runs an action. A download answers with a file, which lands in the
     * browser's download list; anything else is the JSON envelope.
     */
    async function runAction(name: string, selection: VectorSelection | null, fields: Record<string, unknown>): Promise<VectorActionResponse> {
        const response = await api.raw(`${base()}/actions/${encodeURIComponent(name)}`, {
            method: 'POST',
            body: { ...(selection ?? {}), fields },
            responseType: 'blob',
        })
        const type = response.headers.get('content-type') ?? ''

        if (!type.includes('json')) {
            saveBlob(response._data as Blob, filenameOf(response.headers.get('content-disposition')) ?? `${name}.csv`)

            return { type: 'download', message: null, url: null, affected: 0 }
        }

        return JSON.parse(await (response._data as Blob).text()) as VectorActionResponse
    }

    /** Downloads the CSV, or answers the job the server queued for a large one. */
    async function exportCsv(options: VectorListOptions = {}): Promise<{ jobId: number } | null> {
        const response = await api.raw(`${base()}/export`, { query: { ...listQuery({ ...options, page: undefined, perPage: undefined }), format: 'csv' }, responseType: 'blob' })

        if (response.status === 202) {
            const queued = JSON.parse(await (response._data as Blob).text()) as { jobId: number }

            return { jobId: queued.jobId }
        }

        saveBlob(response._data as Blob, filenameOf(response.headers.get('content-disposition')) ?? `${toValue(slug)}.csv`)

        return null
    }

    function audit(id: string | number): Promise<{ data: VectorAuditEntry[] }> {
        return api<{ data: VectorAuditEntry[] }>(`${base()}/${encodeURIComponent(String(id))}/audit`)
    }

    /** The rows a repeater edits: every child of the record, as the child resource shows one. */
    function rows(id: string | number, field: string): Promise<{ data: VectorRecord[] }> {
        return api<{ data: VectorRecord[] }>(`${base()}/${encodeURIComponent(String(id))}/${encodeURIComponent(field)}/rows`)
    }

    /** Puts back what one history entry changed; 409 when a field changed since. */
    function revert(id: string | number, entryId: number): Promise<VectorRecordResponse> {
        return api<VectorRecordResponse>(`${base()}/${encodeURIComponent(String(id))}/audit/${entryId}/revert`, { method: 'POST' })
    }

    function widget(name: string, range?: string): Promise<{ data: VectorWidgetData }> {
        return api<{ data: VectorWidgetData }>(`${base()}/widgets/${encodeURIComponent(name)}`, { query: range ? { range } : {} })
    }

    function importPreview(file: File): Promise<VectorImportPreview> {
        const body = new FormData()
        body.append('file', file)

        return api<VectorImportPreview>(`${base()}/import/preview`, { method: 'POST', body })
    }

    function importCsv(file: File, mapping: Record<string, string>): Promise<{ jobId: number, rows: number }> {
        const body = new FormData()
        body.append('file', file)
        body.append('mapping', JSON.stringify(mapping))

        return api<{ jobId: number, rows: number }>(`${base()}/import`, { method: 'POST', body })
    }

    /** `depends` carries the form's values a dependent choice list narrows by. */
    function options(field: string, search: string, ids: (string | number)[] = [], depends: Record<string, string | number> = {}): Promise<{ data: VectorRelationValue[] }> {
        const narrowing = Object.fromEntries(Object.entries(depends).map(([name, value]) => [`depends[${name}]`, String(value)]))

        return api<{ data: VectorRelationValue[] }>(`${base()}/fields/${encodeURIComponent(field)}/options`, {
            query: ids.length > 0 ? { ids: ids.join(',') } : { q: search, ...narrowing },
        })
    }

    return { list, related, show, create, update, remove, removeMany, batch, changes, attach, detach, upload, fileBlob, downloadFile, forceRemove, restore, runAction, exportCsv, audit, revert, rows, widget, importPreview, importCsv, options }
}

function filenameOf(disposition: string | null): string | undefined {
    const match = disposition?.match(/filename="([^"]+)"/)

    return match?.[1]
}

function saveBlob(blob: Blob, filename: string) {
    if (!import.meta.client) return

    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
}
