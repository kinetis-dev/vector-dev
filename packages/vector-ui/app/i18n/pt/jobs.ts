// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Tarefas',
    heading: 'Tarefas em segundo plano',
    columns: {
        job: 'Tarefa',
        resource: 'Recurso',
        status: 'Estado',
        progress: 'Progresso',
        started: 'Iniciada',
    },
    empty: 'Ainda não há tarefas. As exportações e importações grandes e as ações em fila aparecem aqui.',
    kind: {
        action: 'ação',
        export: 'exportação',
        import: 'importação',
    },
    status: {
        queued: 'em fila',
        running: 'em execução',
        done: 'concluída',
        failed: 'com falha',
        stalled: 'bloqueada',
    },
    detail: {
        pageTitle: 'Tarefa #{id}',
        fallbackTitle: 'Tarefa',
        started: 'iniciada em {date}',
        finished: 'concluída em {date}',
        progress: 'Progresso da tarefa',
        processed: '{processed} processados',
        processedOfTotal: '{processed} de {total} processados',
        download: 'Download de {file}',
        file: 'ficheiro',
    },
}
