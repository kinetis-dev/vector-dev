import { describe, expect, it } from 'vitest'
import { useVectorCursorPager } from '#vector/app/composables/useVectorCursorPager'

describe('useVectorCursorPager', () => {
    it('starts on the first page, whose cursor is empty, with nowhere to go yet', () => {
        const pager = useVectorCursorPager()

        expect(pager.current.value).toBe('')
        expect(pager.cursorFor('stay')).toBe('')
        expect(pager.position.value).toBe(1)
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.hasNext.value).toBe(false)
        expect(pager.cursorFor('forward')).toBeNull()
        expect(pager.cursorFor('back')).toBeNull()
    })

    it('goes forward by the cursor the page handed out, and back through the ones visited', () => {
        const pager = useVectorCursorPager()

        pager.received('stay', { nextCursor: 'c25', hasMore: true })
        expect(pager.hasNext.value).toBe(true)
        expect(pager.cursorFor('forward')).toBe('c25')
        pager.received('forward', { nextCursor: 'c50', hasMore: true })
        expect(pager.current.value).toBe('c25')

        expect(pager.cursorFor('forward')).toBe('c50')
        pager.received('forward', { nextCursor: 'c75', hasMore: true })
        expect(pager.current.value).toBe('c50')
        expect(pager.position.value).toBe(3)

        expect(pager.cursorFor('back')).toBe('c25')
        pager.received('back', { nextCursor: 'c50', hasMore: true })
        expect(pager.current.value).toBe('c25')
        pager.received('back', { nextCursor: 'c25', hasMore: true })
        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
    })

    it('moves nothing until the page asked for has arrived', () => {
        const pager = useVectorCursorPager()
        pager.received('stay', { nextCursor: 'c25', hasMore: true })

        // the request for c25 failed: still on the first page, and Next asks for c25 again
        expect(pager.cursorFor('forward')).toBe('c25')
        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.cursorFor('forward')).toBe('c25')

        pager.received('forward', { nextCursor: 'c50', hasMore: true })
        // the request back failed: still on c25
        expect(pager.cursorFor('back')).toBe('')
        expect(pager.current.value).toBe('c25')
        expect(pager.cursorFor('forward')).toBe('c50')
    })

    it('offers no next page after the last, whatever cursor it carries', () => {
        const pager = useVectorCursorPager()

        pager.received('stay', { nextCursor: 'c25', hasMore: false })
        expect(pager.hasNext.value).toBe(false)
        expect(pager.cursorFor('forward')).toBeNull()
        expect(pager.current.value).toBe('')
    })

    it('goes back to the first page only once that page has arrived', () => {
        const pager = useVectorCursorPager()
        pager.received('stay', { nextCursor: 'c25', hasMore: true })
        pager.received('forward', { nextCursor: 'c50', hasMore: true })

        expect(pager.cursorFor('first')).toBe('')
        expect(pager.current.value).toBe('c25')
        expect(pager.hasPrevious.value).toBe(true)

        pager.received('first', { nextCursor: 'c25', hasMore: true })
        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.cursorFor('forward')).toBe('c25')
    })

    it('resets to the first page', () => {
        const pager = useVectorCursorPager()
        pager.received('stay', { nextCursor: 'c25', hasMore: true })
        pager.received('forward', { nextCursor: 'c50', hasMore: true })

        pager.reset()

        expect(pager.current.value).toBe('')
        expect(pager.hasPrevious.value).toBe(false)
        expect(pager.hasNext.value).toBe(false)
    })
})
