import { ADMIN, expect, test } from '../support/fixtures'

// Writing help against the demo's `fake` AI provider, which shortens the
// two texts App\Vector\Ai\DemoAiReplies scripts: an order's notes, under
// their textarea, and a product description's paragraph, selected in the
// rich-text editor. The answer shows beside the text; Replace puts it in
// the input, and the admin saves it as their own edit. Needs the stack
// configured with it (backend/.env: VECTOR_AI_PROVIDERS=demo,
// VECTOR_AI_DEMO_TYPE=fake); skipped against the demo as it ships, which
// ai.standalone.spec.ts covers. Standalone only: the hosted bundle is
// the plain admin.
const LONG_NOTES = 'Please make sure to call the customer before the delivery, because the customer has asked us to call ahead before the parcel is delivered.'
const SHORT_NOTES = 'Call the customer before delivery.'
const LONG_DESCRIPTION = 'This lamp gives a warm and pleasant light that is very suitable for reading in the evening and for other quiet activities at home.'
const SHORT_DESCRIPTION = 'A warm reading lamp for quiet evenings.'

test.describe('AI, writing help', () => {
    test.beforeEach(async ({ api }) => {
        test.skip((await api.aiCapabilities())?.writing !== true, 'needs a fake AI provider: VECTOR_AI_PROVIDERS=demo, VECTOR_AI_DEMO_TYPE=fake')
    })

    test('shortens an order\'s notes, and the save is the admin\'s own edit', async ({ admin: page, api }) => {
        const id = (await api.search('orders', ''))[0]!.id
        const before = (await api.show('orders', id)).notes

        try {
            await page.goto(`r/orders/${id}/edit`)
            const notes = page.getByRole('textbox', { name: 'Notes' })
            await notes.fill(LONG_NOTES)
            // the addons under the notes' input, which follow it
            const addon = notes.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " v-input ")][1]/following-sibling::div[contains(concat(" ", normalize-space(@class), " "), " vx-input-addons ")][1]').locator('[data-input-addon="ai-writing"]')

            const asked = page.waitForResponse(response => response.url().endsWith('/vector/ai/writing/orders/notes') && response.request().method() === 'POST')
            await addon.locator('[data-ai-writing-menu]').click()
            await page.locator('[data-ai-writing-operation="shorten"]').click()
            expect((await asked).request().postDataJSON()).toMatchObject({ operation: 'shorten', text: LONG_NOTES, format: 'plain', recordId: String(id) })

            await expect(addon.locator('[data-ai-writing-before]')).toHaveText(LONG_NOTES)
            await expect(addon.locator('[data-ai-writing-after]')).toHaveText(SHORT_NOTES)
            // nothing is in the input until Replace
            await expect(notes).toHaveValue(LONG_NOTES)
            await addon.locator('[data-ai-writing-replace]').click()
            await expect(notes).toHaveValue(SHORT_NOTES)

            await page.getByRole('button', { name: 'Save', exact: true }).click()
            await expect(page).toHaveURL(new RegExp(`/r/orders/${id}$`))
            expect((await api.show('orders', id)).notes).toBe(SHORT_NOTES)

            const [latest] = await api.audit('orders', id)
            expect(latest!.event).toBe('update')
            // the signed-in admin's own update
            const admin = (await api.search('users', ADMIN.email)).find(user => user.email === ADMIN.email)!
            expect(latest!.actorId).toBe(String(admin.id))
            expect(latest!.origin ?? null).toBeNull()
            expect(Object.keys(latest!.changes)).toContain('notes')
        } finally {
            await api.update('orders', id, { notes: before })
        }
    })

    test('shortens a selection in a rich-text description, and saves it', async ({ admin: page, api }) => {
        const product = (await api.search('products', 'SKU-00044'))[0]!
        const before = (await api.show('products', product.id)).description

        try {
            await page.goto(`r/products/${product.id}/edit`)
            await page.getByRole('tab', { name: 'Content' }).click()
            const editor = page.getByRole('textbox', { name: 'Description' })
            const menu = page.locator('[data-editor-toolbar-item="ai-writing"] [data-ai-writing-menu]')
            await editor.click()
            await page.keyboard.press('ControlOrMeta+a')
            await page.keyboard.type(LONG_DESCRIPTION)
            // nothing selected, nothing to work on
            await page.keyboard.press('End')
            await expect(menu).toBeDisabled()

            await editor.locator('p').first().click({ clickCount: 3 })
            await expect(menu).toBeEnabled()
            const asked = page.waitForResponse(response => response.url().endsWith('/vector/ai/writing/products/description') && response.request().method() === 'POST')
            await menu.click()
            await page.locator('[data-ai-writing-operation="shorten"]').click()
            expect((await asked).request().postDataJSON()).toMatchObject({ operation: 'shorten', text: LONG_DESCRIPTION, format: 'plain', recordId: String(product.id) })

            const result = page.locator('[data-ai-writing-result]')
            await expect(result.locator('[data-ai-writing-after]')).toHaveText(SHORT_DESCRIPTION)
            await result.locator('[data-ai-writing-replace]').click()
            await expect(editor).toHaveText(SHORT_DESCRIPTION)

            await page.getByRole('button', { name: 'Save', exact: true }).click()
            await expect(page).toHaveURL(new RegExp(`/r/products/${product.id}$`))
            const stored = JSON.parse(String((await api.show('products', product.id)).description))
            expect(JSON.stringify(stored)).toContain(SHORT_DESCRIPTION)
            expect(JSON.stringify(stored)).not.toContain(LONG_DESCRIPTION)
            expect((await api.audit('products', product.id))[0]!.origin ?? null).toBeNull()
        } finally {
            await api.update('products', product.id, { description: before })
        }
    })
})
