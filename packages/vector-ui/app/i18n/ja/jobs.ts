// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'ジョブ',
    heading: 'バックグラウンドジョブ',
    columns: {
        job: 'ジョブ',
        resource: 'リソース',
        status: 'ステータス',
        progress: '進捗',
        started: '開始日時',
    },
    empty: 'ジョブはまだありません。大規模なエクスポート、インポート、キューに追加されたアクションがここに表示されます。',
    kind: {
        action: 'アクション',
        export: 'エクスポート',
        import: 'インポート',
    },
    status: {
        queued: '待機中',
        running: '実行中',
        done: '完了',
        failed: '失敗',
    },
    detail: {
        pageTitle: 'ジョブ #{id}',
        fallbackTitle: 'ジョブ',
        started: '{date} に開始',
        finished: '{date} に終了',
        progress: 'ジョブの進捗',
        processed: '{processed} 件処理済み',
        processedOfTotal: '{total} 件中 {processed} 件処理済み',
        download: '{file} をダウンロード',
        file: 'ファイル',
    },
}
