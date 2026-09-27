import { expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// A relation's choice list tells records apart: a customer shows its
// e-mail under the name, a product its SKU and its image — fetched with
// the admin's credentials and shown under the hosted CSP.

async function choose(page: Page, label: string, search: string) {
    const field = page.getByRole('main').locator('.v-autocomplete').filter({ has: page.getByLabel(label, { exact: true }) }).first()
    await field.locator('.v-field').click()
    await page.keyboard.type(search)
    return page.locator('.v-overlay--active .v-list')
}

test('a customer choice shows its e-mail', async ({ admin: page, api }) => {
    const customer = (await api.search('customers', ''))[0]!
    await page.goto('r/orders/create')

    const list = await choose(page, 'Customer', String(customer.name))
    const option = list.getByRole('option').filter({ hasText: String(customer.name) }).first()
    await expect(option).toBeVisible()
    await expect(option.locator('.v-list-item-subtitle')).toContainText('@')
})

test('a product choice shows its SKU and its image', async ({ admin: page }) => {
    const violations: string[] = []
    page.on('console', message => { if (/Content Security Policy/i.test(message.text())) violations.push(message.text()) })
    await page.goto('r/orders/create') // an order starts with one line (its repeater's minimum)

    const list = await choose(page, 'Product', 'a')
    const option = list.getByRole('option').first()
    await expect(option.locator('.v-list-item-subtitle')).toContainText('SKU-')
    await expect(list.locator('.v-avatar img').first()).toHaveAttribute('src', /^data:image\//, { timeout: 10_000 })
    expect(violations).toEqual([])
})
