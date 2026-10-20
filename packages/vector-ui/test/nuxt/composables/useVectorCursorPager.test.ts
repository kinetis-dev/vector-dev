import { describe, expect, it } from 'vitest'
import { useVectorCursorPager } from '#vector/app/composables/useVectorCursorPager'

describe('useVectorCursorPager', () => {
    it('starts on the first page, whose cursor is empty, with nowhere to go yet', () => {
        const pager = useVectorCursorPager()

        expect(pager.current.value).toBe('')
        expect(pager.position.value).toBe(1)
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.hasNext.value).toBe(false)
        expect(pager.forward()).toBe(false)
        expect(pager.back()).toBe(false)
    })

    it('goes forward by the cursor the page handed out, and back through the ones visited', () => {
        const pager = useVectorCursorPager()

        pager.received({ nextCursor: 'c25', hasMore: true })
        expect(pager.hasNext.value).toBe(true)
        expect(pager.forward()).toBe(true)
        expect(pager.current.value).toBe('c25')
        expect(pager.hasNext.value).toBe(false) // until the page loads and says so

        pager.received({ nextCursor: 'c50', hasMore: true })
        pager.forward()
        expect(pager.current.value).toBe('c50')
        expect(pager.position.value).toBe(3)

        expect(pager.back()).toBe(true)
        expect(pager.current.value).toBe('c25')
        expect(pager.back()).toBe(true)
        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
    })

    it('offers no next page after the last, whatever cursor it carries', () => {
        const pager = useVectorCursorPager()

        pager.received({ nextCursor: 'c25', hasMore: false })
        expect(pager.hasNext.value).toBe(false)
        expect(pager.forward()).toBe(false)
        expect(pager.current.value).toBe('')
    })

    it('resets to the first page', () => {
        const pager = useVectorCursorPager()
        pager.received({ nextCursor: 'c25', hasMore: true })
        pager.forward()
        pager.received({ nextCursor: 'c50', hasMore: true })

        pager.reset()

        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.hasNext.value).toBe(false)
    })
})
