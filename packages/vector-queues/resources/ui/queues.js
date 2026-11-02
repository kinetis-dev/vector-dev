// The queue dashboard's admin UI: a runtime extension, plain JavaScript
// with no build step. Vector serves each declared module under a
// directory named for its own content hash, so a relative import between
// two modules would not resolve: this is one file on purpose.
//
// It registers the "queues" page (overview, failed jobs, metrics) and a
// "vector-queues" widget renderer, and reads only the package's own
// /vector/queues endpoints through the admin's API client. What the
// policy disallows is left out, from GET /vector/queues/permissions, but
// that is cosmetic: the server asks the policy on every call. Every
// string a user sees goes through t(), and every value from the server —
// exception messages, arguments, queue and class names — is rendered as
// a text node, never as HTML.

/** The server's own limits: QueuesController::MAX_RETRY_BATCH, the prune age, MetricsQuery::MAX_MINUTES. */
const MAX_RETRY_BATCH = 50
const MAX_PRUNE_DAYS = 36500
const POLL_MS = 5000
const WINDOWS = [15, 60, 360, 1440, 10080]
const DEFAULT_WINDOW = 60
const SPARK_POINTS = 240

const messages = {
    title: 'Queues',
    menuGroup: 'Operations',
    loading: 'Loading…',
    none: '—',
    percent: '{value}%',
    noAccess: 'Your account may not view the queue dashboard.',
    tabs: { overview: 'Overview', failed: 'Failed jobs', metrics: 'Metrics' },
    actions: { cancel: 'Cancel', confirm: 'Confirm', refresh: 'Refresh', back: 'Back' },
    errors: {
        forbidden: 'Your account may not do that.',
        notFound: 'It no longer exists.',
        conflict: 'The server refused it: {detail}',
        invalid: 'The request was refused: {detail}',
        failed: 'Something went wrong: {detail}',
    },
    overview: {
        queue: 'Queue',
        size: 'Waiting',
        failed: 'Failed',
        unknownSize: 'unknown',
        empty: 'No queues are watched.',
        clear: 'Clear',
        clearUnavailable: 'This queue backend cannot discard waiting jobs.',
        clearTitle: 'Clear "{queue}"?',
        clearText: 'Every job waiting on "{queue}" is discarded. This cannot be undone.',
        cleared: 'Cleared {count} job(s) from "{queue}".',
        clearUnsupported: 'This queue backend cannot discard waiting jobs, so "{queue}" was not cleared.',
        clearInvalid: '"{queue}" was not cleared: {detail}',
    },
    failed: {
        filters: { queue: 'Queue', allQueues: 'All queues', class: 'Job class', from: 'Failed from', to: 'Failed until', apply: 'Apply', reset: 'Reset' },
        select: 'Select {id}',
        id: 'ID',
        class: 'Job class',
        queue: 'Queue',
        attempts: 'Attempts',
        attemptsOf: '{attempts} of {max}',
        exception: 'Exception',
        failedAt: 'Failed at',
        empty: 'No failed jobs match.',
        loadMore: 'Load more',
        open: 'Details',
        retry: 'Retry',
        retrySelected: 'Retry selected ({count})',
        retryLimit: 'At most {max} jobs are retried at once.',
        retryTitle: 'Retry {count} job(s)?',
        retryText: 'Each job is pushed back onto its queue and its failure record removed.',
        retried: 'Retried {retried}; not found {notFound}; failed {failed}.',
        forget: 'Forget',
        forgetTitle: 'Forget this failed job?',
        forgetText: 'The record is deleted without retrying the job.',
        forgotten: 'The failed job was forgotten.',
        prune: 'Prune',
        pruneDays: 'Older than (days)',
        pruneTitle: 'Prune failed jobs older than {days} day(s)?',
        pruneText: 'Every failure record older than that is deleted, on every queue.',
        pruned: 'Deleted {count} failure record(s).',
        detail: {
            title: 'Failed job {id}',
            connection: 'Connection',
            message: 'Message',
            trace: 'Stack trace',
            noTrace: 'No stack trace is recorded for failed jobs.',
            args: 'Arguments',
        },
    },
    metrics: {
        queue: 'Queue',
        allQueues: 'All queues',
        window: 'Window',
        windows: { m15: 'Last 15 minutes', m60: 'Last hour', m360: 'Last 6 hours', m1440: 'Last 24 hours', m10080: 'Last 7 days' },
        succeeded: 'Succeeded',
        released: 'Released',
        failed: 'Failed',
        failureRate: 'Failure rate',
        averageDuration: 'Average (ms)',
        maxDuration: 'Longest (ms)',
        lastProcessed: 'Last processed',
        class: 'Job class',
        byQueue: 'By queue',
        byClass: 'By job class',
        throughput: 'Attempts per minute',
        empty: 'Nothing ran in this window.',
    },
    widget: { pending: 'Waiting', failed: 'Failed', open: 'Open queues' },
}

/**
 * The same messages in Vector's other languages (machine translations,
 * like the rest of Vector's catalogues). Each has exactly the keys of
 * `messages`; a locale without one falls back to English.
 */
const translations = {
    de: {
        title: 'Warteschlangen',
        menuGroup: 'Betrieb',
        loading: 'Wird geladen…',
        none: '—',
        percent: '{value} %',
        noAccess: 'Ihr Konto darf das Warteschlangen-Dashboard nicht ansehen.',
        tabs: {
            overview: 'Übersicht',
            failed: 'Fehlgeschlagene Jobs',
            metrics: 'Metriken',
        },
        actions: {
            cancel: 'Abbrechen',
            confirm: 'Bestätigen',
            refresh: 'Aktualisieren',
            back: 'Zurück',
        },
        errors: {
            forbidden: 'Ihr Konto darf das nicht tun.',
            notFound: 'Es existiert nicht mehr.',
            conflict: 'Der Server hat es abgelehnt: {detail}',
            invalid: 'Die Anfrage wurde abgelehnt: {detail}',
            failed: 'Etwas ist schiefgelaufen: {detail}',
        },
        overview: {
            queue: 'Warteschlange',
            size: 'Wartend',
            failed: 'Fehlgeschlagen',
            unknownSize: 'unbekannt',
            empty: 'Es werden keine Warteschlangen überwacht.',
            clear: 'Leeren',
            clearUnavailable: 'Dieses Warteschlangen-Backend kann wartende Jobs nicht verwerfen.',
            clearTitle: '„{queue}“ leeren?',
            clearText: 'Alle in „{queue}“ wartenden Jobs werden verworfen. Das kann nicht rückgängig gemacht werden.',
            cleared: '{count} Job(s) aus „{queue}“ entfernt.',
            clearUnsupported: 'Dieses Warteschlangen-Backend kann wartende Jobs nicht verwerfen, daher wurde „{queue}“ nicht geleert.',
            clearInvalid: '„{queue}“ wurde nicht geleert: {detail}',
        },
        failed: {
            filters: {
                queue: 'Warteschlange',
                allQueues: 'Alle Warteschlangen',
                class: 'Job-Klasse',
                from: 'Fehlgeschlagen ab',
                to: 'Fehlgeschlagen bis',
                apply: 'Anwenden',
                reset: 'Zurücksetzen',
            },
            select: '{id} auswählen',
            id: 'ID',
            class: 'Job-Klasse',
            queue: 'Warteschlange',
            attempts: 'Versuche',
            attemptsOf: '{attempts} von {max}',
            exception: 'Exception',
            failedAt: 'Fehlgeschlagen am',
            empty: 'Keine fehlgeschlagenen Jobs gefunden.',
            loadMore: 'Mehr laden',
            open: 'Details',
            retry: 'Wiederholen',
            retrySelected: 'Ausgewählte wiederholen ({count})',
            retryLimit: 'Höchstens {max} Jobs werden gleichzeitig wiederholt.',
            retryTitle: '{count} Job(s) wiederholen?',
            retryText: 'Jeder Job wird zurück in seine Warteschlange gestellt und sein Fehlereintrag entfernt.',
            retried: '{retried} wiederholt; {notFound} nicht gefunden; {failed} fehlgeschlagen.',
            forget: 'Verwerfen',
            forgetTitle: 'Diesen fehlgeschlagenen Job verwerfen?',
            forgetText: 'Der Eintrag wird gelöscht, ohne den Job zu wiederholen.',
            forgotten: 'Der fehlgeschlagene Job wurde verworfen.',
            prune: 'Bereinigen',
            pruneDays: 'Älter als (Tage)',
            pruneTitle: 'Fehlgeschlagene Jobs bereinigen, die älter als {days} Tag(e) sind?',
            pruneText: 'Jeder ältere Fehlereintrag wird in allen Warteschlangen gelöscht.',
            pruned: '{count} Fehlereintrag/-einträge gelöscht.',
            detail: {
                title: 'Fehlgeschlagener Job {id}',
                connection: 'Verbindung',
                message: 'Meldung',
                trace: 'Stacktrace',
                noTrace: 'Für fehlgeschlagene Jobs wird kein Stacktrace aufgezeichnet.',
                args: 'Argumente',
            },
        },
        metrics: {
            queue: 'Warteschlange',
            allQueues: 'Alle Warteschlangen',
            window: 'Zeitraum',
            windows: {
                m15: 'Letzte 15 Minuten',
                m60: 'Letzte Stunde',
                m360: 'Letzte 6 Stunden',
                m1440: 'Letzte 24 Stunden',
                m10080: 'Letzte 7 Tage',
            },
            succeeded: 'Erfolgreich',
            released: 'Zurückgestellt',
            failed: 'Fehlgeschlagen',
            failureRate: 'Fehlerquote',
            averageDuration: 'Durchschnitt (ms)',
            maxDuration: 'Längste (ms)',
            lastProcessed: 'Zuletzt verarbeitet',
            class: 'Job-Klasse',
            byQueue: 'Nach Warteschlange',
            byClass: 'Nach Job-Klasse',
            throughput: 'Versuche pro Minute',
            empty: 'In diesem Zeitraum lief nichts.',
        },
        widget: {
            pending: 'Wartend',
            failed: 'Fehlgeschlagen',
            open: 'Warteschlangen öffnen',
        },
    },
    fr: {
        title: 'Files d’attente',
        menuGroup: 'Exploitation',
        loading: 'Chargement…',
        none: '—',
        percent: '{value} %',
        noAccess: 'Votre compte n’est pas autorisé à consulter le tableau de bord des files d’attente.',
        tabs: {
            overview: 'Vue d’ensemble',
            failed: 'Tâches échouées',
            metrics: 'Métriques',
        },
        actions: {
            cancel: 'Annuler',
            confirm: 'Confirmer',
            refresh: 'Actualiser',
            back: 'Retour',
        },
        errors: {
            forbidden: 'Votre compte n’est pas autorisé à effectuer cette action.',
            notFound: 'Cet élément n’existe plus.',
            conflict: 'Le serveur l’a refusé : {detail}',
            invalid: 'La requête a été refusée : {detail}',
            failed: 'Une erreur s’est produite : {detail}',
        },
        overview: {
            queue: 'File d’attente',
            size: 'En attente',
            failed: 'Échouées',
            unknownSize: 'inconnu',
            empty: 'Aucune file d’attente n’est surveillée.',
            clear: 'Vider',
            clearUnavailable: 'Ce backend de file d’attente ne peut pas supprimer les tâches en attente.',
            clearTitle: 'Vider « {queue} » ?',
            clearText: 'Toutes les tâches en attente dans « {queue} » seront supprimées. Cette action est irréversible.',
            cleared: '{count} tâche(s) supprimée(s) de « {queue} ».',
            clearUnsupported: 'Ce backend de file d’attente ne peut pas supprimer les tâches en attente ; « {queue} » n’a donc pas été vidée.',
            clearInvalid: '« {queue} » n’a pas été vidée : {detail}',
        },
        failed: {
            filters: {
                queue: 'File d’attente',
                allQueues: 'Toutes les files d’attente',
                class: 'Classe de tâche',
                from: 'Échec à partir du',
                to: 'Échec jusqu’au',
                apply: 'Appliquer',
                reset: 'Réinitialiser',
            },
            select: 'Sélectionner {id}',
            id: 'ID',
            class: 'Classe de tâche',
            queue: 'File d’attente',
            attempts: 'Tentatives',
            attemptsOf: '{attempts} sur {max}',
            exception: 'Exception',
            failedAt: 'Échec le',
            empty: 'Aucune tâche échouée ne correspond.',
            loadMore: 'Charger plus',
            open: 'Détails',
            retry: 'Réessayer',
            retrySelected: 'Réessayer la sélection ({count})',
            retryLimit: 'Au plus {max} tâches sont relancées à la fois.',
            retryTitle: 'Réessayer {count} tâche(s) ?',
            retryText: 'Chaque tâche est remise dans sa file d’attente et son enregistrement d’échec est supprimé.',
            retried: '{retried} relancée(s) ; {notFound} introuvable(s) ; {failed} échouée(s).',
            forget: 'Oublier',
            forgetTitle: 'Oublier cette tâche échouée ?',
            forgetText: 'L’enregistrement est supprimé sans relancer la tâche.',
            forgotten: 'La tâche échouée a été oubliée.',
            prune: 'Purger',
            pruneDays: 'Plus ancien que (jours)',
            pruneTitle: 'Purger les tâches échouées de plus de {days} jour(s) ?',
            pruneText: 'Tous les enregistrements d’échec plus anciens sont supprimés, sur toutes les files d’attente.',
            pruned: '{count} enregistrement(s) d’échec supprimé(s).',
            detail: {
                title: 'Tâche échouée {id}',
                connection: 'Connexion',
                message: 'Message',
                trace: 'Trace d’appels',
                noTrace: 'Aucune trace d’appels n’est enregistrée pour les tâches échouées.',
                args: 'Arguments',
            },
        },
        metrics: {
            queue: 'File d’attente',
            allQueues: 'Toutes les files d’attente',
            window: 'Période',
            windows: {
                m15: '15 dernières minutes',
                m60: 'Dernière heure',
                m360: '6 dernières heures',
                m1440: '24 dernières heures',
                m10080: '7 derniers jours',
            },
            succeeded: 'Réussies',
            released: 'Relâchées',
            failed: 'Échouées',
            failureRate: 'Taux d’échec',
            averageDuration: 'Moyenne (ms)',
            maxDuration: 'Plus longue (ms)',
            lastProcessed: 'Dernier traitement',
            class: 'Classe de tâche',
            byQueue: 'Par file d’attente',
            byClass: 'Par classe de tâche',
            throughput: 'Tentatives par minute',
            empty: 'Rien ne s’est exécuté sur cette période.',
        },
        widget: {
            pending: 'En attente',
            failed: 'Échouées',
            open: 'Ouvrir les files d’attente',
        },
    },
    nl: {
        title: 'Wachtrijen',
        menuGroup: 'Beheer',
        loading: 'Laden…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Uw account mag het wachtrijdashboard niet bekijken.',
        tabs: {
            overview: 'Overzicht',
            failed: 'Mislukte taken',
            metrics: 'Statistieken',
        },
        actions: {
            cancel: 'Annuleren',
            confirm: 'Bevestigen',
            refresh: 'Vernieuwen',
            back: 'Terug',
        },
        errors: {
            forbidden: 'Uw account mag dat niet doen.',
            notFound: 'Het bestaat niet meer.',
            conflict: 'De server heeft het geweigerd: {detail}',
            invalid: 'Het verzoek is geweigerd: {detail}',
            failed: 'Er is iets misgegaan: {detail}',
        },
        overview: {
            queue: 'Wachtrij',
            size: 'Wachtend',
            failed: 'Mislukt',
            unknownSize: 'onbekend',
            empty: 'Er worden geen wachtrijen bewaakt.',
            clear: 'Legen',
            clearUnavailable: 'Deze wachtrij-backend kan wachtende taken niet verwijderen.',
            clearTitle: '"{queue}" legen?',
            clearText: 'Alle taken die in "{queue}" wachten, worden verwijderd. Dit kan niet ongedaan worden gemaakt.',
            cleared: '{count} taak/taken uit "{queue}" verwijderd.',
            clearUnsupported: 'Deze wachtrij-backend kan wachtende taken niet verwijderen, dus "{queue}" is niet geleegd.',
            clearInvalid: '"{queue}" is niet geleegd: {detail}',
        },
        failed: {
            filters: {
                queue: 'Wachtrij',
                allQueues: 'Alle wachtrijen',
                class: 'Taakklasse',
                from: 'Mislukt vanaf',
                to: 'Mislukt tot',
                apply: 'Toepassen',
                reset: 'Standaard herstellen',
            },
            select: '{id} selecteren',
            id: 'ID',
            class: 'Taakklasse',
            queue: 'Wachtrij',
            attempts: 'Pogingen',
            attemptsOf: '{attempts} van {max}',
            exception: 'Uitzondering',
            failedAt: 'Mislukt op',
            empty: 'Geen mislukte taken gevonden.',
            loadMore: 'Meer laden',
            open: 'Details',
            retry: 'Opnieuw proberen',
            retrySelected: 'Geselecteerde opnieuw proberen ({count})',
            retryLimit: 'Er worden maximaal {max} taken tegelijk opnieuw geprobeerd.',
            retryTitle: '{count} taak/taken opnieuw proberen?',
            retryText: 'Elke taak wordt teruggezet in zijn wachtrij en het foutrecord wordt verwijderd.',
            retried: '{retried} opnieuw geprobeerd; {notFound} niet gevonden; {failed} mislukt.',
            forget: 'Vergeten',
            forgetTitle: 'Deze mislukte taak vergeten?',
            forgetText: 'Het record wordt verwijderd zonder de taak opnieuw te proberen.',
            forgotten: 'De mislukte taak is vergeten.',
            prune: 'Opschonen',
            pruneDays: 'Ouder dan (dagen)',
            pruneTitle: 'Mislukte taken ouder dan {days} dag(en) opschonen?',
            pruneText: 'Elk foutrecord dat ouder is, wordt verwijderd, in alle wachtrijen.',
            pruned: '{count} foutrecord(s) verwijderd.',
            detail: {
                title: 'Mislukte taak {id}',
                connection: 'Verbinding',
                message: 'Bericht',
                trace: 'Stacktrace',
                noTrace: 'Voor mislukte taken wordt geen stacktrace vastgelegd.',
                args: 'Argumenten',
            },
        },
        metrics: {
            queue: 'Wachtrij',
            allQueues: 'Alle wachtrijen',
            window: 'Periode',
            windows: {
                m15: 'Laatste 15 minuten',
                m60: 'Laatste uur',
                m360: 'Laatste 6 uur',
                m1440: 'Laatste 24 uur',
                m10080: 'Laatste 7 dagen',
            },
            succeeded: 'Geslaagd',
            released: 'Teruggezet',
            failed: 'Mislukt',
            failureRate: 'Foutpercentage',
            averageDuration: 'Gemiddelde (ms)',
            maxDuration: 'Langste (ms)',
            lastProcessed: 'Laatst verwerkt',
            class: 'Taakklasse',
            byQueue: 'Per wachtrij',
            byClass: 'Per taakklasse',
            throughput: 'Pogingen per minuut',
            empty: 'In deze periode is niets uitgevoerd.',
        },
        widget: {
            pending: 'Wachtend',
            failed: 'Mislukt',
            open: 'Wachtrijen openen',
        },
    },
    it: {
        title: 'Code',
        menuGroup: 'Operazioni',
        loading: 'Caricamento…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Il tuo account non può visualizzare la dashboard delle code.',
        tabs: {
            overview: 'Panoramica',
            failed: 'Job non riusciti',
            metrics: 'Metriche',
        },
        actions: {
            cancel: 'Annulla',
            confirm: 'Conferma',
            refresh: 'Aggiorna',
            back: 'Indietro',
        },
        errors: {
            forbidden: 'Il tuo account non può farlo.',
            notFound: 'Non esiste più.',
            conflict: 'Il server lo ha rifiutato: {detail}',
            invalid: 'La richiesta è stata rifiutata: {detail}',
            failed: 'Qualcosa è andato storto: {detail}',
        },
        overview: {
            queue: 'Coda',
            size: 'In attesa',
            failed: 'Non riusciti',
            unknownSize: 'sconosciuto',
            empty: 'Nessuna coda è monitorata.',
            clear: 'Svuota',
            clearUnavailable: 'Questo backend di code non può eliminare i job in attesa.',
            clearTitle: 'Svuotare "{queue}"?',
            clearText: 'Tutti i job in attesa su "{queue}" vengono eliminati. L’operazione non può essere annullata.',
            cleared: '{count} job eliminati da "{queue}".',
            clearUnsupported: 'Questo backend di code non può eliminare i job in attesa, quindi "{queue}" non è stata svuotata.',
            clearInvalid: '"{queue}" non è stata svuotata: {detail}',
        },
        failed: {
            filters: {
                queue: 'Coda',
                allQueues: 'Tutte le code',
                class: 'Classe del job',
                from: 'Non riuscito dal',
                to: 'Non riuscito fino al',
                apply: 'Applica',
                reset: 'Ripristina',
            },
            select: 'Seleziona {id}',
            id: 'ID',
            class: 'Classe del job',
            queue: 'Coda',
            attempts: 'Tentativi',
            attemptsOf: '{attempts} di {max}',
            exception: 'Eccezione',
            failedAt: 'Non riuscito il',
            empty: 'Nessun job non riuscito corrisponde.',
            loadMore: 'Carica altri',
            open: 'Dettagli',
            retry: 'Riprova',
            retrySelected: 'Riprova selezionati ({count})',
            retryLimit: 'Al massimo {max} job vengono riprovati alla volta.',
            retryTitle: 'Riprovare {count} job?',
            retryText: 'Ogni job viene rimesso nella sua coda e il relativo record di errore viene rimosso.',
            retried: '{retried} riprovati; {notFound} non trovati; {failed} non riusciti.',
            forget: 'Dimentica',
            forgetTitle: 'Dimenticare questo job non riuscito?',
            forgetText: 'Il record viene eliminato senza riprovare il job.',
            forgotten: 'Il job non riuscito è stato dimenticato.',
            prune: 'Elimina vecchi',
            pruneDays: 'Più vecchi di (giorni)',
            pruneTitle: 'Eliminare i job non riusciti più vecchi di {days} giorno/i?',
            pruneText: 'Ogni record di errore più vecchio viene eliminato, su tutte le code.',
            pruned: '{count} record di errore eliminati.',
            detail: {
                title: 'Job non riuscito {id}',
                connection: 'Connessione',
                message: 'Messaggio',
                trace: 'Stack trace',
                noTrace: 'Per i job non riusciti non viene registrato alcuno stack trace.',
                args: 'Argomenti',
            },
        },
        metrics: {
            queue: 'Coda',
            allQueues: 'Tutte le code',
            window: 'Intervallo',
            windows: {
                m15: 'Ultimi 15 minuti',
                m60: 'Ultima ora',
                m360: 'Ultime 6 ore',
                m1440: 'Ultime 24 ore',
                m10080: 'Ultimi 7 giorni',
            },
            succeeded: 'Riusciti',
            released: 'Rilasciati',
            failed: 'Non riusciti',
            failureRate: 'Tasso di errore',
            averageDuration: 'Media (ms)',
            maxDuration: 'Più lungo (ms)',
            lastProcessed: 'Ultima elaborazione',
            class: 'Classe del job',
            byQueue: 'Per coda',
            byClass: 'Per classe del job',
            throughput: 'Tentativi al minuto',
            empty: 'In questo intervallo non è stato eseguito nulla.',
        },
        widget: {
            pending: 'In attesa',
            failed: 'Non riusciti',
            open: 'Apri code',
        },
    },
    es: {
        title: 'Colas',
        menuGroup: 'Operaciones',
        loading: 'Cargando…',
        none: '—',
        percent: '{value} %',
        noAccess: 'Tu cuenta no puede ver el panel de colas.',
        tabs: {
            overview: 'Resumen',
            failed: 'Tareas fallidas',
            metrics: 'Métricas',
        },
        actions: {
            cancel: 'Cancelar',
            confirm: 'Confirmar',
            refresh: 'Actualizar',
            back: 'Volver',
        },
        errors: {
            forbidden: 'Tu cuenta no puede hacer eso.',
            notFound: 'Ya no existe.',
            conflict: 'El servidor lo rechazó: {detail}',
            invalid: 'Se rechazó la solicitud: {detail}',
            failed: 'Algo salió mal: {detail}',
        },
        overview: {
            queue: 'Cola',
            size: 'En espera',
            failed: 'Fallidas',
            unknownSize: 'desconocido',
            empty: 'No se vigila ninguna cola.',
            clear: 'Vaciar',
            clearUnavailable: 'Este backend de colas no puede descartar las tareas en espera.',
            clearTitle: '¿Vaciar "{queue}"?',
            clearText: 'Se descartan todas las tareas en espera en "{queue}". No se puede deshacer.',
            cleared: 'Se descartaron {count} tarea(s) de "{queue}".',
            clearUnsupported: 'Este backend de colas no puede descartar las tareas en espera, así que "{queue}" no se vació.',
            clearInvalid: '"{queue}" no se vació: {detail}',
        },
        failed: {
            filters: {
                queue: 'Cola',
                allQueues: 'Todas las colas',
                class: 'Clase de tarea',
                from: 'Fallida desde',
                to: 'Fallida hasta',
                apply: 'Aplicar',
                reset: 'Restablecer',
            },
            select: 'Seleccionar {id}',
            id: 'ID',
            class: 'Clase de tarea',
            queue: 'Cola',
            attempts: 'Intentos',
            attemptsOf: '{attempts} de {max}',
            exception: 'Excepción',
            failedAt: 'Falló el',
            empty: 'Ninguna tarea fallida coincide.',
            loadMore: 'Cargar más',
            open: 'Detalles',
            retry: 'Reintentar',
            retrySelected: 'Reintentar seleccionadas ({count})',
            retryLimit: 'Se reintentan como máximo {max} tareas a la vez.',
            retryTitle: '¿Reintentar {count} tarea(s)?',
            retryText: 'Cada tarea vuelve a su cola y se elimina su registro de fallo.',
            retried: '{retried} reintentadas; {notFound} no encontradas; {failed} fallidas.',
            forget: 'Olvidar',
            forgetTitle: '¿Olvidar esta tarea fallida?',
            forgetText: 'El registro se elimina sin reintentar la tarea.',
            forgotten: 'Se olvidó la tarea fallida.',
            prune: 'Depurar',
            pruneDays: 'Anteriores a (días)',
            pruneTitle: '¿Depurar las tareas fallidas anteriores a {days} día(s)?',
            pruneText: 'Se elimina todo registro de fallo anterior, en todas las colas.',
            pruned: 'Se eliminaron {count} registro(s) de fallo.',
            detail: {
                title: 'Tarea fallida {id}',
                connection: 'Conexión',
                message: 'Mensaje',
                trace: 'Traza de pila',
                noTrace: 'No se registra traza de pila para las tareas fallidas.',
                args: 'Argumentos',
            },
        },
        metrics: {
            queue: 'Cola',
            allQueues: 'Todas las colas',
            window: 'Periodo',
            windows: {
                m15: 'Últimos 15 minutos',
                m60: 'Última hora',
                m360: 'Últimas 6 horas',
                m1440: 'Últimas 24 horas',
                m10080: 'Últimos 7 días',
            },
            succeeded: 'Correctas',
            released: 'Liberadas',
            failed: 'Fallidas',
            failureRate: 'Tasa de fallos',
            averageDuration: 'Media (ms)',
            maxDuration: 'Más larga (ms)',
            lastProcessed: 'Último procesamiento',
            class: 'Clase de tarea',
            byQueue: 'Por cola',
            byClass: 'Por clase de tarea',
            throughput: 'Intentos por minuto',
            empty: 'No se ejecutó nada en este periodo.',
        },
        widget: {
            pending: 'En espera',
            failed: 'Fallidas',
            open: 'Abrir colas',
        },
    },
    pt: {
        title: 'Filas',
        menuGroup: 'Operações',
        loading: 'A carregar…',
        none: '—',
        percent: '{value}%',
        noAccess: 'A sua conta não pode ver o painel de filas.',
        tabs: {
            overview: 'Visão geral',
            failed: 'Tarefas com falha',
            metrics: 'Métricas',
        },
        actions: {
            cancel: 'Cancelar',
            confirm: 'Confirmar',
            refresh: 'Atualizar',
            back: 'Voltar',
        },
        errors: {
            forbidden: 'A sua conta não pode fazer isso.',
            notFound: 'Já não existe.',
            conflict: 'O servidor recusou: {detail}',
            invalid: 'O pedido foi recusado: {detail}',
            failed: 'Algo correu mal: {detail}',
        },
        overview: {
            queue: 'Fila',
            size: 'Em espera',
            failed: 'Com falha',
            unknownSize: 'desconhecido',
            empty: 'Nenhuma fila está a ser monitorizada.',
            clear: 'Limpar',
            clearUnavailable: 'Este backend de filas não pode descartar as tarefas em espera.',
            clearTitle: 'Limpar "{queue}"?',
            clearText: 'Todas as tarefas em espera em "{queue}" são descartadas. Não é possível anular.',
            cleared: 'Foram descartadas {count} tarefa(s) de "{queue}".',
            clearUnsupported: 'Este backend de filas não pode descartar as tarefas em espera, por isso "{queue}" não foi limpa.',
            clearInvalid: '"{queue}" não foi limpa: {detail}',
        },
        failed: {
            filters: {
                queue: 'Fila',
                allQueues: 'Todas as filas',
                class: 'Classe da tarefa',
                from: 'Falha desde',
                to: 'Falha até',
                apply: 'Aplicar',
                reset: 'Repor',
            },
            select: 'Selecionar {id}',
            id: 'ID',
            class: 'Classe da tarefa',
            queue: 'Fila',
            attempts: 'Tentativas',
            attemptsOf: '{attempts} de {max}',
            exception: 'Exceção',
            failedAt: 'Falhou em',
            empty: 'Nenhuma tarefa com falha corresponde.',
            loadMore: 'Carregar mais',
            open: 'Detalhes',
            retry: 'Repetir',
            retrySelected: 'Repetir selecionadas ({count})',
            retryLimit: 'No máximo {max} tarefas são repetidas de cada vez.',
            retryTitle: 'Repetir {count} tarefa(s)?',
            retryText: 'Cada tarefa é colocada de novo na sua fila e o respetivo registo de falha é removido.',
            retried: '{retried} repetidas; {notFound} não encontradas; {failed} com falha.',
            forget: 'Esquecer',
            forgetTitle: 'Esquecer esta tarefa com falha?',
            forgetText: 'O registo é eliminado sem repetir a tarefa.',
            forgotten: 'A tarefa com falha foi esquecida.',
            prune: 'Limpar antigas',
            pruneDays: 'Mais antigas que (dias)',
            pruneTitle: 'Limpar as tarefas com falha com mais de {days} dia(s)?',
            pruneText: 'Todos os registos de falha mais antigos são eliminados, em todas as filas.',
            pruned: 'Foram eliminados {count} registo(s) de falha.',
            detail: {
                title: 'Tarefa com falha {id}',
                connection: 'Ligação',
                message: 'Mensagem',
                trace: 'Rasto de pilha',
                noTrace: 'Não é registado rasto de pilha para tarefas com falha.',
                args: 'Argumentos',
            },
        },
        metrics: {
            queue: 'Fila',
            allQueues: 'Todas as filas',
            window: 'Período',
            windows: {
                m15: 'Últimos 15 minutos',
                m60: 'Última hora',
                m360: 'Últimas 6 horas',
                m1440: 'Últimas 24 horas',
                m10080: 'Últimos 7 dias',
            },
            succeeded: 'Com sucesso',
            released: 'Libertadas',
            failed: 'Com falha',
            failureRate: 'Taxa de falhas',
            averageDuration: 'Média (ms)',
            maxDuration: 'Mais longa (ms)',
            lastProcessed: 'Último processamento',
            class: 'Classe da tarefa',
            byQueue: 'Por fila',
            byClass: 'Por classe da tarefa',
            throughput: 'Tentativas por minuto',
            empty: 'Nada foi executado neste período.',
        },
        widget: {
            pending: 'Em espera',
            failed: 'Com falha',
            open: 'Abrir filas',
        },
    },
    'pt-BR': {
        title: 'Filas',
        menuGroup: 'Operações',
        loading: 'Carregando…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Sua conta não pode ver o painel de filas.',
        tabs: {
            overview: 'Visão geral',
            failed: 'Tarefas com falha',
            metrics: 'Métricas',
        },
        actions: {
            cancel: 'Cancelar',
            confirm: 'Confirmar',
            refresh: 'Atualizar',
            back: 'Voltar',
        },
        errors: {
            forbidden: 'Sua conta não pode fazer isso.',
            notFound: 'Não existe mais.',
            conflict: 'O servidor recusou: {detail}',
            invalid: 'A solicitação foi recusada: {detail}',
            failed: 'Algo deu errado: {detail}',
        },
        overview: {
            queue: 'Fila',
            size: 'Aguardando',
            failed: 'Com falha',
            unknownSize: 'desconhecido',
            empty: 'Nenhuma fila está sendo monitorada.',
            clear: 'Limpar',
            clearUnavailable: 'Este backend de filas não pode descartar as tarefas aguardando.',
            clearTitle: 'Limpar "{queue}"?',
            clearText: 'Todas as tarefas aguardando em "{queue}" serão descartadas. Isso não pode ser desfeito.',
            cleared: '{count} tarefa(s) descartada(s) de "{queue}".',
            clearUnsupported: 'Este backend de filas não pode descartar as tarefas aguardando, então "{queue}" não foi limpa.',
            clearInvalid: '"{queue}" não foi limpa: {detail}',
        },
        failed: {
            filters: {
                queue: 'Fila',
                allQueues: 'Todas as filas',
                class: 'Classe da tarefa',
                from: 'Falha a partir de',
                to: 'Falha até',
                apply: 'Aplicar',
                reset: 'Redefinir',
            },
            select: 'Selecionar {id}',
            id: 'ID',
            class: 'Classe da tarefa',
            queue: 'Fila',
            attempts: 'Tentativas',
            attemptsOf: '{attempts} de {max}',
            exception: 'Exceção',
            failedAt: 'Falhou em',
            empty: 'Nenhuma tarefa com falha corresponde.',
            loadMore: 'Carregar mais',
            open: 'Detalhes',
            retry: 'Tentar novamente',
            retrySelected: 'Tentar novamente as selecionadas ({count})',
            retryLimit: 'No máximo {max} tarefas são repetidas por vez.',
            retryTitle: 'Tentar novamente {count} tarefa(s)?',
            retryText: 'Cada tarefa volta para a sua fila e o registro de falha dela é removido.',
            retried: '{retried} repetidas; {notFound} não encontradas; {failed} com falha.',
            forget: 'Esquecer',
            forgetTitle: 'Esquecer esta tarefa com falha?',
            forgetText: 'O registro é excluído sem repetir a tarefa.',
            forgotten: 'A tarefa com falha foi esquecida.',
            prune: 'Limpar antigas',
            pruneDays: 'Mais antigas que (dias)',
            pruneTitle: 'Limpar as tarefas com falha com mais de {days} dia(s)?',
            pruneText: 'Todo registro de falha mais antigo é excluído, em todas as filas.',
            pruned: '{count} registro(s) de falha excluído(s).',
            detail: {
                title: 'Tarefa com falha {id}',
                connection: 'Conexão',
                message: 'Mensagem',
                trace: 'Stack trace',
                noTrace: 'Nenhum stack trace é registrado para tarefas com falha.',
                args: 'Argumentos',
            },
        },
        metrics: {
            queue: 'Fila',
            allQueues: 'Todas as filas',
            window: 'Período',
            windows: {
                m15: 'Últimos 15 minutos',
                m60: 'Última hora',
                m360: 'Últimas 6 horas',
                m1440: 'Últimas 24 horas',
                m10080: 'Últimos 7 dias',
            },
            succeeded: 'Com sucesso',
            released: 'Liberadas',
            failed: 'Com falha',
            failureRate: 'Taxa de falhas',
            averageDuration: 'Média (ms)',
            maxDuration: 'Mais longa (ms)',
            lastProcessed: 'Último processamento',
            class: 'Classe da tarefa',
            byQueue: 'Por fila',
            byClass: 'Por classe da tarefa',
            throughput: 'Tentativas por minuto',
            empty: 'Nada foi executado neste período.',
        },
        widget: {
            pending: 'Aguardando',
            failed: 'Com falha',
            open: 'Abrir filas',
        },
    },
    pl: {
        title: 'Kolejki',
        menuGroup: 'Operacje',
        loading: 'Ładowanie…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Twoje konto nie może wyświetlać pulpitu kolejek.',
        tabs: {
            overview: 'Przegląd',
            failed: 'Nieudane zadania',
            metrics: 'Metryki',
        },
        actions: {
            cancel: 'Anuluj',
            confirm: 'Potwierdź',
            refresh: 'Odśwież',
            back: 'Wstecz',
        },
        errors: {
            forbidden: 'Twoje konto nie może tego zrobić.',
            notFound: 'Już nie istnieje.',
            conflict: 'Serwer to odrzucił: {detail}',
            invalid: 'Żądanie zostało odrzucone: {detail}',
            failed: 'Coś poszło nie tak: {detail}',
        },
        overview: {
            queue: 'Kolejka',
            size: 'Oczekujące',
            failed: 'Nieudane',
            unknownSize: 'nieznane',
            empty: 'Żadne kolejki nie są monitorowane.',
            clear: 'Wyczyść',
            clearUnavailable: 'Ten backend kolejek nie może odrzucać oczekujących zadań.',
            clearTitle: 'Wyczyścić „{queue}”?',
            clearText: 'Wszystkie zadania oczekujące w „{queue}” zostaną odrzucone. Tej operacji nie można cofnąć.',
            cleared: 'Usunięto zadania z „{queue}”: {count}.',
            clearUnsupported: 'Ten backend kolejek nie może odrzucać oczekujących zadań, więc „{queue}” nie została wyczyszczona.',
            clearInvalid: '„{queue}” nie została wyczyszczona: {detail}',
        },
        failed: {
            filters: {
                queue: 'Kolejka',
                allQueues: 'Wszystkie kolejki',
                class: 'Klasa zadania',
                from: 'Nieudane od',
                to: 'Nieudane do',
                apply: 'Zastosuj',
                reset: 'Resetuj',
            },
            select: 'Wybierz {id}',
            id: 'ID',
            class: 'Klasa zadania',
            queue: 'Kolejka',
            attempts: 'Próby',
            attemptsOf: '{attempts} z {max}',
            exception: 'Wyjątek',
            failedAt: 'Niepowodzenie',
            empty: 'Brak pasujących nieudanych zadań.',
            loadMore: 'Załaduj więcej',
            open: 'Szczegóły',
            retry: 'Ponów',
            retrySelected: 'Ponów wybrane ({count})',
            retryLimit: 'Jednorazowo ponawianych jest co najwyżej {max} zadań.',
            retryTitle: 'Ponowić zadania: {count}?',
            retryText: 'Każde zadanie wraca do swojej kolejki, a jego rekord niepowodzenia zostaje usunięty.',
            retried: 'Ponowiono: {retried}; nie znaleziono: {notFound}; nieudane: {failed}.',
            forget: 'Zapomnij',
            forgetTitle: 'Zapomnieć to nieudane zadanie?',
            forgetText: 'Rekord zostanie usunięty bez ponawiania zadania.',
            forgotten: 'Nieudane zadanie zostało zapomniane.',
            prune: 'Wyczyść stare',
            pruneDays: 'Starsze niż (dni)',
            pruneTitle: 'Wyczyścić nieudane zadania starsze niż {days} dni?',
            pruneText: 'Każdy starszy rekord niepowodzenia zostanie usunięty we wszystkich kolejkach.',
            pruned: 'Usunięto rekordy niepowodzeń: {count}.',
            detail: {
                title: 'Nieudane zadanie {id}',
                connection: 'Połączenie',
                message: 'Komunikat',
                trace: 'Ślad stosu',
                noTrace: 'Dla nieudanych zadań nie jest zapisywany ślad stosu.',
                args: 'Argumenty',
            },
        },
        metrics: {
            queue: 'Kolejka',
            allQueues: 'Wszystkie kolejki',
            window: 'Okres',
            windows: {
                m15: 'Ostatnie 15 minut',
                m60: 'Ostatnia godzina',
                m360: 'Ostatnie 6 godzin',
                m1440: 'Ostatnie 24 godziny',
                m10080: 'Ostatnie 7 dni',
            },
            succeeded: 'Udane',
            released: 'Zwolnione',
            failed: 'Nieudane',
            failureRate: 'Odsetek niepowodzeń',
            averageDuration: 'Średnia (ms)',
            maxDuration: 'Najdłuższe (ms)',
            lastProcessed: 'Ostatnio przetworzone',
            class: 'Klasa zadania',
            byQueue: 'Według kolejki',
            byClass: 'Według klasy zadania',
            throughput: 'Próby na minutę',
            empty: 'W tym okresie nic się nie wykonało.',
        },
        widget: {
            pending: 'Oczekujące',
            failed: 'Nieudane',
            open: 'Otwórz kolejki',
        },
    },
    tr: {
        title: 'Kuyruklar',
        menuGroup: 'İşlemler',
        loading: 'Yükleniyor…',
        none: '—',
        percent: '%{value}',
        noAccess: 'Hesabınız kuyruk panelini görüntüleyemez.',
        tabs: {
            overview: 'Genel bakış',
            failed: 'Başarısız işler',
            metrics: 'Metrikler',
        },
        actions: {
            cancel: 'İptal',
            confirm: 'Onayla',
            refresh: 'Yenile',
            back: 'Geri',
        },
        errors: {
            forbidden: 'Hesabınız bunu yapamaz.',
            notFound: 'Artık mevcut değil.',
            conflict: 'Sunucu bunu reddetti: {detail}',
            invalid: 'İstek reddedildi: {detail}',
            failed: 'Bir şeyler ters gitti: {detail}',
        },
        overview: {
            queue: 'Kuyruk',
            size: 'Bekleyen',
            failed: 'Başarısız',
            unknownSize: 'bilinmiyor',
            empty: 'İzlenen kuyruk yok.',
            clear: 'Temizle',
            clearUnavailable: 'Bu kuyruk altyapısı bekleyen işleri silemez.',
            clearTitle: '"{queue}" temizlensin mi?',
            clearText: '"{queue}" içinde bekleyen tüm işler silinir. Bu işlem geri alınamaz.',
            cleared: '"{queue}" içinden {count} iş silindi.',
            clearUnsupported: 'Bu kuyruk altyapısı bekleyen işleri silemez, bu yüzden "{queue}" temizlenmedi.',
            clearInvalid: '"{queue}" temizlenmedi: {detail}',
        },
        failed: {
            filters: {
                queue: 'Kuyruk',
                allQueues: 'Tüm kuyruklar',
                class: 'İş sınıfı',
                from: 'Başarısızlık başlangıcı',
                to: 'Başarısızlık bitişi',
                apply: 'Uygula',
                reset: 'Sıfırla',
            },
            select: '{id} seç',
            id: 'Kimlik',
            class: 'İş sınıfı',
            queue: 'Kuyruk',
            attempts: 'Deneme',
            attemptsOf: '{attempts} / {max}',
            exception: 'İstisna',
            failedAt: 'Başarısız olduğu zaman',
            empty: 'Eşleşen başarısız iş yok.',
            loadMore: 'Daha fazla yükle',
            open: 'Ayrıntılar',
            retry: 'Yeniden dene',
            retrySelected: 'Seçilenleri yeniden dene ({count})',
            retryLimit: 'Aynı anda en fazla {max} iş yeniden denenir.',
            retryTitle: '{count} iş yeniden denensin mi?',
            retryText: 'Her iş kendi kuyruğuna geri konur ve başarısızlık kaydı kaldırılır.',
            retried: '{retried} yeniden denendi; {notFound} bulunamadı; {failed} başarısız oldu.',
            forget: 'Unut',
            forgetTitle: 'Bu başarısız iş unutulsun mu?',
            forgetText: 'Kayıt, iş yeniden denenmeden silinir.',
            forgotten: 'Başarısız iş unutuldu.',
            prune: 'Eskileri temizle',
            pruneDays: 'Şundan eski (gün)',
            pruneTitle: '{days} günden eski başarısız işler temizlensin mi?',
            pruneText: 'Bundan eski her başarısızlık kaydı, tüm kuyruklarda silinir.',
            pruned: '{count} başarısızlık kaydı silindi.',
            detail: {
                title: 'Başarısız iş {id}',
                connection: 'Bağlantı',
                message: 'İleti',
                trace: 'Yığın izi',
                noTrace: 'Başarısız işler için yığın izi kaydedilmez.',
                args: 'Bağımsız değişkenler',
            },
        },
        metrics: {
            queue: 'Kuyruk',
            allQueues: 'Tüm kuyruklar',
            window: 'Aralık',
            windows: {
                m15: 'Son 15 dakika',
                m60: 'Son 1 saat',
                m360: 'Son 6 saat',
                m1440: 'Son 24 saat',
                m10080: 'Son 7 gün',
            },
            succeeded: 'Başarılı',
            released: 'Geri bırakılan',
            failed: 'Başarısız',
            failureRate: 'Başarısızlık oranı',
            averageDuration: 'Ortalama (ms)',
            maxDuration: 'En uzun (ms)',
            lastProcessed: 'Son işlenen',
            class: 'İş sınıfı',
            byQueue: 'Kuyruğa göre',
            byClass: 'İş sınıfına göre',
            throughput: 'Dakika başına deneme',
            empty: 'Bu aralıkta hiçbir şey çalışmadı.',
        },
        widget: {
            pending: 'Bekleyen',
            failed: 'Başarısız',
            open: 'Kuyrukları aç',
        },
    },
    ru: {
        title: 'Очереди',
        menuGroup: 'Операции',
        loading: 'Загрузка…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Ваша учётная запись не может просматривать панель очередей.',
        tabs: {
            overview: 'Обзор',
            failed: 'Неудачные задачи',
            metrics: 'Метрики',
        },
        actions: {
            cancel: 'Отмена',
            confirm: 'Подтвердить',
            refresh: 'Обновить',
            back: 'Назад',
        },
        errors: {
            forbidden: 'Ваша учётная запись не может этого делать.',
            notFound: 'Его больше не существует.',
            conflict: 'Сервер отклонил запрос: {detail}',
            invalid: 'Запрос отклонён: {detail}',
            failed: 'Что-то пошло не так: {detail}',
        },
        overview: {
            queue: 'Очередь',
            size: 'Ожидают',
            failed: 'Неудачные',
            unknownSize: 'неизвестно',
            empty: 'Ни одна очередь не отслеживается.',
            clear: 'Очистить',
            clearUnavailable: 'Этот бэкенд очередей не может удалять ожидающие задачи.',
            clearTitle: 'Очистить «{queue}»?',
            clearText: 'Все задачи, ожидающие в «{queue}», будут удалены. Это действие нельзя отменить.',
            cleared: 'Из «{queue}» удалено задач: {count}.',
            clearUnsupported: 'Этот бэкенд очередей не может удалять ожидающие задачи, поэтому «{queue}» не очищена.',
            clearInvalid: '«{queue}» не очищена: {detail}',
        },
        failed: {
            filters: {
                queue: 'Очередь',
                allQueues: 'Все очереди',
                class: 'Класс задачи',
                from: 'Неудача с',
                to: 'Неудача по',
                apply: 'Применить',
                reset: 'Сбросить',
            },
            select: 'Выбрать {id}',
            id: 'ID',
            class: 'Класс задачи',
            queue: 'Очередь',
            attempts: 'Попытки',
            attemptsOf: '{attempts} из {max}',
            exception: 'Исключение',
            failedAt: 'Время неудачи',
            empty: 'Подходящих неудачных задач нет.',
            loadMore: 'Загрузить ещё',
            open: 'Подробности',
            retry: 'Повторить',
            retrySelected: 'Повторить выбранные ({count})',
            retryLimit: 'За один раз повторяется не более {max} задач.',
            retryTitle: 'Повторить задачи ({count})?',
            retryText: 'Каждая задача возвращается в свою очередь, а запись о неудаче удаляется.',
            retried: 'Повторено: {retried}; не найдено: {notFound}; неудачно: {failed}.',
            forget: 'Забыть',
            forgetTitle: 'Забыть эту неудачную задачу?',
            forgetText: 'Запись удаляется без повторного выполнения задачи.',
            forgotten: 'Неудачная задача забыта.',
            prune: 'Очистить старые',
            pruneDays: 'Старше (дней)',
            pruneTitle: 'Удалить неудачные задачи старше {days} дн.?',
            pruneText: 'Все более старые записи о неудачах будут удалены во всех очередях.',
            pruned: 'Удалено записей о неудачах: {count}.',
            detail: {
                title: 'Неудачная задача {id}',
                connection: 'Подключение',
                message: 'Сообщение',
                trace: 'Трассировка стека',
                noTrace: 'Для неудачных задач трассировка стека не записывается.',
                args: 'Аргументы',
            },
        },
        metrics: {
            queue: 'Очередь',
            allQueues: 'Все очереди',
            window: 'Период',
            windows: {
                m15: 'Последние 15 минут',
                m60: 'Последний час',
                m360: 'Последние 6 часов',
                m1440: 'Последние 24 часа',
                m10080: 'Последние 7 дней',
            },
            succeeded: 'Успешно',
            released: 'Возвращено',
            failed: 'Неудачно',
            failureRate: 'Доля неудач',
            averageDuration: 'Среднее (мс)',
            maxDuration: 'Самая долгая (мс)',
            lastProcessed: 'Последняя обработка',
            class: 'Класс задачи',
            byQueue: 'По очередям',
            byClass: 'По классам задач',
            throughput: 'Попыток в минуту',
            empty: 'В этом периоде ничего не выполнялось.',
        },
        widget: {
            pending: 'Ожидают',
            failed: 'Неудачные',
            open: 'Открыть очереди',
        },
    },
    uk: {
        title: 'Черги',
        menuGroup: 'Операції',
        loading: 'Завантаження…',
        none: '—',
        percent: '{value}%',
        noAccess: 'Ваш обліковий запис не може переглядати панель черг.',
        tabs: {
            overview: 'Огляд',
            failed: 'Невдалі завдання',
            metrics: 'Метрики',
        },
        actions: {
            cancel: 'Скасувати',
            confirm: 'Підтвердити',
            refresh: 'Оновити',
            back: 'Назад',
        },
        errors: {
            forbidden: 'Ваш обліковий запис не може цього робити.',
            notFound: 'Його більше не існує.',
            conflict: 'Сервер відхилив запит: {detail}',
            invalid: 'Запит відхилено: {detail}',
            failed: 'Щось пішло не так: {detail}',
        },
        overview: {
            queue: 'Черга',
            size: 'Очікують',
            failed: 'Невдалі',
            unknownSize: 'невідомо',
            empty: 'Жодна черга не відстежується.',
            clear: 'Очистити',
            clearUnavailable: 'Цей бекенд черг не може видаляти завдання, що очікують.',
            clearTitle: 'Очистити «{queue}»?',
            clearText: 'Усі завдання, що очікують у «{queue}», буде видалено. Цю дію неможливо скасувати.',
            cleared: 'З «{queue}» видалено завдань: {count}.',
            clearUnsupported: 'Цей бекенд черг не може видаляти завдання, що очікують, тому «{queue}» не очищено.',
            clearInvalid: '«{queue}» не очищено: {detail}',
        },
        failed: {
            filters: {
                queue: 'Черга',
                allQueues: 'Усі черги',
                class: 'Клас завдання',
                from: 'Невдача від',
                to: 'Невдача до',
                apply: 'Застосувати',
                reset: 'Скинути',
            },
            select: 'Вибрати {id}',
            id: 'ID',
            class: 'Клас завдання',
            queue: 'Черга',
            attempts: 'Спроби',
            attemptsOf: '{attempts} з {max}',
            exception: 'Виняток',
            failedAt: 'Час невдачі',
            empty: 'Немає відповідних невдалих завдань.',
            loadMore: 'Завантажити ще',
            open: 'Деталі',
            retry: 'Повторити',
            retrySelected: 'Повторити вибрані ({count})',
            retryLimit: 'За один раз повторюється не більше ніж {max} завдань.',
            retryTitle: 'Повторити завдання ({count})?',
            retryText: 'Кожне завдання повертається у свою чергу, а запис про невдачу видаляється.',
            retried: 'Повторено: {retried}; не знайдено: {notFound}; невдало: {failed}.',
            forget: 'Забути',
            forgetTitle: 'Забути це невдале завдання?',
            forgetText: 'Запис видаляється без повторного виконання завдання.',
            forgotten: 'Невдале завдання забуто.',
            prune: 'Очистити старі',
            pruneDays: 'Старші за (днів)',
            pruneTitle: 'Видалити невдалі завдання старші за {days} дн.?',
            pruneText: 'Усі старіші записи про невдачі буде видалено в усіх чергах.',
            pruned: 'Видалено записів про невдачі: {count}.',
            detail: {
                title: 'Невдале завдання {id}',
                connection: 'Підключення',
                message: 'Повідомлення',
                trace: 'Трасування стека',
                noTrace: 'Для невдалих завдань трасування стека не записується.',
                args: 'Аргументи',
            },
        },
        metrics: {
            queue: 'Черга',
            allQueues: 'Усі черги',
            window: 'Період',
            windows: {
                m15: 'Останні 15 хвилин',
                m60: 'Остання година',
                m360: 'Останні 6 годин',
                m1440: 'Останні 24 години',
                m10080: 'Останні 7 днів',
            },
            succeeded: 'Успішно',
            released: 'Повернуто',
            failed: 'Невдало',
            failureRate: 'Частка невдач',
            averageDuration: 'Середнє (мс)',
            maxDuration: 'Найдовше (мс)',
            lastProcessed: 'Остання обробка',
            class: 'Клас завдання',
            byQueue: 'За чергами',
            byClass: 'За класами завдань',
            throughput: 'Спроб за хвилину',
            empty: 'У цьому періоді нічого не виконувалось.',
        },
        widget: {
            pending: 'Очікують',
            failed: 'Невдалі',
            open: 'Відкрити черги',
        },
    },
    he: {
        title: 'תורים',
        menuGroup: 'תפעול',
        loading: 'טוען…',
        none: '—',
        percent: '{value}%',
        noAccess: 'לחשבון שלך אין הרשאה לצפות בלוח הבקרה של התורים.',
        tabs: {
            overview: 'סקירה',
            failed: 'משימות שנכשלו',
            metrics: 'מדדים',
        },
        actions: {
            cancel: 'ביטול',
            confirm: 'אישור',
            refresh: 'רענון',
            back: 'חזרה',
        },
        errors: {
            forbidden: 'לחשבון שלך אין הרשאה לבצע פעולה זו.',
            notFound: 'הפריט כבר לא קיים.',
            conflict: 'השרת דחה זאת: {detail}',
            invalid: 'הבקשה נדחתה: {detail}',
            failed: 'משהו השתבש: {detail}',
        },
        overview: {
            queue: 'תור',
            size: 'ממתינות',
            failed: 'נכשלו',
            unknownSize: 'לא ידוע',
            empty: 'אין תורים במעקב.',
            clear: 'ניקוי',
            clearUnavailable: 'מנגנון התורים הזה אינו יכול להשליך משימות ממתינות.',
            clearTitle: 'לנקות את "{queue}"?',
            clearText: 'כל המשימות הממתינות ב-"{queue}" יושלכו. אי אפשר לבטל פעולה זו.',
            cleared: '{count} משימות הוסרו מ-"{queue}".',
            clearUnsupported: 'מנגנון התורים הזה אינו יכול להשליך משימות ממתינות, ולכן "{queue}" לא נוקה.',
            clearInvalid: '"{queue}" לא נוקה: {detail}',
        },
        failed: {
            filters: {
                queue: 'תור',
                allQueues: 'כל התורים',
                class: 'מחלקת משימה',
                from: 'נכשלו מתאריך',
                to: 'נכשלו עד תאריך',
                apply: 'החלה',
                reset: 'איפוס',
            },
            select: 'בחירת {id}',
            id: 'מזהה',
            class: 'מחלקת משימה',
            queue: 'תור',
            attempts: 'ניסיונות',
            attemptsOf: '{attempts} מתוך {max}',
            exception: 'חריגה',
            failedAt: 'זמן הכישלון',
            empty: 'אין משימות שנכשלו שתואמות.',
            loadMore: 'טעינת עוד',
            open: 'פרטים',
            retry: 'ניסיון חוזר',
            retrySelected: 'ניסיון חוזר לנבחרות ({count})',
            retryLimit: 'מנסים שוב לכל היותר {max} משימות בכל פעם.',
            retryTitle: 'לנסות שוב {count} משימות?',
            retryText: 'כל משימה מוחזרת לתור שלה ורשומת הכישלון שלה נמחקת.',
            retried: 'נוסו שוב: {retried}; לא נמצאו: {notFound}; נכשלו: {failed}.',
            forget: 'שכחה',
            forgetTitle: 'לשכוח את המשימה שנכשלה?',
            forgetText: 'הרשומה נמחקת בלי לנסות שוב את המשימה.',
            forgotten: 'המשימה שנכשלה נשכחה.',
            prune: 'ניקוי ישנות',
            pruneDays: 'ישנות מ-(ימים)',
            pruneTitle: 'לנקות משימות שנכשלו ישנות מ-{days} ימים?',
            pruneText: 'כל רשומת כישלון ישנה יותר נמחקת, בכל התורים.',
            pruned: 'נמחקו {count} רשומות כישלון.',
            detail: {
                title: 'משימה שנכשלה {id}',
                connection: 'חיבור',
                message: 'הודעה',
                trace: 'מעקב מחסנית',
                noTrace: 'לא נרשם מעקב מחסנית עבור משימות שנכשלו.',
                args: 'ארגומנטים',
            },
        },
        metrics: {
            queue: 'תור',
            allQueues: 'כל התורים',
            window: 'חלון זמן',
            windows: {
                m15: '15 הדקות האחרונות',
                m60: 'השעה האחרונה',
                m360: '6 השעות האחרונות',
                m1440: '24 השעות האחרונות',
                m10080: '7 הימים האחרונים',
            },
            succeeded: 'הצליחו',
            released: 'שוחררו',
            failed: 'נכשלו',
            failureRate: 'שיעור כישלונות',
            averageDuration: 'ממוצע (מילישניות)',
            maxDuration: 'הארוכה ביותר (מילישניות)',
            lastProcessed: 'עובדה לאחרונה',
            class: 'מחלקת משימה',
            byQueue: 'לפי תור',
            byClass: 'לפי מחלקת משימה',
            throughput: 'ניסיונות בדקה',
            empty: 'שום דבר לא רץ בחלון הזה.',
        },
        widget: {
            pending: 'ממתינות',
            failed: 'נכשלו',
            open: 'פתיחת התורים',
        },
    },
    ar: {
        title: 'قوائم الانتظار',
        menuGroup: 'العمليات',
        loading: 'جارٍ التحميل…',
        none: '—',
        percent: '{value}%',
        noAccess: 'لا يمكن لحسابك عرض لوحة قوائم الانتظار.',
        tabs: {
            overview: 'نظرة عامة',
            failed: 'المهام الفاشلة',
            metrics: 'المقاييس',
        },
        actions: {
            cancel: 'إلغاء',
            confirm: 'تأكيد',
            refresh: 'تحديث',
            back: 'رجوع',
        },
        errors: {
            forbidden: 'لا يمكن لحسابك القيام بذلك.',
            notFound: 'لم يعد موجودًا.',
            conflict: 'رفض الخادم ذلك: {detail}',
            invalid: 'تم رفض الطلب: {detail}',
            failed: 'حدث خطأ ما: {detail}',
        },
        overview: {
            queue: 'قائمة الانتظار',
            size: 'قيد الانتظار',
            failed: 'فاشلة',
            unknownSize: 'غير معروف',
            empty: 'لا توجد قوائم انتظار قيد المراقبة.',
            clear: 'تفريغ',
            clearUnavailable: 'لا يمكن لواجهة قائمة الانتظار هذه تجاهل المهام المنتظرة.',
            clearTitle: 'تفريغ "{queue}"؟',
            clearText: 'سيتم تجاهل كل المهام المنتظرة في "{queue}". لا يمكن التراجع عن ذلك.',
            cleared: 'تم تجاهل {count} مهمة من "{queue}".',
            clearUnsupported: 'لا يمكن لواجهة قائمة الانتظار هذه تجاهل المهام المنتظرة، لذا لم يتم تفريغ "{queue}".',
            clearInvalid: 'لم يتم تفريغ "{queue}": {detail}',
        },
        failed: {
            filters: {
                queue: 'قائمة الانتظار',
                allQueues: 'كل قوائم الانتظار',
                class: 'فئة المهمة',
                from: 'فشلت منذ',
                to: 'فشلت حتى',
                apply: 'تطبيق',
                reset: 'إعادة تعيين',
            },
            select: 'تحديد {id}',
            id: 'المعرّف',
            class: 'فئة المهمة',
            queue: 'قائمة الانتظار',
            attempts: 'المحاولات',
            attemptsOf: '{attempts} من {max}',
            exception: 'الاستثناء',
            failedAt: 'وقت الفشل',
            empty: 'لا توجد مهام فاشلة مطابقة.',
            loadMore: 'تحميل المزيد',
            open: 'التفاصيل',
            retry: 'إعادة المحاولة',
            retrySelected: 'إعادة محاولة المحدد ({count})',
            retryLimit: 'تتم إعادة محاولة {max} مهمة كحد أقصى في المرة الواحدة.',
            retryTitle: 'إعادة محاولة {count} مهمة؟',
            retryText: 'تُعاد كل مهمة إلى قائمة انتظارها ويُحذف سجل فشلها.',
            retried: 'أُعيدت محاولة {retried}؛ لم يُعثر على {notFound}؛ فشلت {failed}.',
            forget: 'نسيان',
            forgetTitle: 'نسيان هذه المهمة الفاشلة؟',
            forgetText: 'يُحذف السجل دون إعادة محاولة المهمة.',
            forgotten: 'تم نسيان المهمة الفاشلة.',
            prune: 'تنظيف',
            pruneDays: 'أقدم من (أيام)',
            pruneTitle: 'تنظيف المهام الفاشلة الأقدم من {days} يوم؟',
            pruneText: 'يُحذف كل سجل فشل أقدم من ذلك، في كل قوائم الانتظار.',
            pruned: 'تم حذف {count} سجل فشل.',
            detail: {
                title: 'المهمة الفاشلة {id}',
                connection: 'الاتصال',
                message: 'الرسالة',
                trace: 'تتبع المكدس',
                noTrace: 'لا يُسجَّل تتبع للمكدس للمهام الفاشلة.',
                args: 'المعاملات',
            },
        },
        metrics: {
            queue: 'قائمة الانتظار',
            allQueues: 'كل قوائم الانتظار',
            window: 'النافذة الزمنية',
            windows: {
                m15: 'آخر 15 دقيقة',
                m60: 'آخر ساعة',
                m360: 'آخر 6 ساعات',
                m1440: 'آخر 24 ساعة',
                m10080: 'آخر 7 أيام',
            },
            succeeded: 'الناجحة',
            released: 'المُعادة',
            failed: 'الفاشلة',
            failureRate: 'معدل الفشل',
            averageDuration: 'المتوسط (مللي ثانية)',
            maxDuration: 'الأطول (مللي ثانية)',
            lastProcessed: 'آخر معالجة',
            class: 'فئة المهمة',
            byQueue: 'حسب قائمة الانتظار',
            byClass: 'حسب فئة المهمة',
            throughput: 'المحاولات في الدقيقة',
            empty: 'لم يُنفَّذ شيء في هذه النافذة.',
        },
        widget: {
            pending: 'قيد الانتظار',
            failed: 'فاشلة',
            open: 'فتح قوائم الانتظار',
        },
    },
    ko: {
        title: '대기열',
        menuGroup: '운영',
        loading: '불러오는 중…',
        none: '—',
        percent: '{value}%',
        noAccess: '이 계정은 대기열 대시보드를 볼 수 없습니다.',
        tabs: {
            overview: '개요',
            failed: '실패한 작업',
            metrics: '지표',
        },
        actions: {
            cancel: '취소',
            confirm: '확인',
            refresh: '새로 고침',
            back: '뒤로',
        },
        errors: {
            forbidden: '이 계정은 해당 작업을 할 수 없습니다.',
            notFound: '더 이상 존재하지 않습니다.',
            conflict: '서버가 거부했습니다: {detail}',
            invalid: '요청이 거부되었습니다: {detail}',
            failed: '문제가 발생했습니다: {detail}',
        },
        overview: {
            queue: '대기열',
            size: '대기 중',
            failed: '실패',
            unknownSize: '알 수 없음',
            empty: '감시 중인 대기열이 없습니다.',
            clear: '비우기',
            clearUnavailable: '이 대기열 백엔드는 대기 중인 작업을 삭제할 수 없습니다.',
            clearTitle: '"{queue}"을(를) 비울까요?',
            clearText: '"{queue}"에서 대기 중인 모든 작업이 삭제됩니다. 이 작업은 되돌릴 수 없습니다.',
            cleared: '"{queue}"에서 작업 {count}개를 삭제했습니다.',
            clearUnsupported: '이 대기열 백엔드는 대기 중인 작업을 삭제할 수 없어 "{queue}"을(를) 비우지 못했습니다.',
            clearInvalid: '"{queue}"을(를) 비우지 못했습니다: {detail}',
        },
        failed: {
            filters: {
                queue: '대기열',
                allQueues: '모든 대기열',
                class: '작업 클래스',
                from: '실패 시작',
                to: '실패 종료',
                apply: '적용',
                reset: '초기화',
            },
            select: '{id} 선택',
            id: 'ID',
            class: '작업 클래스',
            queue: '대기열',
            attempts: '시도',
            attemptsOf: '{max}회 중 {attempts}회',
            exception: '예외',
            failedAt: '실패 시각',
            empty: '일치하는 실패한 작업이 없습니다.',
            loadMore: '더 불러오기',
            open: '상세 정보',
            retry: '재시도',
            retrySelected: '선택 항목 재시도 ({count})',
            retryLimit: '한 번에 최대 {max}개의 작업을 재시도합니다.',
            retryTitle: '작업 {count}개를 재시도할까요?',
            retryText: '각 작업은 해당 대기열로 다시 들어가고 실패 기록은 삭제됩니다.',
            retried: '재시도 {retried}개, 찾을 수 없음 {notFound}개, 실패 {failed}개.',
            forget: '제거',
            forgetTitle: '이 실패한 작업을 제거할까요?',
            forgetText: '작업을 재시도하지 않고 기록만 삭제합니다.',
            forgotten: '실패한 작업을 제거했습니다.',
            prune: '정리',
            pruneDays: '경과 일수 기준',
            pruneTitle: '{days}일보다 오래된 실패한 작업을 정리할까요?',
            pruneText: '그보다 오래된 모든 실패 기록이 모든 대기열에서 삭제됩니다.',
            pruned: '실패 기록 {count}개를 삭제했습니다.',
            detail: {
                title: '실패한 작업 {id}',
                connection: '연결',
                message: '메시지',
                trace: '스택 트레이스',
                noTrace: '실패한 작업에는 스택 트레이스가 기록되지 않습니다.',
                args: '인수',
            },
        },
        metrics: {
            queue: '대기열',
            allQueues: '모든 대기열',
            window: '기간',
            windows: {
                m15: '최근 15분',
                m60: '최근 1시간',
                m360: '최근 6시간',
                m1440: '최근 24시간',
                m10080: '최근 7일',
            },
            succeeded: '성공',
            released: '반환됨',
            failed: '실패',
            failureRate: '실패율',
            averageDuration: '평균 (ms)',
            maxDuration: '최장 (ms)',
            lastProcessed: '마지막 처리',
            class: '작업 클래스',
            byQueue: '대기열별',
            byClass: '작업 클래스별',
            throughput: '분당 시도',
            empty: '이 기간에는 실행된 작업이 없습니다.',
        },
        widget: {
            pending: '대기 중',
            failed: '실패',
            open: '대기열 열기',
        },
    },
    ja: {
        title: 'キュー',
        menuGroup: '運用',
        loading: '読み込み中…',
        none: '—',
        percent: '{value}%',
        noAccess: 'このアカウントではキューのダッシュボードを表示できません。',
        tabs: {
            overview: '概要',
            failed: '失敗したジョブ',
            metrics: 'メトリクス',
        },
        actions: {
            cancel: 'キャンセル',
            confirm: '確認',
            refresh: '更新',
            back: '戻る',
        },
        errors: {
            forbidden: 'このアカウントではその操作を実行できません。',
            notFound: 'すでに存在しません。',
            conflict: 'サーバーに拒否されました: {detail}',
            invalid: 'リクエストが拒否されました: {detail}',
            failed: '問題が発生しました: {detail}',
        },
        overview: {
            queue: 'キュー',
            size: '待機中',
            failed: '失敗',
            unknownSize: '不明',
            empty: '監視中のキューはありません。',
            clear: 'クリア',
            clearUnavailable: 'このキューバックエンドは待機中のジョブを破棄できません。',
            clearTitle: '「{queue}」をクリアしますか？',
            clearText: '「{queue}」で待機中のジョブはすべて破棄されます。この操作は元に戻せません。',
            cleared: '「{queue}」から {count} 件のジョブを破棄しました。',
            clearUnsupported: 'このキューバックエンドは待機中のジョブを破棄できないため、「{queue}」はクリアされませんでした。',
            clearInvalid: '「{queue}」をクリアできませんでした: {detail}',
        },
        failed: {
            filters: {
                queue: 'キュー',
                allQueues: 'すべてのキュー',
                class: 'ジョブクラス',
                from: '失敗日時（開始）',
                to: '失敗日時（終了）',
                apply: '適用',
                reset: 'リセット',
            },
            select: '{id} を選択',
            id: 'ID',
            class: 'ジョブクラス',
            queue: 'キュー',
            attempts: '試行回数',
            attemptsOf: '{max} 回中 {attempts} 回',
            exception: '例外',
            failedAt: '失敗日時',
            empty: '一致する失敗したジョブはありません。',
            loadMore: 'さらに読み込む',
            open: '詳細',
            retry: '再試行',
            retrySelected: '選択したジョブを再試行 ({count})',
            retryLimit: '一度に再試行できるジョブは最大 {max} 件です。',
            retryTitle: '{count} 件のジョブを再試行しますか？',
            retryText: '各ジョブはキューに戻され、失敗の記録は削除されます。',
            retried: '再試行 {retried} 件、見つからない {notFound} 件、失敗 {failed} 件。',
            forget: '削除',
            forgetTitle: 'この失敗したジョブを削除しますか？',
            forgetText: 'ジョブを再試行せずに記録を削除します。',
            forgotten: '失敗したジョブを削除しました。',
            prune: '古い記録を削除',
            pruneDays: '経過日数',
            pruneTitle: '{days} 日より古い失敗したジョブを削除しますか？',
            pruneText: 'それより古い失敗の記録は、すべてのキューから削除されます。',
            pruned: '失敗の記録を {count} 件削除しました。',
            detail: {
                title: '失敗したジョブ {id}',
                connection: '接続',
                message: 'メッセージ',
                trace: 'スタックトレース',
                noTrace: '失敗したジョブのスタックトレースは記録されません。',
                args: '引数',
            },
        },
        metrics: {
            queue: 'キュー',
            allQueues: 'すべてのキュー',
            window: '期間',
            windows: {
                m15: '過去 15 分',
                m60: '過去 1 時間',
                m360: '過去 6 時間',
                m1440: '過去 24 時間',
                m10080: '過去 7 日間',
            },
            succeeded: '成功',
            released: '解放',
            failed: '失敗',
            failureRate: '失敗率',
            averageDuration: '平均 (ms)',
            maxDuration: '最長 (ms)',
            lastProcessed: '最終処理',
            class: 'ジョブクラス',
            byQueue: 'キュー別',
            byClass: 'ジョブクラス別',
            throughput: '1 分あたりの試行回数',
            empty: 'この期間に実行されたものはありません。',
        },
        widget: {
            pending: '待機中',
            failed: '失敗',
            open: 'キューを開く',
        },
    },
    'zh-Hans': {
        title: '队列',
        menuGroup: '运维',
        loading: '加载中…',
        none: '—',
        percent: '{value}%',
        noAccess: '你的账号无权查看队列仪表盘。',
        tabs: {
            overview: '概览',
            failed: '失败的任务',
            metrics: '指标',
        },
        actions: {
            cancel: '取消',
            confirm: '确认',
            refresh: '刷新',
            back: '返回',
        },
        errors: {
            forbidden: '你的账号无权执行该操作。',
            notFound: '它已不存在。',
            conflict: '服务器拒绝了该操作：{detail}',
            invalid: '请求被拒绝：{detail}',
            failed: '出了点问题：{detail}',
        },
        overview: {
            queue: '队列',
            size: '等待中',
            failed: '失败',
            unknownSize: '未知',
            empty: '没有被监控的队列。',
            clear: '清空',
            clearUnavailable: '此队列后端无法丢弃等待中的任务。',
            clearTitle: '清空“{queue}”？',
            clearText: '“{queue}”中所有等待的任务都会被丢弃。此操作无法撤销。',
            cleared: '已从“{queue}”中丢弃 {count} 个任务。',
            clearUnsupported: '此队列后端无法丢弃等待中的任务，因此未清空“{queue}”。',
            clearInvalid: '未清空“{queue}”：{detail}',
        },
        failed: {
            filters: {
                queue: '队列',
                allQueues: '所有队列',
                class: '任务类',
                from: '失败起始',
                to: '失败截止',
                apply: '应用',
                reset: '重置',
            },
            select: '选择 {id}',
            id: 'ID',
            class: '任务类',
            queue: '队列',
            attempts: '尝试次数',
            attemptsOf: '第 {attempts} 次，共 {max} 次',
            exception: '异常',
            failedAt: '失败时间',
            empty: '没有匹配的失败任务。',
            loadMore: '加载更多',
            open: '详情',
            retry: '重试',
            retrySelected: '重试所选项 ({count})',
            retryLimit: '一次最多重试 {max} 个任务。',
            retryTitle: '重试 {count} 个任务？',
            retryText: '每个任务都会被放回其队列，其失败记录会被删除。',
            retried: '已重试 {retried} 个；未找到 {notFound} 个；失败 {failed} 个。',
            forget: '忽略',
            forgetTitle: '忽略此失败的任务？',
            forgetText: '仅删除该记录，不重试任务。',
            forgotten: '已忽略该失败的任务。',
            prune: '清理',
            pruneDays: '早于（天）',
            pruneTitle: '清理早于 {days} 天的失败任务？',
            pruneText: '所有更早的失败记录都会从所有队列中删除。',
            pruned: '已删除 {count} 条失败记录。',
            detail: {
                title: '失败的任务 {id}',
                connection: '连接',
                message: '消息',
                trace: '堆栈跟踪',
                noTrace: '不会为失败的任务记录堆栈跟踪。',
                args: '参数',
            },
        },
        metrics: {
            queue: '队列',
            allQueues: '所有队列',
            window: '时间范围',
            windows: {
                m15: '最近 15 分钟',
                m60: '最近 1 小时',
                m360: '最近 6 小时',
                m1440: '最近 24 小时',
                m10080: '最近 7 天',
            },
            succeeded: '成功',
            released: '已释放',
            failed: '失败',
            failureRate: '失败率',
            averageDuration: '平均 (ms)',
            maxDuration: '最长 (ms)',
            lastProcessed: '最后处理',
            class: '任务类',
            byQueue: '按队列',
            byClass: '按任务类',
            throughput: '每分钟尝试次数',
            empty: '此时间范围内没有运行任何任务。',
        },
        widget: {
            pending: '等待中',
            failed: '失败',
            open: '打开队列',
        },
    },
}

/** The HTTP status of an API client error, whichever field the client put it in. */
function statusOf(error) {
    return error?.status ?? error?.statusCode ?? error?.response?.status ?? null
}

/** The server's own explanation of a refusal, for the notices that quote it. */
function detailOf(error) {
    const data = error?.data
    if (data && typeof data === 'object') {
        for (const key of ['error', 'detail', 'title']) {
            if (typeof data[key] === 'string') return data[key]
        }
    }
    return typeof error?.message === 'string' ? error.message : String(error)
}

/** Drops the empty filters, so an unset one is absent rather than an empty string the server refuses. */
function compact(query) {
    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined))
}

/** A `datetime-local` value in the browser's zone as RFC 3339 in whole seconds, UTC; '' when unset or invalid. */
function rfc3339(local) {
    if (!local) return ''
    const date = new Date(local)
    return Number.isNaN(date.getTime()) ? '' : date.toISOString().replace(/\.\d{3}Z$/, 'Z')
}

export default function ({ vue, vuetify, definePage, defineWidget, defineMessages, t, api: vectorApi, navigate: vectorNavigate }) {
    const { h, ref, computed, onMounted, onBeforeUnmount } = vue
    const { VAlert, VBtn, VCard, VCheckbox, VChip, VDialog, VProgressLinear, VSelect, VTab, VTable, VTabs, VTextField, VTooltip } = vuetify

    defineMessages('en', { vectorQueues: messages })
    for (const [code, catalogue] of Object.entries(translations)) defineMessages(code, { vectorQueues: catalogue })

    const tq = (key, params = {}) => t(`vectorQueues.${key}`, params)
    const number = value => (typeof value === 'number' ? value.toLocaleString() : tq('none'))
    const ms = value => (typeof value === 'number' ? value.toLocaleString(undefined, { maximumFractionDigits: 1 }) : tq('none'))
    const rate = value => (typeof value === 'number' ? tq('percent', { value: (value * 100).toFixed(1) }) : tq('none'))
    const isoTime = value => (value ? new Date(value).toLocaleString() : tq('none'))
    const unixTime = value => (typeof value === 'number' ? new Date(value * 1000).toLocaleString() : tq('none'))
    const tabular = { style: 'font-variant-numeric: tabular-nums' }

    /** A notice for a failed call: 403, 404, 409 and 422 in words of their own, anything else with the server's reason. */
    function describe(error) {
        switch (statusOf(error)) {
            case 403: return tq('errors.forbidden')
            case 404: return tq('errors.notFound')
            case 409: return tq('errors.conflict', { detail: detailOf(error) })
            case 422: return tq('errors.invalid', { detail: detailOf(error) })
            default: return tq('errors.failed', { detail: detailOf(error) })
        }
    }

    const noAccess = () => h(VAlert, { type: 'warning', variant: 'tonal', text: tq('noAccess'), 'data-state': 'no-access' })
    const loadingBar = () => h(VProgressLinear, { indeterminate: true, color: 'primary', 'aria-label': tq('loading') })

    /**
     * One confirmation dialog per tab: ask() opens it, and its Confirm
     * runs the action once, staying open (and busy) until it settles.
     */
    function useConfirm() {
        const pending = ref(null)
        const busy = ref(false)

        function ask(title, text, run) {
            pending.value = { title, text, run }
        }

        async function accept() {
            if (!pending.value || busy.value) return
            busy.value = true
            try {
                await pending.value.run()
            } finally {
                busy.value = false
                pending.value = null
            }
        }

        function render() {
            const open = pending.value !== null
            return h(VDialog, {
                modelValue: open,
                'onUpdate:modelValue': value => { if (!value && !busy.value) pending.value = null },
                maxWidth: 480,
            }, {
                default: () => open
                    ? h(VCard, { title: pending.value.title, text: pending.value.text }, {
                            actions: () => [
                                h(VBtn, { variant: 'text', disabled: busy.value, onClick: () => { pending.value = null } }, () => tq('actions.cancel')),
                                h(VBtn, { color: 'error', variant: 'flat', loading: busy.value, 'data-action': 'confirm', onClick: accept }, () => tq('actions.confirm')),
                            ],
                        })
                    : null,
            })
        }

        return { ask, render }
    }

    /**
     * The overview: GET /vector/queues every POLL_MS while the page is
     * visible, one request at a time — the next is scheduled only once
     * the last settled — and never after the tab is unmounted or once
     * the server answered 403.
     */
    const OverviewTab = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true } },
        setup(props) {
            const rows = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            const confirm = useConfirm()
            let timer = null
            let inFlight = false
            let again = false
            let stopped = false

            function unschedule() {
                if (timer !== null) clearTimeout(timer)
                timer = null
            }

            function schedule() {
                unschedule()
                if (!stopped && !document.hidden) timer = setTimeout(refresh, POLL_MS)
            }

            async function refresh() {
                if (inFlight) {
                    again = true
                    return
                }
                unschedule()
                if (stopped) return
                inFlight = true
                try {
                    const response = await props.vector.api('/vector/queues')
                    rows.value = response.data
                    error.value = null
                } catch (caught) {
                    if (statusOf(caught) === 403) {
                        forbidden.value = true
                        stopped = true
                    } else {
                        error.value = describe(caught)
                    }
                } finally {
                    inFlight = false
                    if (again) {
                        again = false
                        void refresh()
                    } else {
                        schedule()
                    }
                }
            }

            function onVisibility() {
                if (document.hidden) unschedule()
                else if (!inFlight) void refresh()
            }

            onMounted(() => {
                document.addEventListener('visibilitychange', onVisibility)
                void refresh()
            })

            onBeforeUnmount(() => {
                stopped = true
                unschedule()
                document.removeEventListener('visibilitychange', onVisibility)
            })

            function clear(queue) {
                confirm.ask(tq('overview.clearTitle', { queue }), tq('overview.clearText', { queue }), async () => {
                    try {
                        const response = await props.vector.api(`/vector/queues/${encodeURIComponent(queue)}/clear`, { method: 'POST', body: { confirm: queue } })
                        props.vector.notify.success(tq('overview.cleared', { queue, count: response.data.cleared }))
                    } catch (caught) {
                        const status = statusOf(caught)
                        props.vector.notify.error(status === 409
                            ? tq('overview.clearUnsupported', { queue })
                            : status === 422 ? tq('overview.clearInvalid', { queue, detail: detailOf(caught) }) : describe(caught))
                    }
                    void refresh()
                })
            }

            function clearButton(row) {
                if (props.permissions.clear?.[row.name] !== true) return null
                const button = activator => h(VBtn, {
                    ...activator,
                    size: 'small',
                    variant: 'tonal',
                    color: 'error',
                    prependIcon: 'mdi-delete-sweep-outline',
                    disabled: !row.clearable,
                    'data-action': 'clear',
                    onClick: () => clear(row.name),
                }, () => tq('overview.clear'))

                if (row.clearable) return button({})
                // a disabled button receives no pointer events: the tooltip hangs on a wrapper
                return h(VTooltip, { text: tq('overview.clearUnavailable'), location: 'top' }, {
                    activator: ({ props: activator }) => h('span', activator, [button({})]),
                })
            }

            return () => {
                if (forbidden.value) return noAccess()
                if (rows.value === null) return error.value ? h(VAlert, { type: 'error', variant: 'tonal', text: error.value }) : loadingBar()

                return h('div', [
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    rows.value.length === 0
                        ? h('p', { class: 'text-medium-emphasis' }, tq('overview.empty'))
                        : h(VTable, { hover: true }, () => [
                                h('thead', h('tr', [
                                    h('th', { class: 'text-start' }, tq('overview.queue')),
                                    h('th', { class: 'text-end' }, tq('overview.size')),
                                    h('th', { class: 'text-end' }, tq('overview.failed')),
                                    h('th', { class: 'text-end' }),
                                ])),
                                h('tbody', rows.value.map(row => h('tr', { key: row.name }, [
                                    h('td', row.name),
                                    h('td', { class: 'text-end', ...tabular }, row.size === null ? tq('overview.unknownSize') : number(row.size)),
                                    h('td', { class: 'text-end', ...tabular }, row.failed > 0
                                        ? h(VChip, { size: 'small', color: 'error', variant: 'tonal', label: true }, () => number(row.failed))
                                        : number(row.failed)),
                                    h('td', { class: 'text-end' }, clearButton(row)),
                                ]))),
                            ]),
                    confirm.render(),
                ])
            }
        },
    }

    /** One failed job: GET /failed/{id}, its exception, message and trace (always null today) as text, and Retry and Forget when allowed. */
    const FailedDetail = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true }, id: { type: String, required: true } },
        emits: ['back', 'changed'],
        setup(props, { emit }) {
            const job = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            const confirm = useConfirm()

            onMounted(async () => {
                try {
                    job.value = (await props.vector.api(`/vector/queues/failed/${encodeURIComponent(props.id)}`)).data
                } catch (caught) {
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            })

            function retry() {
                confirm.ask(tq('failed.retryTitle', { count: 1 }), tq('failed.retryText'), async () => {
                    try {
                        const results = (await props.vector.api('/vector/queues/failed/retry', { method: 'POST', body: { ids: [props.id] } })).data
                        notifyRetried(props.vector, results)
                        emit('changed')
                        if (results.every(result => result.status !== 'failed')) emit('back')
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                })
            }

            function forget() {
                confirm.ask(tq('failed.forgetTitle'), tq('failed.forgetText'), async () => {
                    try {
                        await props.vector.api(`/vector/queues/failed/${encodeURIComponent(props.id)}`, { method: 'DELETE' })
                        props.vector.notify.success(tq('failed.forgotten'))
                        emit('changed')
                        emit('back')
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                })
            }

            const field = (label, value) => h('div', { class: 'mb-2' }, [
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
                h('div', value),
            ])
            // text children only: Vue sets them as text nodes, so a message or a trace is never parsed as HTML
            const block = (label, text) => h('div', { class: 'mb-3' }, [
                h('div', { class: 'text-caption text-medium-emphasis mb-1' }, label),
                h('pre', { class: 'pa-2 rounded bg-surface-variant', style: 'white-space: pre-wrap; word-break: break-word; max-height: 24rem; overflow: auto' }, text),
            ])

            return () => {
                const back = h(VBtn, { variant: 'text', prependIcon: 'mdi-arrow-left', class: 'mb-3', onClick: () => emit('back') }, () => tq('actions.back'))
                if (forbidden.value) return h('div', [back, noAccess()])
                if (error.value) return h('div', [back, h(VAlert, { type: 'error', variant: 'tonal', text: error.value })])
                if (job.value === null) return h('div', [back, loadingBar()])

                const value = job.value
                return h('div', [
                    h('div', { class: 'd-flex align-center ga-2 flex-wrap' }, [
                        back,
                        h('div', { class: 'flex-grow-1' }),
                        props.permissions.retry === true
                            && h(VBtn, { variant: 'tonal', color: 'primary', prependIcon: 'mdi-replay', 'data-action': 'retry', onClick: retry }, () => tq('failed.retry')),
                        props.permissions.forget === true
                            && h(VBtn, { variant: 'tonal', color: 'error', prependIcon: 'mdi-delete-outline', 'data-action': 'forget', onClick: forget }, () => tq('failed.forget')),
                    ]),
                    h('h2', { class: 'text-h6 mb-3' }, tq('failed.detail.title', { id: value.id })),
                    field(tq('failed.class'), value.class),
                    field(tq('failed.queue'), value.queue),
                    field(tq('failed.detail.connection'), value.connection ?? tq('none')),
                    field(tq('failed.attempts'), value.maxAttempts === null ? number(value.attempts) : tq('failed.attemptsOf', { attempts: value.attempts, max: value.maxAttempts })),
                    field(tq('failed.failedAt'), isoTime(value.failedAt)),
                    field(tq('failed.exception'), value.exception.class),
                    block(tq('failed.detail.message'), value.exception.message),
                    block(tq('failed.detail.trace'), value.exception.trace ?? tq('failed.detail.noTrace')),
                    block(tq('failed.detail.args'), JSON.stringify(value.args, null, 2)),
                    confirm.render(),
                ])
            }
        },
    }

    function notifyRetried(vector, results) {
        const count = status => results.filter(result => result.status === status).length
        const message = tq('failed.retried', { retried: count('retried'), notFound: count('not_found'), failed: count('failed') })
        if (count('retried') === results.length) vector.notify.success(message)
        else vector.notify.error(message)
    }

    /**
     * The failed list: GET /failed with its filters, newest first, paged
     * by the server's opaque `nextCursor` ("load more"), with Retry (one,
     * or up to MAX_RETRY_BATCH selected), Forget and Prune where allowed.
     */
    const FailedTab = {
        props: { vector: { type: Object, required: true }, permissions: { type: Object, required: true }, queues: { type: Array, required: true } },
        setup(props) {
            const emptyFilters = () => ({ queue: '', class: '', failedFrom: '', failedTo: '' })
            const filters = ref(emptyFilters())
            let applied = emptyFilters()
            const rows = ref([])
            const nextCursor = ref(null)
            const loaded = ref(false)
            const loading = ref(false)
            const forbidden = ref(false)
            const error = ref(null)
            const selected = ref(new Set())
            const openId = ref(null)
            const pruneDays = ref('30')
            const confirm = useConfirm()
            let request = 0

            /** The first page for the applied filters, or (more) the page after the last one shown. */
            async function load(more = false) {
                const id = ++request
                loading.value = true
                const query = compact({
                    queue: applied.queue,
                    class: applied.class.trim(),
                    failedFrom: rfc3339(applied.failedFrom),
                    failedTo: rfc3339(applied.failedTo),
                    cursor: more ? nextCursor.value : '',
                })
                try {
                    const page = await props.vector.api('/vector/queues/failed', { query })
                    if (id !== request) return
                    rows.value = more ? [...rows.value, ...page.data] : page.data
                    nextCursor.value = page.hasMore ? page.nextCursor : null
                    error.value = null
                    loaded.value = true
                    if (!more) selected.value = new Set()
                } catch (caught) {
                    if (id !== request) return
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                } finally {
                    if (id === request) loading.value = false
                }
            }

            onMounted(() => load())

            function apply() {
                applied = { ...filters.value }
                void load()
            }

            function reset() {
                filters.value = emptyFilters()
                apply()
            }

            function toggle(id, on) {
                const next = new Set(selected.value)
                if (on && next.size < MAX_RETRY_BATCH) next.add(id)
                else next.delete(id)
                selected.value = next
            }

            function retry(ids) {
                confirm.ask(tq('failed.retryTitle', { count: ids.length }), tq('failed.retryText'), async () => {
                    try {
                        notifyRetried(props.vector, (await props.vector.api('/vector/queues/failed/retry', { method: 'POST', body: { ids } })).data)
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            function forget(id) {
                confirm.ask(tq('failed.forgetTitle'), tq('failed.forgetText'), async () => {
                    try {
                        await props.vector.api(`/vector/queues/failed/${encodeURIComponent(id)}`, { method: 'DELETE' })
                        props.vector.notify.success(tq('failed.forgotten'))
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            const days = computed(() => (/^[1-9]\d*$/.test(String(pruneDays.value).trim()) ? Number(pruneDays.value) : null))

            function prune() {
                const value = days.value
                if (value === null || value > MAX_PRUNE_DAYS) return
                confirm.ask(tq('failed.pruneTitle', { days: value }), tq('failed.pruneText'), async () => {
                    try {
                        const response = await props.vector.api('/vector/queues/failed/prune', { method: 'POST', body: { days: value } })
                        props.vector.notify.success(tq('failed.pruned', { count: response.data.deleted }))
                    } catch (caught) {
                        props.vector.notify.error(describe(caught))
                    }
                    await load()
                })
            }

            const model = key => ({
                modelValue: filters.value[key],
                'onUpdate:modelValue': value => { filters.value = { ...filters.value, [key]: value ?? '' } },
            })

            function toolbar() {
                const queueItems = [{ title: tq('failed.filters.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))]
                return h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, [
                    h(VSelect, { ...model('queue'), items: queueItems, label: tq('failed.filters.queue'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VTextField, { ...model('class'), label: tq('failed.filters.class'), density: 'compact', hideDetails: true, style: 'max-width: 22rem', onKeydown: event => { if (event.key === 'Enter') apply() } }),
                    h(VTextField, { ...model('failedFrom'), type: 'datetime-local', step: 1, label: tq('failed.filters.from'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VTextField, { ...model('failedTo'), type: 'datetime-local', step: 1, label: tq('failed.filters.to'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                    h(VBtn, { variant: 'tonal', color: 'primary', onClick: apply }, () => tq('failed.filters.apply')),
                    h(VBtn, { variant: 'text', onClick: reset }, () => tq('failed.filters.reset')),
                ])
            }

            function mutations() {
                const buttons = []
                if (props.permissions.retry === true) {
                    buttons.push(h(VBtn, {
                        variant: 'tonal',
                        color: 'primary',
                        prependIcon: 'mdi-replay',
                        disabled: selected.value.size === 0,
                        'data-action': 'retry-selected',
                        onClick: () => retry([...selected.value]),
                    }, () => tq('failed.retrySelected', { count: selected.value.size })))
                    if (selected.value.size >= MAX_RETRY_BATCH) buttons.push(h('span', { class: 'text-caption text-medium-emphasis' }, tq('failed.retryLimit', { max: MAX_RETRY_BATCH })))
                }
                if (props.permissions.prune === true) {
                    buttons.push(h('div', { class: 'flex-grow-1' }))
                    buttons.push(h(VTextField, {
                        modelValue: pruneDays.value,
                        'onUpdate:modelValue': value => { pruneDays.value = value },
                        type: 'number',
                        min: 1,
                        max: MAX_PRUNE_DAYS,
                        label: tq('failed.pruneDays'),
                        density: 'compact',
                        hideDetails: true,
                        style: 'max-width: 11rem',
                    }))
                    buttons.push(h(VBtn, {
                        variant: 'tonal',
                        color: 'error',
                        prependIcon: 'mdi-broom',
                        disabled: days.value === null || days.value > MAX_PRUNE_DAYS,
                        'data-action': 'prune',
                        onClick: prune,
                    }, () => tq('failed.prune')))
                }
                return buttons.length === 0 ? null : h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, buttons)
            }

            function table() {
                const canRetry = props.permissions.retry === true
                const canForget = props.permissions.forget === true
                return h(VTable, { hover: true, density: 'compact' }, () => [
                    h('thead', h('tr', [
                        canRetry && h('th'),
                        h('th', { class: 'text-start' }, tq('failed.class')),
                        h('th', { class: 'text-start' }, tq('failed.queue')),
                        h('th', { class: 'text-start' }, tq('failed.exception')),
                        h('th', { class: 'text-end' }, tq('failed.attempts')),
                        h('th', { class: 'text-start' }, tq('failed.failedAt')),
                        h('th', { class: 'text-end' }),
                    ])),
                    h('tbody', rows.value.map(row => h('tr', { key: row.id }, [
                        canRetry && h('td', h(VCheckbox, {
                            modelValue: selected.value.has(row.id),
                            'onUpdate:modelValue': on => toggle(row.id, on),
                            disabled: !selected.value.has(row.id) && selected.value.size >= MAX_RETRY_BATCH,
                            density: 'compact',
                            hideDetails: true,
                            'aria-label': tq('failed.select', { id: row.id }),
                        })),
                        h('td', { style: 'word-break: break-all' }, row.class),
                        h('td', row.queue),
                        h('td', [
                            h('div', { class: 'text-caption' }, row.exception.class),
                            h('div', { class: 'text-medium-emphasis', style: 'max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap' }, row.exception.message),
                        ]),
                        h('td', { class: 'text-end', ...tabular }, row.maxAttempts === null ? number(row.attempts) : tq('failed.attemptsOf', { attempts: row.attempts, max: row.maxAttempts })),
                        h('td', isoTime(row.failedAt)),
                        h('td', { class: 'text-end text-no-wrap' }, [
                            h(VBtn, { size: 'small', variant: 'text', onClick: () => { openId.value = row.id } }, () => tq('failed.open')),
                            canRetry && h(VBtn, { size: 'small', variant: 'text', color: 'primary', 'data-action': 'retry', onClick: () => retry([row.id]) }, () => tq('failed.retry')),
                            canForget && h(VBtn, { size: 'small', variant: 'text', color: 'error', 'data-action': 'forget', onClick: () => forget(row.id) }, () => tq('failed.forget')),
                        ]),
                    ]))),
                ])
            }

            return () => {
                if (forbidden.value) return noAccess()
                if (openId.value !== null) {
                    return h(FailedDetail, {
                        key: openId.value,
                        vector: props.vector,
                        permissions: props.permissions,
                        id: openId.value,
                        onBack: () => { openId.value = null },
                        onChanged: () => load(),
                    })
                }

                return h('div', [
                    toolbar(),
                    mutations(),
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    !loaded.value
                        ? loadingBar()
                        : rows.value.length === 0
                            ? h('p', { class: 'text-medium-emphasis' }, tq('failed.empty'))
                            : table(),
                    nextCursor.value !== null && h('div', { class: 'd-flex justify-center mt-3' }, [
                        h(VBtn, { variant: 'tonal', loading: loading.value, onClick: () => load(true) }, () => tq('failed.loadMore')),
                    ]),
                    confirm.render(),
                ])
            }
        },
    }

    /** Attempts per minute as an inline SVG polyline, summed into at most SPARK_POINTS points; no chart library. */
    function sparkline(buckets) {
        const totals = buckets.map(bucket => bucket.succeeded + bucket.released + bucket.failed)
        const size = Math.max(1, Math.ceil(totals.length / SPARK_POINTS))
        const points = []
        for (let index = 0; index < totals.length; index += size) {
            points.push(totals.slice(index, index + size).reduce((sum, value) => sum + value, 0))
        }
        const peak = Math.max(1, ...points)
        const width = Math.max(1, points.length - 1)
        const line = points.map((value, index) => `${index},${(40 - (value / peak) * 38 - 1).toFixed(2)}`).join(' ')

        return h('svg', { viewBox: `0 0 ${width} 40`, preserveAspectRatio: 'none', width: '100%', height: 48, role: 'img', 'aria-label': tq('metrics.throughput') }, [
            h('polyline', { points: line, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'vector-effect': 'non-scaling-stroke' }),
        ])
    }

    /** The job metrics: GET /metrics for a watched queue (or all) over a window of at most 7 days, the last hour by default. */
    const MetricsTab = {
        props: { vector: { type: Object, required: true }, queues: { type: Array, required: true } },
        setup(props) {
            const queue = ref('')
            const minutes = ref(DEFAULT_WINDOW)
            const metrics = ref(null)
            const forbidden = ref(false)
            const error = ref(null)
            let request = 0

            async function load() {
                const id = ++request
                // both ends from this clock, so the window is exactly the one chosen whatever the server's clock says
                const to = Math.floor(Date.now() / 60000) * 60
                const from = to - (minutes.value - 1) * 60
                try {
                    const response = await props.vector.api('/vector/queues/metrics', { query: compact({ queue: queue.value, from, to }) })
                    if (id !== request) return
                    metrics.value = response.data
                    error.value = null
                } catch (caught) {
                    if (id !== request) return
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            }

            onMounted(load)

            const totals = computed(() => {
                const sum = { succeeded: 0, released: 0, failed: 0, max: null, duration: 0 }
                for (const row of metrics.value?.queues ?? []) {
                    sum.succeeded += row.succeeded
                    sum.released += row.released
                    sum.failed += row.failed
                    sum.duration += row.averageDurationMs * (row.succeeded + row.released + row.failed)
                    sum.max = Math.max(sum.max ?? 0, row.maxDurationMs)
                }
                const attempts = sum.succeeded + sum.released + sum.failed
                const finished = sum.succeeded + sum.failed
                return {
                    ...sum,
                    failureRate: finished === 0 ? null : sum.failed / finished,
                    averageDurationMs: attempts === 0 ? null : sum.duration / attempts,
                }
            })

            function totalsTable(label, key, rows) {
                return h('div', { class: 'mb-4' }, [
                    h('h3', { class: 'text-subtitle-1 mb-1' }, label),
                    h(VTable, { density: 'compact', hover: true }, () => [
                        h('thead', h('tr', [
                            h('th', { class: 'text-start' }, key === 'queue' ? tq('metrics.queue') : tq('metrics.class')),
                            h('th', { class: 'text-end' }, tq('metrics.succeeded')),
                            h('th', { class: 'text-end' }, tq('metrics.released')),
                            h('th', { class: 'text-end' }, tq('metrics.failed')),
                            h('th', { class: 'text-end' }, tq('metrics.failureRate')),
                            h('th', { class: 'text-end' }, tq('metrics.averageDuration')),
                            h('th', { class: 'text-end' }, tq('metrics.maxDuration')),
                            h('th', { class: 'text-start' }, tq('metrics.lastProcessed')),
                        ])),
                        h('tbody', rows.map(row => h('tr', { key: row[key] }, [
                            h('td', { style: 'word-break: break-all' }, row[key]),
                            h('td', { class: 'text-end', ...tabular }, number(row.succeeded)),
                            h('td', { class: 'text-end', ...tabular }, number(row.released)),
                            h('td', { class: 'text-end', ...tabular }, number(row.failed)),
                            h('td', { class: 'text-end', ...tabular }, rate(row.failureRate)),
                            h('td', { class: 'text-end', ...tabular }, ms(row.averageDurationMs)),
                            h('td', { class: 'text-end', ...tabular }, ms(row.maxDurationMs)),
                            h('td', unixTime(row.lastProcessedAt)),
                        ]))),
                    ]),
                ])
            }

            const stat = (label, value) => h(VCard, { variant: 'tonal', class: 'pa-3', style: 'min-width: 9rem' }, () => [
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
                h('div', { class: 'text-h6', ...tabular }, value),
            ])

            return () => {
                if (forbidden.value) return noAccess()
                const queueItems = [{ title: tq('metrics.allQueues'), value: '' }, ...props.queues.map(name => ({ title: name, value: name }))]
                const windowItems = WINDOWS.map(value => ({ title: tq(`metrics.windows.m${value}`), value }))
                const data = metrics.value
                const sum = totals.value

                return h('div', [
                    h('div', { class: 'd-flex flex-wrap align-center ga-2 mb-3' }, [
                        h(VSelect, { modelValue: queue.value, 'onUpdate:modelValue': value => { queue.value = value ?? ''; void load() }, items: queueItems, label: tq('metrics.queue'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                        h(VSelect, { modelValue: minutes.value, 'onUpdate:modelValue': value => { minutes.value = value; void load() }, items: windowItems, label: tq('metrics.window'), density: 'compact', hideDetails: true, style: 'max-width: 14rem' }),
                        h(VBtn, { variant: 'text', prependIcon: 'mdi-refresh', onClick: load }, () => tq('actions.refresh')),
                    ]),
                    error.value && h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', class: 'mb-3', text: error.value }),
                    data === null
                        ? (error.value ? null : loadingBar())
                        : h('div', [
                                h('div', { class: 'd-flex flex-wrap ga-3 mb-4' }, [
                                    stat(tq('metrics.succeeded'), number(sum.succeeded)),
                                    stat(tq('metrics.released'), number(sum.released)),
                                    stat(tq('metrics.failed'), number(sum.failed)),
                                    stat(tq('metrics.failureRate'), rate(sum.failureRate)),
                                    stat(tq('metrics.averageDuration'), ms(sum.averageDurationMs)),
                                    stat(tq('metrics.maxDuration'), ms(sum.max)),
                                ]),
                                h('div', { class: 'mb-4 text-primary' }, [
                                    h('div', { class: 'text-caption text-medium-emphasis' }, tq('metrics.throughput')),
                                    sparkline(data.buckets),
                                ]),
                                data.queues.length === 0
                                    ? h('p', { class: 'text-medium-emphasis' }, tq('metrics.empty'))
                                    : [totalsTable(tq('metrics.byQueue'), 'queue', data.queues), totalsTable(tq('metrics.byClass'), 'class', data.classes)],
                            ]),
                ])
            }
        },
    }

    definePage('queues', {
        // functions, so the menu entry follows a change of language
        title: () => tq('title'),
        icon: 'mdi-tray-full',
        group: () => tq('menuGroup'),
        component: {
            props: { vector: { type: Object, required: true } },
            setup(props) {
                const tab = ref('overview')
                const permissions = ref(null)
                const forbidden = ref(false)
                const error = ref(null)

                onMounted(async () => {
                    try {
                        permissions.value = await props.vector.api('/vector/queues/permissions')
                    } catch (caught) {
                        if (statusOf(caught) === 403) forbidden.value = true
                        else error.value = describe(caught)
                    }
                })

                return () => {
                    if (forbidden.value) return noAccess()
                    if (error.value) return h(VAlert, { type: 'error', variant: 'tonal', text: error.value })
                    if (permissions.value === null) return loadingBar()

                    const queues = Object.keys(permissions.value.clear ?? {})
                    const shared = { vector: props.vector, permissions: permissions.value, queues }

                    return h('div', [
                        h(VTabs, { modelValue: tab.value, 'onUpdate:modelValue': value => { tab.value = value }, class: 'mb-4' }, () => [
                            h(VTab, { value: 'overview' }, () => tq('tabs.overview')),
                            h(VTab, { value: 'failed' }, () => tq('tabs.failed')),
                            h(VTab, { value: 'metrics' }, () => tq('tabs.metrics')),
                        ]),
                        // only the open tab is mounted, so the overview polls only while it is shown
                        tab.value === 'overview' ? h(OverviewTab, shared) : tab.value === 'failed' ? h(FailedTab, shared) : h(MetricsTab, shared),
                    ])
                }
            },
        },
    })

    /**
     * A dashboard card: the jobs waiting and failed across the watched
     * queues, from GET /vector/queues, and a link to the page. It renders
     * a dashboard widget whose type is "vector-queues"; the `data` the
     * dashboard loads for it is not used.
     */
    defineWidget('vector-queues', {
        props: { data: {}, widget: {} },
        setup() {
            const totals = ref(null)
            const forbidden = ref(false)
            const error = ref(null)

            onMounted(async () => {
                try {
                    const rows = (await vectorApi('/vector/queues')).data
                    const known = rows.filter(row => row.size !== null)
                    totals.value = {
                        pending: known.length === 0 ? null : known.reduce((sum, row) => sum + row.size, 0),
                        failed: rows.reduce((sum, row) => sum + row.failed, 0),
                    }
                } catch (caught) {
                    if (statusOf(caught) === 403) forbidden.value = true
                    else error.value = describe(caught)
                }
            })

            const figure = (label, value, color) => h('div', { class: 'flex-grow-1' }, [
                h('div', { class: `text-h4 ${color}`, ...tabular }, number(value)),
                h('div', { class: 'text-caption text-medium-emphasis' }, label),
            ])

            return () => {
                if (forbidden.value) return noAccess()
                if (error.value) return h(VAlert, { type: 'error', variant: 'tonal', density: 'compact', text: error.value })
                if (totals.value === null) return loadingBar()

                return h('div', [
                    h('div', { class: 'd-flex ga-4 mb-2' }, [
                        figure(tq('widget.pending'), totals.value.pending, ''),
                        figure(tq('widget.failed'), totals.value.failed, totals.value.failed > 0 ? 'text-error' : ''),
                    ]),
                    h(VBtn, { variant: 'text', size: 'small', appendIcon: 'mdi-arrow-right', class: 'px-0', onClick: () => vectorNavigate('/p/queues') }, () => tq('widget.open')),
                ])
            }
        },
    })
}
