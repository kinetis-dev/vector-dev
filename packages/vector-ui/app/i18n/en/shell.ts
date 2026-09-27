// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Admin',
    account: 'Account',
    signOut: 'Sign out',
    toggleNavigation: 'Toggle navigation',
    toggleTheme: 'Toggle theme',
    language: 'Language: {name}',
    languageFailed: 'The language could not be saved: {error}',
    nav: {
        main: 'Main',
        dashboard: 'Dashboard',
        jobs: 'Jobs',
        settings: 'Settings',
        pages: 'Pages',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Unknown page',
        loading: 'Loading the page',
    },
    impersonation: {
        actingAs: 'You are acting as {name}.',
        anotherUser: 'another user',
        stop: 'Stop impersonating',
        noAccess: '{name} has no access to the admin, so there is nothing to see as them. Impersonation was stopped.',
        noToken: 'The application answered without a token or a reload, so nothing changed.',
    },
    palette: {
        placeholder: 'Search records and resources…',
        records: 'Records',
        resources: 'Resources',
        noMatch: 'No records match',
    },
    inbox: {
        title: 'Notifications',
        markAllRead: 'Mark all read',
        empty: 'Nothing yet',
    },
    login: {
        pageTitle: 'Sign in · {brand}',
        subtitle: 'Sign in to the admin',
        email: 'E-mail',
        password: 'Password',
        submit: 'Sign in',
        invalidEmail: 'Enter a valid e-mail address',
        welcome: 'Welcome back, {name}.',
    },
    home: {
        title: 'Dashboard',
    },
}
