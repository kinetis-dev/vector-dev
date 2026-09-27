// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Завдання',
    heading: 'Фонові завдання',
    columns: {
        job: 'Завдання',
        resource: 'Ресурс',
        status: 'Статус',
        progress: 'Прогрес',
        started: 'Розпочато',
    },
    empty: 'Завдань ще немає. Тут з’являються великі експорти, імпорти й дії в черзі.',
    kind: {
        action: 'дія',
        export: 'експорт',
        import: 'імпорт',
    },
    status: {
        queued: 'у черзі',
        running: 'виконується',
        done: 'виконано',
        failed: 'помилка',
    },
    detail: {
        pageTitle: 'Завдання #{id}',
        fallbackTitle: 'Завдання',
        started: 'розпочато {date}',
        finished: 'завершено {date}',
        progress: 'Прогрес завдання',
        processed: 'Оброблено: {processed}',
        processedOfTotal: 'Оброблено {processed} з {total}',
        download: 'Завантажити {file}',
        file: 'файл',
    },
}
