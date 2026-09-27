// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Beheer',
    account: 'Account',
    signOut: 'Afmelden',
    toggleNavigation: 'Navigatie in-/uitschakelen',
    toggleTheme: 'Thema wisselen',
    language: 'Taal: {name}',
    languageFailed: 'De taal kon niet worden opgeslagen: {error}',
    nav: {
        main: 'Hoofdmenu',
        dashboard: 'Dashboard',
        jobs: 'Taken',
        settings: 'Instellingen',
        pages: 'Pagina’s',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Onbekende pagina',
        loading: 'De pagina wordt geladen',
    },
    impersonation: {
        actingAs: 'U handelt als {name}.',
        anotherUser: 'een andere gebruiker',
        stop: 'Terug naar uw eigen account',
        noAccess: '{name} heeft geen toegang tot het beheer, dus er is niets te zien namens deze gebruiker. Het handelen als deze gebruiker is gestopt.',
        noToken: 'De applicatie antwoordde zonder token of herlaadopdracht, dus er is niets gewijzigd.',
    },
    palette: {
        placeholder: 'Records en resources zoeken…',
        records: 'Records',
        resources: 'Resources',
        noMatch: 'Geen overeenkomende records',
    },
    inbox: {
        title: 'Meldingen',
        markAllRead: 'Alles als gelezen markeren',
        empty: 'Nog niets',
    },
    login: {
        pageTitle: 'Aanmelden · {brand}',
        subtitle: 'Meld u aan bij het beheer',
        email: 'E-mail',
        password: 'Wachtwoord',
        submit: 'Aanmelden',
        invalidEmail: 'Voer een geldig e-mailadres in',
        welcome: 'Welkom terug, {name}.',
    },
    home: {
        title: 'Dashboard',
    },
}
