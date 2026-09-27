// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Amministrazione',
    account: 'Account',
    signOut: 'Esci',
    toggleNavigation: 'Mostra/nascondi navigazione',
    toggleTheme: 'Cambia tema',
    language: 'Lingua: {name}',
    languageFailed: 'Impossibile salvare la lingua: {error}',
    nav: {
        main: 'Principale',
        dashboard: 'Dashboard',
        jobs: 'Job',
        settings: 'Impostazioni',
        pages: 'Pagine',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Pagina sconosciuta',
        loading: 'Caricamento della pagina',
    },
    impersonation: {
        actingAs: 'Stai agendo come {name}.',
        anotherUser: 'un altro utente',
        stop: 'Termina impersonazione',
        noAccess: '{name} non ha accesso all’amministrazione, quindi non c’è nulla da vedere al suo posto. L’impersonazione è stata interrotta.',
        noToken: 'L’applicazione ha risposto senza un token né un ricaricamento, quindi non è cambiato nulla.',
    },
    palette: {
        placeholder: 'Cerca record e risorse…',
        records: 'Record',
        resources: 'Risorse',
        noMatch: 'Nessun record corrispondente',
    },
    inbox: {
        title: 'Notifiche',
        markAllRead: 'Segna tutte come lette',
        empty: 'Ancora niente',
    },
    login: {
        pageTitle: 'Accedi · {brand}',
        subtitle: 'Accedi all’amministrazione',
        email: 'E-mail',
        password: 'Password',
        submit: 'Accedi',
        invalidEmail: 'Inserisci un indirizzo e-mail valido',
        welcome: 'Ti diamo il bentornato, {name}.',
    },
    home: {
        title: 'Dashboard',
    },
}
