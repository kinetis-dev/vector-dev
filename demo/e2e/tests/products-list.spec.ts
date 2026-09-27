import { expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// The seeded catalogue: 150 products, SKU-00001 to SKU-00150. No test
// creates products, so the list is known.

/** The product rows of the list (the summaries row has no SKU). */
const rows = (page: Page) => page.locator('.v-data-table tbody tr').filter({ hasText: /SKU-\d{5}/ })
const search = (page: Page) => page.getByRole('textbox', { name: 'Search' })

test.describe('the products list', () => {
    test.beforeEach(async ({ admin: page }) => {
        await page.goto('r/products')
        await expect(rows(page).first()).toBeVisible()
    })

    test('shows a page of 25 products', async ({ admin: page }) => {
        await expect(rows(page)).toHaveCount(25)
        await expect(page.getByText(/1-25 of 150/)).toBeVisible()
    })

    test('searches by SKU', async ({ admin: page }) => {
        await search(page).fill('SKU-00042')

        await expect(rows(page)).toHaveCount(1)
        await expect(rows(page).first()).toContainText('SKU-00042')

        await search(page).clear()
        await expect(rows(page)).toHaveCount(25)
    })

    test('says nothing matches a search that finds nothing', async ({ admin: page }) => {
        await search(page).fill('no product is called this')

        await expect(page.getByText('Nothing matches')).toBeVisible()
        await expect(rows(page)).toHaveCount(0)
    })

    test('sorts by a column, both ways', async ({ admin: page }) => {
        const header = page.locator('.v-data-table thead th').filter({ hasText: /^SKU$/ })

        await header.click()
        await expect(rows(page).first()).toContainText('SKU-00001')

        await header.click()
        await expect(rows(page).first()).toContainText('SKU-00150')
    })

    test('pages through the list', async ({ admin: page }) => {
        await page.getByRole('button', { name: 'Next page' }).click()

        await expect(page.getByText(/26-50 of 150/)).toBeVisible()
        await expect(rows(page)).toHaveCount(25)
    })

    test('opens a product from its row', async ({ admin: page }) => {
        await search(page).fill('SKU-00042')
        await expect(rows(page)).toHaveCount(1)

        // on the SKU: the row's middle is the category cell, a link to the category
        await rows(page).first().getByText('SKU-00042').click()

        await expect(page).toHaveURL(/\/r\/products\/\d+$/)
        await expect(page.getByRole('main').getByText('SKU-00042')).toBeVisible()
        await expect(page.getByRole('main').getByText('Product', { exact: true })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Edit' }).or(page.getByRole('link', { name: 'Edit' }))).toBeVisible()
    })

    // The list asks the API for the visible columns only; the API returns
    // each row's key whatever they are, so the row still opens its record.
    test('opens a product from its row with the ID column hidden', async ({ admin: page, api }) => {
        await api.preferences({ 'columns.products': ['sku', 'name'] })
        await page.goto('r/products')
        await search(page).fill('SKU-00042')
        await expect(rows(page)).toHaveCount(1)

        // on the SKU: the row's middle is the category cell, a link to the category
        await rows(page).first().getByText('SKU-00042').click()

        await expect(page).toHaveURL(/\/r\/products\/\d+$/)
        await expect(page.getByRole('main').getByText('SKU-00042')).toBeVisible()
    })
})
