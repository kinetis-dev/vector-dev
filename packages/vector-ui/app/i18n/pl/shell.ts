// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Administracja',
    account: 'Konto',
    signOut: 'Wyloguj',
    toggleNavigation: 'Przełącz nawigację',
    toggleTheme: 'Przełącz motyw',
    language: 'Język: {name}',
    languageFailed: 'Nie udało się zapisać języka: {error}',
    nav: {
        main: 'Główne',
        dashboard: 'Pulpit',
        jobs: 'Zadania',
        settings: 'Ustawienia',
        pages: 'Strony',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Nieznana strona',
        loading: 'Ładowanie strony',
    },
    impersonation: {
        actingAs: 'Działasz jako {name}.',
        anotherUser: 'inny użytkownik',
        stop: 'Zakończ działanie jako',
        noAccess: '{name} nie ma dostępu do panelu administracyjnego, więc nie ma nic do wyświetlenia w jego imieniu. Działanie jako ten użytkownik zostało zakończone.',
        noToken: 'Aplikacja odpowiedziała bez tokenu i bez przeładowania, więc nic się nie zmieniło.',
    },
    palette: {
        placeholder: 'Szukaj rekordów i zasobów…',
        records: 'Rekordy',
        resources: 'Zasoby',
        noMatch: 'Brak pasujących rekordów',
    },
    inbox: {
        title: 'Powiadomienia',
        markAllRead: 'Oznacz wszystkie jako przeczytane',
        empty: 'Na razie nic',
    },
    login: {
        pageTitle: 'Logowanie · {brand}',
        subtitle: 'Zaloguj się do panelu administracyjnego',
        email: 'E-mail',
        password: 'Hasło',
        submit: 'Zaloguj się',
        invalidEmail: 'Podaj prawidłowy adres e-mail',
        welcome: 'Witaj ponownie, {name}.',
    },
    home: {
        title: 'Pulpit',
    },
}
