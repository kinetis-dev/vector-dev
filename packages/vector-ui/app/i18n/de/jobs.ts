// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Jobs',
    heading: 'Hintergrundjobs',
    columns: {
        job: 'Job',
        resource: 'Ressource',
        status: 'Status',
        progress: 'Fortschritt',
        started: 'Gestartet',
    },
    empty: 'Noch keine Jobs. Große Exporte, Importe und Aktionen in der Warteschlange erscheinen hier.',
    kind: {
        action: 'Aktion',
        export: 'Export',
        import: 'Import',
    },
    status: {
        queued: 'in Warteschlange',
        running: 'läuft',
        done: 'erledigt',
        failed: 'fehlgeschlagen',
    },
    detail: {
        pageTitle: 'Job #{id}',
        fallbackTitle: 'Job',
        started: 'gestartet {date}',
        finished: 'beendet {date}',
        progress: 'Jobfortschritt',
        processed: '{processed} verarbeitet',
        processedOfTotal: '{processed} von {total} verarbeitet',
        download: '{file} herunterladen',
        file: 'Datei',
    },
}
