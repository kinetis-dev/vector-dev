/** Where a list goes from the page it shows: nowhere (a reload), the next page, or the one before. */
export type VectorPageStep = 'stay' | 'forward' | 'back'

/**
 * Keyset paging as a list walks it: the cursor of every page visited so
 * far, so Previous returns to the one before, and the next page's cursor
 * once the current page says another follows. The first page's cursor is
 * the empty string; the API hands out the others, and no cursor can be
 * worked out from a page number, so going back means remembering.
 *
 * A step moves nothing until its page has arrived: `cursorFor()` names
 * the cursor to request, and `received()` takes the step once the answer
 * is in, so a request that fails leaves the list where it was.
 */
export function useVectorCursorPager() {
    const visited = ref<string[]>([''])
    const next = ref<string | null>(null)

    /** The cursor the page shown now was requested with. */
    const current = computed(() => visited.value[visited.value.length - 1]!)
    /** Which page this is, counting from 1. */
    const position = computed(() => visited.value.length)
    const hasPrevious = computed(() => visited.value.length > 1)
    const hasNext = computed(() => next.value !== null)

    /** The cursor to request for $step; null when there is no such page. */
    function cursorFor(step: VectorPageStep): string | null {
        if (step === 'forward') return next.value
        if (step === 'back') return visited.value.length > 1 ? visited.value[visited.value.length - 2]! : null
        return current.value
    }

    /** Takes $step now that its page has arrived, and records what the page says about the one after it. */
    function received(step: VectorPageStep, page: { nextCursor: string | null, hasMore: boolean }) {
        if (step === 'forward' && next.value !== null) visited.value = [...visited.value, next.value]
        if (step === 'back' && visited.value.length > 1) visited.value = visited.value.slice(0, -1)
        next.value = page.hasMore ? page.nextCursor : null
    }

    /** Back to the first page, as a changed search, filter or page size needs. */
    function reset() {
        visited.value = ['']
        next.value = null
    }

    return { current, position, hasPrevious, hasNext, cursorFor, received, reset }
}
