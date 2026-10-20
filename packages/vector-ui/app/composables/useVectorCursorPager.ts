/**
 * Keyset paging as a list walks it: the cursor of every page visited so
 * far, so Previous returns to the one before, and the next page's cursor
 * once the current page says another follows. The first page's cursor is
 * the empty string; the API hands out the others, and no cursor can be
 * worked out from a page number, so going back means remembering.
 */
export function useVectorCursorPager() {
    const visited = ref<string[]>([''])
    const next = ref<string | null>(null)

    /** The cursor to request the page shown now with. */
    const current = computed(() => visited.value[visited.value.length - 1]!)
    /** Which page this is, counting from 1. */
    const position = computed(() => visited.value.length)
    const hasPrevious = computed(() => visited.value.length > 1)
    const hasNext = computed(() => next.value !== null)

    /** Records what the page just loaded says about the one after it. */
    function received(page: { nextCursor: string | null, hasMore: boolean }) {
        next.value = page.hasMore ? page.nextCursor : null
    }

    /** Moves to the next page; false when there is none. */
    function forward(): boolean {
        if (next.value === null) return false
        visited.value = [...visited.value, next.value]
        next.value = null
        return true
    }

    /** Moves back to the page before; false on the first. */
    function back(): boolean {
        if (visited.value.length <= 1) return false
        visited.value = visited.value.slice(0, -1)
        next.value = null
        return true
    }

    /** Back to the first page, as a changed search, filter or page size needs. */
    function reset() {
        visited.value = ['']
        next.value = null
    }

    return { current, position, hasPrevious, hasNext, received, forward, back, reset }
}
