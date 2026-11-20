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

    test('a sentence previews as chips, and Apply filters the list', async ({ admin: page }) => {
        await page.goto('r/orders')
        const input = page.locator('[data-ai-filters-input] input')
        await expect(input).toBeVisible()

        await input.fill('paid orders over 500')
        await input.press('Enter')
        const preview = page.locator('[data-ai-filters-preview]')
        await expect(preview.locator('[data-ai-filters-explanation]')).toContainText('Paid orders with a total above 500.00.')
        // the FilterBar's own chips, before anything reaches the list
        await expect(preview).toContainText('Status')
        await expect(preview).toContainText('Total')

        const listed = page.waitForResponse(response => {
            const url = decodeURIComponent(response.url())
            return url.includes('/vector/r/orders?') && url.includes('filter[status][eq]=paid') && url.includes('filter[totalCents][gt]=50000')
        })
        await preview.locator('[data-ai-filters-apply]').click()
        const page1 = await (await listed).json() as { data: { status: string, totalCents: number }[] }

        expect(page1.data.length).toBeGreaterThan(0)
        for (const order of page1.data) {
            expect(order.status).toBe('paid')
            expect(order.totalCents).toBeGreaterThan(50000)
        }
        await expect(preview).toHaveCount(0)
    })

    test('Discard leaves the list as it was, and keeps the sentence', async ({ admin: page }) => {
        await page.goto('r/orders')
        const input = page.locator('[data-ai-filters-input] input')
        await input.fill('paid orders over 500')
        await input.press('Enter')
        await expect(page.locator('[data-ai-filters-preview]')).toBeVisible()

        await page.locator('[data-ai-filters-discard]').click()

        await expect(page.locator('[data-ai-filters-preview]')).toHaveCount(0)
        await expect(input).toHaveValue('paid orders over 500')
    })

    test('a resource kept from AI offers no filter', async ({ admin: page }) => {
        await page.goto('r/profiles')
        await expect(page.getByRole('heading', { name: 'Profiles', level: 1 })).toBeVisible()
        await expect(page.locator('[data-ai-filters]')).toHaveCount(0)
    })
})
