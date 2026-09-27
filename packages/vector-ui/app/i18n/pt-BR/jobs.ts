// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Tarefas',
    heading: 'Tarefas em segundo plano',
    columns: {
        job: 'Tarefa',
        resource: 'Recurso',
        status: 'Status',
        progress: 'Progresso',
        started: 'Iniciada',
    },
    empty: 'Nenhuma tarefa ainda. Exportações e importações grandes e ações na fila aparecem aqui.',
    kind: {
        action: 'ação',
        export: 'exportação',
        import: 'importação',
    },
    status: {
        queued: 'na fila',
        running: 'em execução',
        done: 'concluída',
        failed: 'falhou',
    },
    detail: {
        pageTitle: 'Tarefa #{id}',
        fallbackTitle: 'Tarefa',
        started: 'iniciada em {date}',
        finished: 'concluída em {date}',
        progress: 'Progresso da tarefa',
        processed: '{processed} processados',
        processedOfTotal: '{processed} de {total} processados',
        download: 'Baixar {file}',
        file: 'arquivo',
    },
}
