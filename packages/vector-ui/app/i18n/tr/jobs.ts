// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'İşler',
    heading: 'Arka plan işleri',
    columns: {
        job: 'İş',
        resource: 'Kaynak',
        status: 'Durum',
        progress: 'İlerleme',
        started: 'Başlangıç',
    },
    empty: 'Henüz iş yok. Büyük dışa aktarmalar, içe aktarmalar ve kuyruğa alınan eylemler burada görünür.',
    kind: {
        action: 'eylem',
        export: 'dışa aktarma',
        import: 'içe aktarma',
    },
    status: {
        queued: 'kuyrukta',
        running: 'çalışıyor',
        done: 'tamamlandı',
        failed: 'başarısız',
    },
    detail: {
        pageTitle: 'İş #{id}',
        fallbackTitle: 'İş',
        started: 'başlangıç: {date}',
        finished: 'bitiş: {date}',
        progress: 'İş ilerlemesi',
        processed: '{processed} işlendi',
        processedOfTotal: '{processed} / {total} işlendi',
        download: 'İndir: {file}',
        file: 'dosya',
    },
}
