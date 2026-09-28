// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Taken',
    heading: 'Achtergrondtaken',
    columns: {
        job: 'Taak',
        resource: 'Resource',
        status: 'Status',
        progress: 'Voortgang',
        started: 'Gestart',
    },
    empty: 'Nog geen taken. Grote exports, imports en acties in de wachtrij verschijnen hier.',
    kind: {
        action: 'actie',
        export: 'export',
        import: 'import',
    },
    status: {
        queued: 'in wachtrij',
        running: 'bezig',
        done: 'voltooid',
        failed: 'mislukt',
        stalled: 'vastgelopen',
    },
    detail: {
        pageTitle: 'Taak #{id}',
        fallbackTitle: 'Taak',
        started: 'gestart {date}',
        finished: 'voltooid {date}',
        progress: 'Voortgang van de taak',
        processed: '{processed} verwerkt',
        processedOfTotal: '{processed} van {total} verwerkt',
        download: '{file} downloaden',
        file: 'bestand',
    },
}
