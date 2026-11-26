import { expect, test } from '../support/fixtures'
import { watchCsp } from '../support/csp'
import type { Page } from '@playwright/test'

// Two field types in a real browser: a team's routing rules in the code
// editor (under the hosted shell's strict CSP too), and a product's
// promotion period as a date range.

const form = (page: Page) => page.getByRole('main').locator('form')

test.describe('code editor', () => {
    test('edits a team\'s routing rules as YAML, saved as typed', async ({ admin: page, api }) => {
        const team = (await api.search('support-teams', 'Billing'))[0]!
        const before = (await api.show('support-teams', team.id)).routingRules ?? null
        // the editor's style element must pass the hosted shell's strict policy, or it edits without its layout
        const csp = await watchCsp(page)

        try {
            await page.goto(`r/support-teams/${team.id}/edit`)
            const editor = form(page).getByRole('textbox', { name: 'Routing rules' })
            await expect(editor).toContainText('assign: round-robin')

            // highlighted as YAML: a key is a token of its own
            await expect(form(page).locator('.vx-code .tok-propertyName, .vx-code .tok-atom').first()).toBeVisible()

            await editor.click()
            await page.keyboard.press('ControlOrMeta+a')
            // typed over the whole text, as a user types: keys, and Enter, which the editor handles itself
            await page.keyboard.type('assign: least-busy')
            await page.keyboard.press('Enter')
            await page.keyboard.type('escalate_after: 8h')
            await form(page).getByRole('button', { name: 'Save', exact: true }).click()
            await expect(page).toHaveURL(new RegExp(`/r/support-teams/${team.id}$`))

            expect((await api.show('support-teams', team.id)).routingRules).toBe('assign: least-busy\nescalate_after: 8h')
            // the record page shows it in a read-only editor
            await expect(page.getByRole('main').locator('.vx-code-view .cm-content')).toContainText('escalate_after: 8h')
            await expect(page.getByRole('main').locator('.vx-code-view .cm-content')).toHaveAttribute('aria-readonly', 'true')
            expect(await csp()).toEqual([])
        } finally {
            await api.update('support-teams', team.id, { routingRules: before })
        }
    })
})

test.describe('date range', () => {
    test('saves a product\'s promotion as both ends, and refuses one end alone', async ({ admin: page, api }) => {
        const product = (await api.search('products', 'SKU-00001'))[0]!
        const before = (await api.show('products', product.id)).promotion ?? null

        try {
            await api.update('products', product.id, { promotion: null })
            await page.goto(`r/products/${product.id}/edit`)
            await page.getByRole('tab', { name: 'Publishing' }).click()
            const range = form(page).locator('[data-date-range="promotion"]')
            await expect(range.locator('legend')).toHaveText('Promotion')

            // one end alone is refused before anything is sent
            await range.getByRole('textbox', { name: 'From' }).fill('2026-12-01')
            await form(page).getByRole('button', { name: 'Save', exact: true }).click()
            await expect(form(page)).toContainText('Set both ends of the range, or neither')
            expect((await api.show('products', product.id)).promotion).toBeNull()

            await range.getByRole('textbox', { name: 'To' }).fill('2026-12-24')
            await form(page).getByRole('button', { name: 'Save', exact: true }).click()
            await expect(page).toHaveURL(new RegExp(`/r/products/${product.id}$`))

            expect((await api.show('products', product.id)).promotion).toEqual({ from: '2026-12-01', to: '2026-12-24' })
            await page.getByRole('tab', { name: 'Publishing' }).click()
            await expect(page.getByRole('main')).toContainText(/2026 to .*2026/)
        } finally {
            await api.update('products', product.id, { promotion: before })
        }
    })
})
