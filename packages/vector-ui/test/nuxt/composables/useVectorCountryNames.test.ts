import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { useVectorCountryNames } from '#vector/app/composables/useVectorCountryNames'

// A locale chunk that fails to load must not leave an unhandled rejection:
// the names stay as they were.

vi.mock('#vector/app/utils/countries', () => ({
    loadCountryNames: vi.fn(() => Promise.reject(new Error('chunk failed'))),
}))

describe('useVectorCountryNames', () => {
    it('keeps the names empty when the locale chunk fails to load', async () => {
        const rejections: unknown[] = []
        const record = (reason: unknown) => rejections.push(reason)
        process.on('unhandledRejection', record)
        try {
            const names = useVectorCountryNames()
            await flushPromises()
            expect(names.value).toEqual({})
            expect(rejections).toEqual([])
        } finally {
            process.off('unhandledRejection', record)
        }
    })
})
