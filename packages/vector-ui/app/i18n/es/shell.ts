// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Administración',
    account: 'Cuenta',
    signOut: 'Cerrar sesión',
    toggleNavigation: 'Mostrar u ocultar la navegación',
    toggleTheme: 'Cambiar tema',
    language: 'Idioma: {name}',
    languageFailed: 'No se pudo guardar el idioma: {error}',
    nav: {
        main: 'Principal',
        dashboard: 'Panel',
        jobs: 'Tareas',
        settings: 'Configuración',
        pages: 'Páginas',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Página desconocida',
        loading: 'Cargando la página',
    },
    impersonation: {
        actingAs: 'Está actuando como {name}.',
        anotherUser: 'otro usuario',
        stop: 'Dejar de suplantar',
        noAccess: '{name} no tiene acceso a la administración, así que no hay nada que ver en su nombre. Se detuvo la suplantación.',
        noToken: 'La aplicación respondió sin un token ni una recarga, así que no cambió nada.',
    },
    palette: {
        placeholder: 'Buscar registros y recursos…',
        records: 'Registros',
        resources: 'Recursos',
        noMatch: 'Ningún registro coincide',
    },
    inbox: {
        title: 'Notificaciones',
        markAllRead: 'Marcar todo como leído',
        empty: 'Aún no hay nada',
    },
    login: {
        pageTitle: 'Iniciar sesión · {brand}',
        subtitle: 'Inicie sesión en la administración',
        email: 'Correo electrónico',
        password: 'Contraseña',
        submit: 'Iniciar sesión',
        invalidEmail: 'Introduzca una dirección de correo electrónico válida',
        welcome: 'Hola de nuevo, {name}.',
    },
    home: {
        title: 'Panel',
    },
}
