// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: '管理画面',
    account: 'アカウント',
    signOut: 'ログアウト',
    toggleNavigation: 'ナビゲーションの切り替え',
    toggleTheme: 'テーマの切り替え',
    language: '言語：{name}',
    languageFailed: '言語を保存できませんでした：{error}',
    nav: {
        main: 'メイン',
        dashboard: 'ダッシュボード',
        jobs: 'ジョブ',
        settings: '設定',
        pages: 'ページ',
        badge: '{count} {label}',
    },
    page: {
        unknown: '不明なページ',
        loading: 'ページを読み込み中',
    },
    impersonation: {
        actingAs: '{name} として操作しています。',
        anotherUser: '別のユーザー',
        stop: '代理操作を終了',
        noAccess: '{name} は管理画面へのアクセス権がないため、表示できる内容がありません。代理操作を終了しました。',
        noToken: 'アプリケーションがトークンも再読み込みも返さなかったため、何も変更されていません。',
    },
    palette: {
        placeholder: 'レコードとリソースを検索…',
        records: 'レコード',
        resources: 'リソース',
        noMatch: '一致するレコードがありません',
    },
    inbox: {
        title: '通知',
        markAllRead: 'すべて既読にする',
        empty: 'まだ何もありません',
    },
    login: {
        pageTitle: 'ログイン · {brand}',
        subtitle: '管理画面にログイン',
        email: 'メールアドレス',
        password: 'パスワード',
        submit: 'ログイン',
        invalidEmail: '有効なメールアドレスを入力してください',
        welcome: 'おかえりなさい、{name} さん。',
        signedOutIdle: '{minutes} 分間操作がなかったためサインアウトしました。もう一度サインインすると、続きから再開できます。',
    },
    home: {
        title: 'ダッシュボード',
    },
}
