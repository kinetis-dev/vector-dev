// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: '管理后台',
    account: '账户',
    signOut: '退出登录',
    toggleNavigation: '切换导航',
    toggleTheme: '切换主题',
    language: '语言：{name}',
    languageFailed: '无法保存语言：{error}',
    nav: {
        main: '主菜单',
        dashboard: '仪表盘',
        jobs: '任务',
        settings: '设置',
        pages: '页面',
        badge: '{count} 个{label}',
    },
    page: {
        unknown: '未知页面',
        loading: '正在加载页面',
    },
    impersonation: {
        actingAs: '你正在以 {name} 的身份操作。',
        anotherUser: '其他用户',
        stop: '停止模拟登录',
        noAccess: '{name} 无权访问管理后台，因此无法以其身份查看任何内容。模拟登录已停止。',
        noToken: '应用返回的响应中既没有令牌也没有重新加载，因此未做任何更改。',
    },
    palette: {
        placeholder: '搜索记录和资源…',
        records: '记录',
        resources: '资源',
        noMatch: '没有匹配的记录',
    },
    inbox: {
        title: '通知',
        markAllRead: '全部标为已读',
        empty: '暂无内容',
    },
    login: {
        pageTitle: '登录 · {brand}',
        subtitle: '登录管理后台',
        email: '电子邮件',
        password: '密码',
        submit: '登录',
        invalidEmail: '请输入有效的电子邮件地址',
        welcome: '欢迎回来，{name}。',
    },
    home: {
        title: '仪表盘',
    },
}
