// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Задачи',
    heading: 'Фоновые задачи',
    columns: {
        job: 'Задача',
        resource: 'Ресурс',
        status: 'Статус',
        progress: 'Прогресс',
        started: 'Начало',
    },
    empty: 'Задач пока нет. Здесь появляются крупные экспорты, импорты и действия в очереди.',
    kind: {
        action: 'действие',
        export: 'экспорт',
        import: 'импорт',
    },
    status: {
        queued: 'в очереди',
        running: 'выполняется',
        done: 'готово',
        failed: 'ошибка',
    },
    detail: {
        pageTitle: 'Задача #{id}',
        fallbackTitle: 'Задача',
        started: 'начата {date}',
        finished: 'завершена {date}',
        progress: 'Ход выполнения задачи',
        processed: 'Обработано: {processed}',
        processedOfTotal: 'Обработано {processed} из {total}',
        download: 'Скачать {file}',
        file: 'файл',
    },
}
