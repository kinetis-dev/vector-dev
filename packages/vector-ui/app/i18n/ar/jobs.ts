// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'المهام',
    heading: 'مهام الخلفية',
    columns: {
        job: 'المهمة',
        resource: 'المورد',
        status: 'الحالة',
        progress: 'التقدم',
        started: 'وقت البدء',
    },
    empty: 'لا توجد مهام بعد. تظهر هنا عمليات التصدير والاستيراد الكبيرة والإجراءات الموضوعة في قائمة الانتظار.',
    kind: {
        action: 'إجراء',
        export: 'تصدير',
        import: 'استيراد',
    },
    status: {
        queued: 'في قائمة الانتظار',
        running: 'قيد التشغيل',
        done: 'مكتملة',
        failed: 'فاشلة',
    },
    detail: {
        pageTitle: 'المهمة #{id}',
        fallbackTitle: 'مهمة',
        started: 'بدأت {date}',
        finished: 'انتهت {date}',
        progress: 'تقدم المهمة',
        processed: 'تمت معالجة {processed}',
        processedOfTotal: 'تمت معالجة {processed} من {total}',
        download: 'تنزيل {file}',
        file: 'الملف',
    },
}
