// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: '작업',
    heading: '백그라운드 작업',
    columns: {
        job: '작업',
        resource: '리소스',
        status: '상태',
        progress: '진행률',
        started: '시작 시간',
    },
    empty: '아직 작업이 없습니다. 대용량 내보내기, 가져오기 및 대기열에 추가된 액션이 여기에 표시됩니다.',
    kind: {
        action: '액션',
        export: '내보내기',
        import: '가져오기',
    },
    status: {
        queued: '대기 중',
        running: '실행 중',
        done: '완료',
        failed: '실패',
        stalled: '중단됨',
    },
    detail: {
        pageTitle: '작업 #{id}',
        fallbackTitle: '작업',
        started: '{date} 시작',
        finished: '{date} 완료',
        progress: '작업 진행률',
        processed: '{processed}건 처리됨',
        processedOfTotal: '{total}건 중 {processed}건 처리됨',
        download: '{file} 다운로드',
        file: '파일',
    },
}
