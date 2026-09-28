// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'משימות',
    heading: 'משימות רקע',
    columns: {
        job: 'משימה',
        resource: 'משאב',
        status: 'סטטוס',
        progress: 'התקדמות',
        started: 'התחלה',
    },
    empty: 'אין עדיין משימות. ייצואים וייבואים גדולים ופעולות שנוספו לתור מופיעים כאן.',
    kind: {
        action: 'פעולה',
        export: 'ייצוא',
        import: 'ייבוא',
    },
    status: {
        queued: 'בתור',
        running: 'בריצה',
        done: 'הושלמה',
        failed: 'נכשלה',
        stalled: 'תקועה',
    },
    detail: {
        pageTitle: 'משימה #{id}',
        fallbackTitle: 'משימה',
        started: 'החלה: {date}',
        finished: 'הסתיימה: {date}',
        progress: 'התקדמות המשימה',
        processed: '{processed} עובדו',
        processedOfTotal: '{processed} מתוך {total} עובדו',
        download: 'הורדת {file}',
        file: 'קובץ',
    },
}
