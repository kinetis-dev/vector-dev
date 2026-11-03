export interface ConfirmOptions {
    title: string
    text?: string
    confirmText?: string
    cancelText?: string
    destructive?: boolean
    /**
     * A typed guard: the dialog shows a field, and its confirm button stays
     * disabled until what was typed equals this exactly.
     */
    requireText?: string
    /** The typed field's label; required wherever `requireText` is set, so the field has an accessible name. */
    requireLabel?: string
}

interface PendingConfirm extends ConfirmOptions {
    /** What was typed on confirm ('' without `requireText`), or null when declined. */
    resolve: (typed: string | null) => void
}

/**
 * A promise-based confirmation. Vuetify ships no programmatic dialog;
 * VxConfirm, mounted once in the layout, renders whatever is pending.
 * `confirmTyped()` asks for a typed confirmation and resolves to what was
 * typed, so a caller sends the user's own text rather than its own copy.
 */
export function useConfirm() {
    const pending = useState<PendingConfirm | null>('vector.confirm', () => null)
    const typed = useState<string>('vector.confirm.typed', () => '')
    const canConfirm = computed(() => pending.value !== null
        && (pending.value.requireText === undefined || typed.value === pending.value.requireText))

    function ask(options: ConfirmOptions): Promise<string | null> {
        pending.value?.resolve(null) // whoever asked first is answered, not left waiting
        typed.value = ''

        return new Promise((resolve) => {
            pending.value = { ...options, resolve }
        })
    }

    function confirm(options: ConfirmOptions): Promise<boolean> {
        return ask(options).then(answer => answer !== null)
    }

    /** Resolves to the typed text — equal to `requireText` — on confirm, or null when declined. */
    function confirmTyped(options: ConfirmOptions & { requireText: string, requireLabel: string }): Promise<string | null> {
        return ask(options)
    }

    /** Answers the pending question; a confirm before the typed text matches is ignored, the dialog stays open. */
    function settle(confirmed: boolean) {
        const current = pending.value
        if (current === null) return
        if (confirmed && !canConfirm.value) return
        const answer = confirmed ? typed.value : null
        pending.value = null
        typed.value = ''
        current.resolve(answer)
    }

    return { pending, typed, canConfirm, confirm, confirmTyped, settle }
}
