import { expect, test } from '../support/fixtures'

// kinetis/vector-ai installed and, as the demo ships, unconfigured: the
// `AI assistant` settings group is there with its provider-key panel,
// which says no provider is configured, and no other AI UI shows.
// Standalone only: the demo frontend extends @kinetis/vector-ai-ui, while
// the hosted bundle is plain @kinetis/vector-ui (hosted delivery of the
// AI layer comes in a later release).
test.describe('AI, unconfigured', () => {
    // a stack configured with a provider (ai-filters.standalone.spec.ts) is not the demo as it ships
    test.beforeEach(async ({ api }) => {
        test.skip((await api.aiCapabilities())?.filters === true, 'an AI provider is configured')
    })

    test('the AI settings group is there, with its provider-key panel', async ({ admin: page }) => {
        await page.goto('settings')

        const tab = page.getByRole('tab', { name: 'AI assistant' })
        await expect(tab).toBeVisible()
        await tab.click()

        await expect(page.getByLabel('Plain-language filters')).toBeVisible()
        const panel = page.locator('[data-settings-addon="ai"] [data-ai-credentials]')
        await expect(panel).toBeVisible()
        await expect(panel.getByRole('heading', { name: 'Provider keys' })).toBeVisible()
        await expect(panel.locator('[data-ai-no-provider]')).toHaveText('No AI provider is configured. The operator lists them in VECTOR_AI_PROVIDERS.')
        // no key is ever shown, and none can be entered without a provider
        await expect(panel.locator('input')).toHaveCount(0)
    })

    test('no other AI UI shows', async ({ admin: page }) => {
        const schema = page.waitForResponse(response => response.url().endsWith('/vector/schema') && response.request().method() === 'GET')
        await page.goto('r/products')
        const capabilities = (await (await schema).json() as { capabilities: { ai?: unknown } }).capabilities

        expect(capabilities.ai).toEqual({ filters: false, assistant: false, assistantWrites: false, writing: false, excluded: {}, excludedFields: {}, noWriting: {} })
        await expect(page.getByRole('heading', { name: 'Products', level: 1 })).toBeVisible()
        // no shell action, list-toolbar item or palette command is registered
        await expect(page.locator('[data-shell-action]')).toHaveCount(0)
        await expect(page.locator('[data-list-toolbar-item]')).toHaveCount(0)
        await expect(page.locator('[data-input-addon]')).toHaveCount(0)
    })
})
