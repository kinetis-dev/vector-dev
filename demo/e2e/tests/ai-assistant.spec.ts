import { watchCsp } from '../support/csp'
import { expect, FAKE_AI, FAKE_AI_NEEDED, test } from '../support/fixtures'

// The read-only assistant against the demo's `fake` AI provider, which
// answers the question App\Vector\Ai\DemoAiReplies scripts: it reads the
// low-stock products with vector_query, then names one as `products#1`.
// Needs the stack configured with it (backend/.env: VECTOR_AI_PROVIDERS=demo,
// VECTOR_AI_DEMO_TYPE=fake); skipped unless VECTOR_E2E_FAKE_AI=1
// says so. ai.spec.ts covers the demo as it ships. In both projects;
// hosted, under the shell's strict CSP without a violation.
const QUESTION = 'Which products are low on stock?'
const ANSWER = 'I listed the active products with 10 or fewer in stock, lowest first.'

test.describe('AI, the assistant', () => {
    test.beforeEach(async ({ api }) => {
        test.skip(!FAKE_AI, FAKE_AI_NEEDED)
        expect((await api.aiCapabilities())?.assistant, 'VECTOR_E2E_FAKE_AI=1, but the stack reports no AI assistant').toBe(true)
    })

    test('opens from the app bar, streams the answer, and its record link opens the record', async ({ admin: page, mode }) => {
        const csp = await watchCsp(page)
        await page.goto('r/products')
        await page.locator('[data-shell-action="ai-assistant"]').click()

        const drawer = page.locator('[data-ai-assistant]')
        await expect(drawer).toBeVisible()
        await expect(drawer.locator('[data-ai-assistant-context]')).toHaveText('Products list')

        const streamed = page.waitForResponse(response => response.url().endsWith('/vector/ai/assistant') && response.request().method() === 'POST')
        await drawer.locator('[data-ai-assistant-input] textarea').fill(QUESTION)
        await drawer.locator('[data-ai-assistant-input] textarea').press('Enter')
        expect((await streamed).headers()['content-type']).toContain('text/event-stream')

        const answer = drawer.locator('[data-ai-assistant-answer]')
        await expect(answer).toContainText(ANSWER)
        await expect(drawer.locator('[data-ai-assistant-tools]')).toContainText('vector_query')

        const link = answer.locator('a[data-record-link="products#1"]')
        await expect(link).toBeVisible()
        // a link the router does not follow (a new tab) opens the record too: hosted, under /vector/ui
        await expect(link).toHaveAttribute('href', mode === 'hosted' ? '/vector/ui/r/products/1' : '/r/products/1')
        await link.click()
        await expect(page).toHaveURL(/\/r\/products\/1$/)
        // the drawer is the layout's: the conversation is still there on the record's page
        await expect(drawer.locator('[data-ai-assistant-answer]')).toContainText(ANSWER)
        await expect(drawer.locator('[data-ai-assistant-context]')).toHaveText('Product #1')
        expect(await csp()).toEqual([])
    })

    test('Ctrl+J opens the drawer, and closes it from its own input', async ({ admin: page }) => {
        const csp = await watchCsp(page)
        await page.goto('')
        // the shortcut is listened for once the admin has rendered
        await expect(page.getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        const toggle = page.locator('[data-ai-assistant-toggle]')
        await expect(toggle).toHaveAttribute('aria-expanded', 'false')

        await page.keyboard.press('Control+J')
        await expect(toggle).toHaveAttribute('aria-expanded', 'true')
        const input = page.locator('[data-ai-assistant] [data-ai-assistant-input] textarea')
        await input.fill(QUESTION)
        await input.press('Enter')
        await expect(page.locator('[data-ai-assistant-answer]')).toContainText(ANSWER)

        await input.press('Control+J')
        await expect(toggle).toHaveAttribute('aria-expanded', 'false')
        // the command palette has no entry of the assistant's own
        await page.keyboard.press('Control+K')
        await page.getByRole('dialog').getByRole('textbox', { name: 'Search' }).fill(QUESTION)
        await expect(page.getByRole('dialog').getByText('Ask the assistant…')).toHaveCount(0)
        expect(await csp()).toEqual([])
    })
})
