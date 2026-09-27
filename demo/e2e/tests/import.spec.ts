import { expect, test, unique } from '../support/fixtures'

// Import by a key column: a file that names an existing tag and a new
// one, imported as "Update or create" matched by the tag's unique name,
// updates the one and creates the other — through the worker.

test('an upsert import updates the matching record and creates the rest', async ({ admin: page, api, cleanup }) => {
    const existing = unique('E2E tag')
    const created = unique('E2E tag')
    cleanup.byName('tags', existing)
    cleanup.byName('tags', created)
    const tag = await api.create('tags', { name: existing, color: '#1f5f8b' })

    await page.goto('r/tags')
    await page.getByRole('button', { name: 'Import CSV' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.locator('input[type=file]').setInputFiles({
        name: 'tags.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`Name,Color\r\n${existing},#b45309\r\n${created},#2e7d32\r\n`),
    })
    await dialog.getByRole('button', { name: 'Analyse' }).click()

    // a Vuetify select opens from its field, not its hidden input
    await dialog.locator('.v-select').filter({ has: page.getByRole('combobox', { name: 'Rows' }) }).locator('.v-field').click()
    await page.getByRole('option', { name: 'Update or create' }).click()
    await expect(dialog.getByRole('combobox', { name: 'Match rows by' })).toHaveValue('Name')
    await dialog.getByRole('button', { name: 'Import 2 rows' }).click()

    await expect(page).toHaveURL(/\/jobs\/\d+$/)
    await expect(page.getByText('updated: 1')).toBeVisible({ timeout: 20_000 })
    await expect(page.getByText('created: 1')).toBeVisible()
    expect((await api.show('tags', tag.id)).color).toBe('#b45309')
    expect((await api.search('tags', created)).map(t => t.name)).toEqual([created])
})
