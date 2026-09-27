// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Адмінпанель',
    account: 'Обліковий запис',
    signOut: 'Вийти',
    toggleNavigation: 'Перемкнути навігацію',
    toggleTheme: 'Перемкнути тему',
    language: 'Мова: {name}',
    languageFailed: 'Не вдалося зберегти мову: {error}',
    nav: {
        main: 'Головне',
        dashboard: 'Панель керування',
        jobs: 'Завдання',
        settings: 'Налаштування',
        pages: 'Сторінки',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Невідома сторінка',
        loading: 'Завантаження сторінки',
    },
    impersonation: {
        actingAs: 'Ви дієте від імені {name}.',
        anotherUser: 'іншого користувача',
        stop: 'Повернутися до свого облікового запису',
        noAccess: '{name} не має доступу до адмінпанелі, тож від цього імені нічого не видно. Дію від імені користувача припинено.',
        noToken: 'Застосунок відповів без токена й без перезавантаження, тож нічого не змінилося.',
    },
    palette: {
        placeholder: 'Пошук записів і ресурсів…',
        records: 'Записи',
        resources: 'Ресурси',
        noMatch: 'Немає відповідних записів',
    },
    inbox: {
        title: 'Сповіщення',
        markAllRead: 'Позначити все як прочитане',
        empty: 'Поки що нічого немає',
    },
    login: {
        pageTitle: 'Вхід · {brand}',
        subtitle: 'Увійдіть до адмінпанелі',
        email: 'Електронна пошта',
        password: 'Пароль',
        submit: 'Увійти',
        invalidEmail: 'Введіть дійсну адресу електронної пошти',
        welcome: 'З поверненням, {name}.',
    },
    home: {
        title: 'Панель керування',
    },
}
