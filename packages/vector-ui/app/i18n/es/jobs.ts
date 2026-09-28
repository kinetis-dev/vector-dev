// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Tareas',
    heading: 'Tareas en segundo plano',
    columns: {
        job: 'Tarea',
        resource: 'Recurso',
        status: 'Estado',
        progress: 'Progreso',
        started: 'Inicio',
    },
    empty: 'Aún no hay tareas. Aquí aparecen las exportaciones e importaciones grandes y las acciones en cola.',
    kind: {
        action: 'acción',
        export: 'exportación',
        import: 'importación',
    },
    status: {
        queued: 'en cola',
        running: 'en curso',
        done: 'completada',
        failed: 'fallida',
        stalled: 'bloqueada',
    },
    detail: {
        pageTitle: 'Tarea #{id}',
        fallbackTitle: 'Tarea',
        started: 'inicio: {date}',
        finished: 'fin: {date}',
        progress: 'Progreso de la tarea',
        processed: '{processed} procesados',
        processedOfTotal: '{processed} de {total} procesados',
        download: 'Descargar {file}',
        file: 'archivo',
    },
}
