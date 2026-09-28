// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Админ-панель',
    account: 'Учётная запись',
    signOut: 'Выйти',
    toggleNavigation: 'Показать или скрыть навигацию',
    toggleTheme: 'Сменить тему',
    language: 'Язык: {name}',
    languageFailed: 'Не удалось сохранить язык: {error}',
    nav: {
        main: 'Главное',
        dashboard: 'Панель управления',
        jobs: 'Задачи',
        settings: 'Настройки',
        pages: 'Страницы',
        badge: '{label}: {count}',
    },
    page: {
        unknown: 'Неизвестная страница',
        loading: 'Загрузка страницы',
    },
    impersonation: {
        actingAs: 'Вы действуете от имени {name}.',
        anotherUser: 'другого пользователя',
        stop: 'Прекратить действовать от чужого имени',
        noAccess: 'У {name} нет доступа к админ-панели, поэтому от этого имени нечего показать. Действие от чужого имени прекращено.',
        noToken: 'Приложение ответило без токена и без перезагрузки, поэтому ничего не изменилось.',
    },
    palette: {
        placeholder: 'Поиск записей и ресурсов…',
        records: 'Записи',
        resources: 'Ресурсы',
        noMatch: 'Нет подходящих записей',
    },
    inbox: {
        title: 'Уведомления',
        markAllRead: 'Отметить все как прочитанные',
        empty: 'Пока ничего нет',
    },
    login: {
        pageTitle: 'Вход · {brand}',
        subtitle: 'Вход в админ-панель',
        email: 'Эл. почта',
        password: 'Пароль',
        submit: 'Войти',
        invalidEmail: 'Введите корректный адрес электронной почты',
        welcome: 'С возвращением, {name}.',
        signedOutIdle: 'Сеанс завершён после {minutes} минут бездействия. Войдите снова, чтобы продолжить с того же места.',
    },
    home: {
        title: 'Панель управления',
    },
}
