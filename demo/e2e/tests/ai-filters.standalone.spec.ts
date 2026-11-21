import { expect, test } from '../support/fixtures'

// Plain-language filters against the demo's `fake` AI provider, which
// answers the sentences App\Vector\Ai\DemoAiReplies scripts. Needs the
// stack configured with it (backend/.env: VECTOR_AI_PROVIDERS=demo,
// VECTOR_AI_DEMO_TYPE=fake); skipped against the demo as it ships, which
// ai.standalone.spec.ts covers. Standalone only: the hosted bundle is
// the plain admin.
test.describe('AI, plain-language filters', () => {
    test.beforeEach(async ({ api }) => {
        test.skip((await api.aiCapabilities())?.filters !== true, 'needs a fake AI provider: VECTOR_AI_PROVIDERS=demo, VECTOR_AI_DEMO_TYPE=fake')
    })

    test('a sentence filters the list at once, and Undo puts it back', async ({ admin: page }) => {
        await page.goto('r/orders')
        const input = page.locator('[data-ai-filters-input] input')
        await expect(input).toBeVisible()

        const listed = page.waitForResponse(response => {
            const url = decodeURIComponent(response.url())
            return url.includes('/vector/r/orders?') && url.includes('filter[status][eq]=paid') && url.includes('filter[totalCents][gt]=50000')
        })
        await input.fill('paid orders over 500')
        await input.press('Enter')
        const page1 = await (await listed).json() as { data: { status: string, totalCents: number }[] }

        expect(page1.data.length).toBeGreaterThan(0)
        for (const order of page1.data) {
            expect(order.status).toBe('paid')
            expect(order.totalCents).toBeGreaterThan(50000)
        }
        const note = page.locator('[data-ai-filters-note]')
        await expect(note.locator('[data-ai-filters-explanation]')).toContainText('Paid orders with a total above 500.00.')
        // the sentence stays, to be refined and sent again
        await expect(input).toHaveValue('paid orders over 500')

        const unfiltered = page.waitForResponse(response => {
            const url = decodeURIComponent(response.url())
            return url.includes('/vector/r/orders?') && !url.includes('filter[')
        })
        await note.locator('[data-ai-filters-undo]').click()
        await unfiltered
        await expect(note).toHaveCount(0)
    })

    test('a resource kept from AI offers no filter', async ({ admin: page }) => {
        await page.goto('r/profiles')
        await expect(page.getByRole('heading', { name: 'Profiles', level: 1 })).toBeVisible()
        await expect(page.locator('[data-ai-filters]')).toHaveCount(0)
    })
})
