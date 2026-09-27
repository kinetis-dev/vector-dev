import { expect, test } from '../support/fixtures'

// The export menu: with XLSX offered, the list's export button opens a
// menu of formats; an Excel export is prepared by the worker and
// downloaded from its job.

test('an Excel export is prepared as a job and downloads as an XLSX workbook', async ({ admin: page }) => {
    await page.goto('r/products')
    await page.getByRole('button', { name: 'Export', exact: true }).click()
    await expect(page.getByRole('listitem').filter({ hasText: /^CSV$/ })).toBeVisible()
    await page.getByRole('listitem').filter({ hasText: 'Excel (XLSX)' }).click()

    await expect(page.getByText('The export is being prepared')).toBeVisible()
    await expect(page).toHaveURL(/\/jobs\/\d+$/)
    const download = page.getByRole('button', { name: /^Download products-.*\.xlsx$/ })
    await expect(download).toBeVisible({ timeout: 30_000 })

    const [file] = await Promise.all([page.waitForEvent('download'), download.click()])
    expect(file.suggestedFilename()).toMatch(/^products-\d{8}-\d{6}\.xlsx$/)
    const bytes = await (await file.createReadStream()).toArray()
    expect(Buffer.concat(bytes).subarray(0, 2).toString()).toBe('PK') // a zip: the workbook
})
