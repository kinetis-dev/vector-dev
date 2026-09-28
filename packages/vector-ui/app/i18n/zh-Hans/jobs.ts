// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: '任务',
    heading: '后台任务',
    columns: {
        job: '任务',
        resource: '资源',
        status: '状态',
        progress: '进度',
        started: '开始时间',
    },
    empty: '暂无任务。大型导出、导入和排队的操作会显示在这里。',
    kind: {
        action: '操作',
        export: '导出',
        import: '导入',
    },
    status: {
        queued: '排队中',
        running: '运行中',
        done: '已完成',
        failed: '失败',
        stalled: '停滞',
    },
    detail: {
        pageTitle: '任务 #{id}',
        fallbackTitle: '任务',
        started: '开始于 {date}',
        finished: '完成于 {date}',
        progress: '任务进度',
        processed: '已处理 {processed} 条',
        processedOfTotal: '已处理 {processed} / {total} 条',
        download: '下载{file}',
        file: '文件',
    },
}
