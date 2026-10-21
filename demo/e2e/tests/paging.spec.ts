import { expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Lists that do not count: orders are withoutCount (pages by number,
// no total), order lines cursorPaging (pages by cursor: no total, no
// page number, no sort). The seed has 400 orders and more lines than
// that, so both lists have pages after the first at the default 25.

// the records' rows: not the totals row orders add under them
const rows = (page: Page) => page.locator('.v-data-table tbody tr.v-data-table__tr')
/** The first row's text: what tells one page from another. */
const firstRow = async (page: Page) => (await rows(page).first().textContent()) ?? ''
const previous = (page: Page) => page.getByRole('button', { name: 'Previous page' })
const next = (page: Page) => page.getByRole('button', { name: 'Next page' })

test.describe('the order lines list, by cursor', () => {
    test.beforeEach(async ({ admin: page }) => {
        await page.goto('r/order-lines')
        await expect(rows(page)).toHaveCount(25)
    })

    test('pages forward and back by cursor, without a total or a page number', async ({ admin: page }) => {
        const first = await firstRow(page)
        await expect(page.getByText(/\d+-\d+ of \d+/)).toHaveCount(0)
        await expect(page.getByText(/^Page \d+$/)).toHaveCount(0)
        await expect(previous(page)).toBeDisabled()

        await next(page).click()
        await expect.poll(() => firstRow(page)).not.toBe(first)
        await expect(rows(page)).toHaveCount(25)
        const second = await firstRow(page)

        await next(page).click()
        await expect.poll(() => firstRow(page)).not.toBe(second)

        await previous(page).click()
        await expect.poll(() => firstRow(page)).toBe(second)
        await previous(page).click()
        await expect.poll(() => firstRow(page)).toBe(first)
        await expect(previous(page)).toBeDisabled()
    })

    test('offers no sort and no grouping', async ({ admin: page }) => {
        await expect(page.locator('.v-data-table thead th.v-data-table__th--sortable')).toHaveCount(0)
        await expect(page.getByLabel('Group by')).toHaveCount(0)
    })
})

test.describe('the orders list, uncounted', () => {
    test.beforeEach(async ({ admin: page }) => {
        await page.goto('r/orders')
        await expect(rows(page)).toHaveCount(25)
    })

    test('pages by number without a total or a last page', async ({ admin: page }) => {
        const first = await firstRow(page)
        await expect(page.getByText('Page 1', { exact: true })).toBeVisible()
        await expect(page.getByText(/\d+-\d+ of \d+/)).toHaveCount(0)
        await expect(page.getByRole('button', { name: 'Last page' })).toHaveCount(0)
        await expect(previous(page)).toBeDisabled()

        await next(page).click()
        await expect(page.getByText('Page 2', { exact: true })).toBeVisible()
        await expect.poll(() => firstRow(page)).not.toBe(first)
        await expect(rows(page)).toHaveCount(25)

        await previous(page).click()
        await expect(page.getByText('Page 1', { exact: true })).toBeVisible()
        await expect.poll(() => firstRow(page)).toBe(first)
    })

    test('starts again from the first page on a new sort', async ({ admin: page }) => {
        await next(page).click()
        await expect(page.getByText('Page 2', { exact: true })).toBeVisible()

        await page.locator('.v-data-table thead th').filter({ hasText: /^Number$/ }).click()

        await expect(page.getByText('Page 1', { exact: true })).toBeVisible()
    })

    test('selects all matching orders without a number', async ({ admin: page }) => {
        await rows(page).first().getByRole('checkbox').check()
        await page.getByRole('button', { name: 'Select all matching records' }).click()

        await expect(page.getByText('All matching records')).toBeVisible()
    })
})
