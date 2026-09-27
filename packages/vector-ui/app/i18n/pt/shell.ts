// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Administração',
    account: 'Conta',
    signOut: 'Terminar sessão',
    toggleNavigation: 'Alternar navegação',
    toggleTheme: 'Alternar tema',
    language: 'Idioma: {name}',
    languageFailed: 'Não foi possível guardar o idioma: {error}',
    nav: {
        main: 'Principal',
        dashboard: 'Painel',
        jobs: 'Tarefas',
        settings: 'Definições',
        pages: 'Páginas',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Página desconhecida',
        loading: 'A carregar a página',
    },
    impersonation: {
        actingAs: 'Está a agir como {name}.',
        anotherUser: 'outro utilizador',
        stop: 'Parar de personificar',
        noAccess: '{name} não tem acesso à administração, por isso não há nada para ver em seu nome. A personificação foi interrompida.',
        noToken: 'A aplicação respondeu sem um token nem um recarregamento, por isso nada mudou.',
    },
    palette: {
        placeholder: 'Pesquisar registos e recursos…',
        records: 'Registos',
        resources: 'Recursos',
        noMatch: 'Nenhum registo corresponde',
    },
    inbox: {
        title: 'Notificações',
        markAllRead: 'Marcar tudo como lido',
        empty: 'Ainda nada',
    },
    login: {
        pageTitle: 'Iniciar sessão · {brand}',
        subtitle: 'Inicie sessão na administração',
        email: 'E-mail',
        password: 'Palavra-passe',
        submit: 'Iniciar sessão',
        invalidEmail: 'Insira um endereço de e-mail válido',
        welcome: 'Bem-vindo de volta, {name}.',
    },
    home: {
        title: 'Painel',
        fieldCount: '{count} campo | {count} campos',
    },
}
