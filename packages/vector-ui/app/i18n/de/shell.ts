// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Admin',
    account: 'Konto',
    signOut: 'Abmelden',
    toggleNavigation: 'Navigation ein-/ausblenden',
    toggleTheme: 'Design wechseln',
    language: 'Sprache: {name}',
    languageFailed: 'Die Sprache konnte nicht gespeichert werden: {error}',
    nav: {
        main: 'Hauptmenü',
        dashboard: 'Dashboard',
        jobs: 'Jobs',
        settings: 'Einstellungen',
        pages: 'Seiten',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Unbekannte Seite',
        loading: 'Seite wird geladen',
    },
    impersonation: {
        actingAs: 'Sie handeln als {name}.',
        anotherUser: 'ein anderer Benutzer',
        stop: 'Identitätswechsel beenden',
        noAccess: '{name} hat keinen Zugriff auf den Admin, daher gibt es in dieser Rolle nichts zu sehen. Der Identitätswechsel wurde beendet.',
        noToken: 'Die Anwendung hat weder ein Token noch ein Neuladen geliefert, daher wurde nichts geändert.',
    },
    palette: {
        placeholder: 'Datensätze und Ressourcen suchen…',
        records: 'Datensätze',
        resources: 'Ressourcen',
        noMatch: 'Keine passenden Datensätze',
    },
    inbox: {
        title: 'Benachrichtigungen',
        markAllRead: 'Alle als gelesen markieren',
        empty: 'Noch nichts',
    },
    login: {
        pageTitle: 'Anmelden · {brand}',
        subtitle: 'Beim Admin anmelden',
        email: 'E-Mail',
        password: 'Passwort',
        submit: 'Anmelden',
        invalidEmail: 'Geben Sie eine gültige E-Mail-Adresse ein',
        welcome: 'Willkommen zurück, {name}.',
    },
    home: {
        title: 'Dashboard',
    },
}
