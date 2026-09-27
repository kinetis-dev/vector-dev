// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Administration',
    account: 'Compte',
    signOut: 'Se déconnecter',
    toggleNavigation: 'Afficher/masquer la navigation',
    toggleTheme: 'Changer de thème',
    language: 'Langue : {name}',
    languageFailed: 'La langue n’a pas pu être enregistrée : {error}',
    nav: {
        main: 'Principal',
        dashboard: 'Tableau de bord',
        jobs: 'Tâches',
        settings: 'Paramètres',
        pages: 'Pages',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Page inconnue',
        loading: 'Chargement de la page',
    },
    impersonation: {
        actingAs: 'Vous agissez en tant que {name}.',
        anotherUser: 'un autre utilisateur',
        stop: 'Arrêter l’usurpation',
        noAccess: '{name} n’a pas accès à l’administration, il n’y a donc rien à voir en son nom. L’usurpation a été arrêtée.',
        noToken: 'L’application a répondu sans jeton ni rechargement, rien n’a donc changé.',
    },
    palette: {
        placeholder: 'Rechercher des enregistrements et des ressources…',
        records: 'Enregistrements',
        resources: 'Ressources',
        noMatch: 'Aucun enregistrement ne correspond',
    },
    inbox: {
        title: 'Notifications',
        markAllRead: 'Tout marquer comme lu',
        empty: 'Rien pour l’instant',
    },
    login: {
        pageTitle: 'Connexion · {brand}',
        subtitle: 'Connectez-vous à l’administration',
        email: 'E-mail',
        password: 'Mot de passe',
        submit: 'Se connecter',
        invalidEmail: 'Saisissez une adresse e-mail valide',
        welcome: 'Bon retour, {name}.',
    },
    home: {
        title: 'Tableau de bord',
    },
}
