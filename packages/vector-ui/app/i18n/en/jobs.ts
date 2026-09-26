// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Jobs',
    heading: 'Background jobs',
    columns: {
        job: 'Job',
        resource: 'Resource',
        status: 'Status',
        progress: 'Progress',
        started: 'Started',
    },
    empty: 'No jobs yet. Large exports, imports and queued actions appear here.',
    kind: {
        action: 'action',
        export: 'export',
        import: 'import',
    },
    status: {
        queued: 'queued',
        running: 'running',
        done: 'done',
        failed: 'failed',
    },
    detail: {
        pageTitle: 'Job #{id}',
        fallbackTitle: 'Job',
        started: 'started {date}',
        finished: 'finished {date}',
        progress: 'Job progress',
        processed: '{processed} processed',
        processedOfTotal: '{processed} of {total} processed',
        download: 'Download {file}',
        file: 'file',
    },
}
