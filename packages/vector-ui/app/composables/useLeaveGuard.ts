/**
 * Asks before leaving a page while $dirty says it holds unsaved changes:
 * a route change through the confirm dialog, a reload or a closed tab
 * through the browser's own prompt.
 */
export function useLeaveGuard(dirty: () => boolean) {
    const { confirm } = useConfirm()
    const { t } = useVectorT()

    const mayLeave = async () => !dirty() || await confirm({ title: t('record.leave.title'), text: t('record.leave.text'), confirmText: t('record.leave.discard'), destructive: true })

    onBeforeRouteLeave(mayLeave)
    onBeforeRouteUpdate((to, from) => to.path === from.path || mayLeave())

    function warnBeforeUnload(event: BeforeUnloadEvent) {
        if (dirty()) event.preventDefault()
    }

    onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload))
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))
}
