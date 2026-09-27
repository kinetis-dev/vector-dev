// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Zadania',
    heading: 'Zadania w tle',
    columns: {
        job: 'Zadanie',
        resource: 'Zasób',
        status: 'Status',
        progress: 'Postęp',
        started: 'Rozpoczęto',
    },
    empty: 'Brak zadań. Pojawią się tu duże eksporty, importy i akcje w kolejce.',
    kind: {
        action: 'akcja',
        export: 'eksport',
        import: 'import',
    },
    status: {
        queued: 'w kolejce',
        running: 'w toku',
        done: 'gotowe',
        failed: 'niepowodzenie',
    },
    detail: {
        pageTitle: 'Zadanie #{id}',
        fallbackTitle: 'Zadanie',
        started: 'rozpoczęto {date}',
        finished: 'zakończono {date}',
        progress: 'Postęp zadania',
        processed: 'Przetworzono: {processed}',
        processedOfTotal: 'Przetworzono {processed} z {total}',
        download: 'Pobierz {file}',
        file: 'plik',
    },
}
