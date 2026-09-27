import { expect, test, unique } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Rich text in a real browser: the product description (a document) and
// ticket replies (Markdown), rendered through the schema and sanitized,
// edited through the toolbar — and, hosted, under the strict CSP without
// a single violation.

/** Collects every CSP violation the page reports, from before its first script. */
async function watchCsp(page: Page) {
    await page.addInitScript(() => {
        const seen: string[] = []
        ;(window as unknown as { __csp: string[] }).__csp = seen
        document.addEventListener('securitypolicyviolation', event => seen.push(`${event.violatedDirective} ${event.blockedURI}`))
    })
    return () => page.evaluate(() => (window as unknown as { __csp: string[] }).__csp)
}

async function productId(page: Page, sku: string): Promise<string> {
    await page.goto('r/products')
    await page.getByRole('textbox', { name: 'Search' }).fill(sku)
    await page.locator('.v-data-table tbody tr').filter({ hasText: sku }).getByText(sku).click()
    await expect(page).toHaveURL(/\/r\/products\/\d+$/)
    return page.url().split('/').pop()!
}

test.describe('rich text', () => {
    test('a product description shows as formatted text', async ({ admin: page }) => {
        const csp = await watchCsp(page)
        await productId(page, 'SKU-00042')

        const view = page.locator('.vx-richtext-view')
        await expect(view.locator('strong')).toHaveText('Silent Monitors 42')
        await expect(view.locator('ul li')).toHaveText(['Two-year warranty', 'Free returns within 30 days'])
        const link = view.getByRole('link', { name: 'care guide' })
        await expect(link).toHaveAttribute('href', 'https://example.com/care')
        await expect(link).toHaveAttribute('target', '_blank')
        await expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow')
        expect(await csp()).toEqual([])
    })

    test('the editor formats and saves a description', async ({ admin: page, api }) => {
        const csp = await watchCsp(page)
        const id = await productId(page, 'SKU-00043')
        const before = (await api.show('products', id)).description

        try {
            await page.goto(`r/products/${id}/edit`)
            await page.getByRole('tab', { name: 'Content' }).click()
            const editor = page.getByRole('textbox', { name: 'Description' })
            await editor.click()
            await page.keyboard.press('ControlOrMeta+a')
            await page.keyboard.type('Fresh copy ')
            await page.getByRole('button', { name: 'Bold' }).click()
            await page.keyboard.type('with bold')
            await page.getByRole('button', { name: 'Save' }).click()

            await expect(page).toHaveURL(new RegExp(`/r/products/${id}$`))
            await expect(page.locator('.vx-richtext-view strong')).toHaveText('with bold')
            const stored = JSON.parse(String((await api.show('products', id)).description))
            expect(stored.type).toBe('doc')
            expect(JSON.stringify(stored)).toContain('"type":"bold"')
            expect(await csp()).toEqual([])
        } finally {
            await api.update('products', id, { description: before })
        }
    })

    test('a Markdown reply renders safely whatever it holds', async ({ admin: page, api, cleanup }) => {
        const author = unique('E2E author')
        cleanup.byName('ticket-replies', author)
        const ticket = (await api.search('tickets', ''))[0]!
        const reply = await api.create('ticket-replies', {
            ticket: ticket.id,
            author,
            isInternal: false,
            body: '## Steps\n\n1. **Restart** the router\n2. Try again\n\n<script>window.__pwned = 1</script>\n<img src=x onerror="window.__pwned = 2">\n[click](javascript:window.__pwned=3) and https://example.com',
        })
        page.on('dialog', dialog => dialog.dismiss())

        await page.goto(`r/ticket-replies/${reply.id}`)
        const view = page.locator('.vx-richtext-view')
        await expect(view.locator('h2')).toHaveText('Steps')
        await expect(view.locator('ol li strong')).toHaveText('Restart')
        await expect(view.locator('script, img, iframe')).toHaveCount(0)
        await expect(view.locator('a[href^="javascript"]')).toHaveCount(0)
        await expect(view.locator('a[href="https://example.com"]')).toHaveCount(1)
        await expect(view).toContainText('<script>window.__pwned = 1</script>') // shown as text
        expect(await page.evaluate(() => (window as unknown as { __pwned?: number }).__pwned)).toBeUndefined()
    })
})
