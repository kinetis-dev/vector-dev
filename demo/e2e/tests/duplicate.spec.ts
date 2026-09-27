import { expect, test, unique } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Duplicate: a record's page opens a create form filled from it, without
// the values that must stay unique; saving makes a new record.

const form = (page: Page) => page.getByRole('main').locator('form')

test('duplicating a product fills a new form without its unique values', async ({ admin: page, api, cleanup }) => {
    const sku = unique('E2E-SKU').replace(/\s/g, '-')
    cleanup.byName('products', sku, true)
    const source = (await api.search('products', 'SKU-00042'))[0]!

    await page.goto(`r/products/${source.id}`)
    await page.getByRole('link', { name: 'Duplicate' }).click()

    await expect(page).toHaveURL(new RegExp(`/r/products/create\\?from=${source.id}$`))
    await expect(page.getByText(`A copy of “${source.name}”`)).toBeVisible()
    await expect(form(page).getByLabel('Name', { exact: true })).toHaveValue(String(source.name))
    await expect(form(page).getByLabel('SKU')).toHaveValue('')
    await expect(form(page).getByLabel('Slug')).toHaveValue('')

    await form(page).getByLabel('SKU').fill(sku)
    await form(page).getByLabel('Slug').fill(sku.toLowerCase())
    await form(page).getByRole('button', { name: 'Create', exact: true }).click()

    await expect(page).toHaveURL(/\/r\/products\/\d+$/)
    const copyId = page.url().split('/').pop()!
    expect(copyId).not.toBe(String(source.id))
    const copy = await api.show('products', copyId)
    const original = await api.show('products', source.id)
    expect(copy.name).toBe(original.name)
    expect(copy.priceCents).toBe(original.priceCents)
    expect(copy.sku).toBe(sku)
    expect((copy.tags as { count: number }).count).toBe((original.tags as { count: number }).count)
})
