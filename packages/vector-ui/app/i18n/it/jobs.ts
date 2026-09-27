// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Job',
    heading: 'Job in background',
    columns: {
        job: 'Job',
        resource: 'Risorsa',
        status: 'Stato',
        progress: 'Avanzamento',
        started: 'Avviato',
    },
    empty: 'Ancora nessun job. Qui compaiono le esportazioni e importazioni di grandi dimensioni e le azioni in coda.',
    kind: {
        action: 'azione',
        export: 'esportazione',
        import: 'importazione',
    },
    status: {
        queued: 'in coda',
        running: 'in esecuzione',
        done: 'completato',
        failed: 'non riuscito',
    },
    detail: {
        pageTitle: 'Job #{id}',
        fallbackTitle: 'Job',
        started: 'avviato {date}',
        finished: 'terminato {date}',
        progress: 'Avanzamento del job',
        processed: '{processed} elaborati',
        processedOfTotal: '{processed} di {total} elaborati',
        download: 'Scarica {file}',
        file: 'file',
    },
}
