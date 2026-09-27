export interface ConfirmOptions {
    title: string
    text?: string
    confirmText?: string
    cancelText?: string
    destructive?: boolean
}

interface PendingConfirm extends ConfirmOptions {
    resolve: (confirmed: boolean) => void
}

/**
 * A promise-based confirmation. Vuetify ships no programmatic dialog;
 * VxConfirm, mounted once in the layout, renders whatever is pending.
 */
export function useConfirm() {
    const pending = useState<PendingConfirm | null>('vector.confirm', () => null)

    function confirm(options: ConfirmOptions): Promise<boolean> {
        pending.value?.resolve(false) // whoever asked first is answered, not left waiting

        return new Promise((resolve) => {
            pending.value = { ...options, resolve }
        })
    }

    function settle(confirmed: boolean) {
        pending.value?.resolve(confirmed)
        pending.value = null
    }

    return { pending, confirm, settle }
}
