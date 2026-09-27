// The jobs area's strings; see common.ts for words every area shares.
export default {
    title: 'Tâches',
    heading: 'Tâches en arrière-plan',
    columns: {
        job: 'Tâche',
        resource: 'Ressource',
        status: 'Statut',
        progress: 'Progression',
        started: 'Démarrée',
    },
    empty: 'Aucune tâche pour l’instant. Les exports volumineux, les imports et les actions en file d’attente apparaissent ici.',
    kind: {
        action: 'action',
        export: 'export',
        import: 'import',
    },
    status: {
        queued: 'en attente',
        running: 'en cours',
        done: 'terminée',
        failed: 'échouée',
    },
    detail: {
        pageTitle: 'Tâche n° {id}',
        fallbackTitle: 'Tâche',
        started: 'démarrée le {date}',
        finished: 'terminée le {date}',
        progress: 'Progression de la tâche',
        processed: '{processed} traités',
        processedOfTotal: '{processed} sur {total} traités',
        download: 'Télécharger {file}',
        file: 'le fichier',
    },
}
