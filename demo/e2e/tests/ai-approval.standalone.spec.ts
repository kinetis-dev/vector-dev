import { ADMIN, expect, test } from '../support/fixtures'

// The assistant proposing changes, against the demo's `fake` AI provider,
// with `assistantWrites` turned on for the test: App\Vector\Ai\DemoAiReplies
// answers a restocking request by reading products#9 with vector_get and
// proposing its stock with vector_update. The proposal shows as a card
// with the change field by field; approving it writes the change as the
// admin, which History shows "via assistant", and the assistant then
// acknowledges the decision. A second proposal is rejected and changes
// nothing. Needs the stack configured with the fake provider
// (backend/.env: VECTOR_AI_PROVIDERS=demo, VECTOR_AI_DEMO_TYPE=fake);
// skipped against the demo as it ships, which ai.standalone.spec.ts
// covers. Standalone only: the hosted bundle is the plain admin.
const PRODUCT = 9
const RESTOCK = 'Set the stock of products#9 to 42.'
const RESTOCK_AGAIN = 'Set the stock of products#9 to 7.'
const RESTOCK_ANSWER = 'I proposed the new stock of products#9; it changes once you approve it.'
const DECIDED_ANSWER = 'Noted: your decision on the change I proposed is recorded above.'

test.describe('AI, the assistant proposing changes', () => {
    // what the test changes, put back after it whatever happened — a timeout included
    let settings: Record<string, unknown> | null = null
    let stock: unknown = null

    test.beforeEach(async ({ api }) => {
        test.skip((await api.aiCapabilities())?.assistant !== true, 'needs a fake AI provider: VECTOR_AI_PROVIDERS=demo, VECTOR_AI_DEMO_TYPE=fake')
        settings = await api.settingsValues('ai')
        stock = (await api.show('products', PRODUCT)).stock
    })

    test.afterEach(async ({ api }) => {
        if (stock !== null) await api.update('products', PRODUCT, { stock })
        if (settings !== null) await api.settings('ai', settings)
        settings = null
        stock = null
    })

    test('a proposal approved is written via the assistant; a second one rejected changes nothing', async ({ admin: page, api }) => {
        expect(stock).not.toBe(42)
        await api.settings('ai', { ...settings, assistantWrites: true })
        await page.goto(`r/products/${PRODUCT}`)
        await page.locator('[data-shell-action="ai-assistant"]').click()
        const drawer = page.locator('[data-ai-assistant]')
        await expect(drawer).toBeVisible()
        const input = drawer.locator('[data-ai-assistant-input] textarea')

        // the proposal: a card from the structured change, nothing written yet
        await input.fill(RESTOCK)
        await input.press('Enter')
        await expect(drawer.locator('[data-ai-assistant-answer]').last()).toContainText(RESTOCK_ANSWER)
        const first = drawer.locator('[data-ai-approval]').first()
        await expect(first).toHaveAttribute('data-ai-approval-state', 'pending')
        await expect(first.locator('[data-ai-approval-title]')).toHaveText(`Proposed: change Product #${PRODUCT}`)
        const row = first.locator('[data-ai-approval-field="stock"]')
        await expect(row.locator('[data-ai-approval-from]')).toHaveText(String(stock))
        await expect(row.locator('[data-ai-approval-to]')).toHaveText('42')
        expect((await api.show('products', PRODUCT)).stock).toBe(stock)

        // approved: written as the admin, and the assistant acknowledges it
        const approved = page.waitForResponse(response => /\/vector\/ai\/approvals\/apr_[a-z2-7]{26}\/approve$/.test(response.url()) && response.request().method() === 'POST')
        await first.locator('[data-ai-approval-approve]').click()
        expect((await approved).status()).toBe(200)
        await expect(first).toHaveAttribute('data-ai-approval-state', 'executed')
        await expect(drawer.locator('[data-ai-assistant-answer]').last()).toContainText(DECIDED_ANSWER)
        expect((await api.show('products', PRODUCT)).stock).toBe(42)

        const [latest] = await api.audit('products', PRODUCT)
        const admin = (await api.search('users', ADMIN.email)).find(user => user.email === ADMIN.email)!
        expect(latest!.event).toBe('update')
        expect(latest!.actorId).toBe(String(admin.id))
        expect(latest!.origin).toBe('assistant')

        // History says so
        await page.reload()
        const history = page.locator('.v-card').filter({ has: page.locator('.v-card-title', { hasText: /^History$/ }) })
        await expect(history.locator('[data-audit-origin="assistant"]').first()).toHaveText('via assistant')

        // a second proposal, rejected: nothing changes (the reload started a new conversation, the drawer closed)
        const toggle = page.locator('[data-shell-action="ai-assistant"]')
        await expect(toggle).toHaveAttribute('aria-expanded', 'false')
        await toggle.click()
        await expect(drawer.locator('[data-ai-assistant-empty]')).toBeVisible()
        await input.fill(RESTOCK_AGAIN)
        await input.press('Enter')
        const second = drawer.locator('[data-ai-approval][data-ai-approval-state="pending"]')
        await expect(second).toHaveCount(1)
        await expect(second.locator('[data-ai-approval-field="stock"] [data-ai-approval-to]')).toHaveText('7')
        const rejected = page.waitForResponse(response => /\/vector\/ai\/approvals\/apr_[a-z2-7]{26}\/reject$/.test(response.url()) && response.request().method() === 'POST')
        await second.locator('[data-ai-approval-reject]').click()
        expect((await rejected).status()).toBe(200)
        await expect(drawer.locator('[data-ai-approval][data-ai-approval-state="rejected"]')).toHaveCount(1)
        await expect(drawer.locator('[data-ai-assistant-answer]').last()).toContainText(DECIDED_ANSWER)
        expect((await api.show('products', PRODUCT)).stock).toBe(42)
    })
})
