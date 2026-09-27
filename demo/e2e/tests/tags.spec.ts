import { expect, test, unique } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Tags: a name (required, at most 60 characters) and a colour (required).
// Every tag a test creates is named uniquely and removed afterwards.

const form = (page: Page) => page.getByRole('main').locator('form')

test.describe('tags', () => {
    test('create, read, update and delete', async ({ admin: page, cleanup }) => {
        const name = unique('E2E tag')
        const renamed = `${name} renamed`
        cleanup.byName('tags', name)

        // create
        await page.goto('r/tags')
        await page.getByRole('link', { name: 'New tag' }).click()
        await expect(page.getByRole('heading', { name: 'New tag', level: 1 })).toBeVisible()
        await form(page).getByLabel('Name').fill(name)
        await form(page).getByLabel('Color').fill('#1f5f8b')
        await form(page).getByRole('button', { name: 'Create', exact: true }).click()

        // read: the new record's page
        await expect(page.getByText('Tag saved.')).toBeVisible()
        await expect(page).toHaveURL(/\/r\/tags\/\d+$/)
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
        await expect(page.getByRole('main').getByText('#1f5f8b').first()).toBeVisible()

        // update
        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page.getByRole('heading', { name: `Edit ${name}`, level: 1 })).toBeVisible()
        await expect(form(page).getByLabel('Name')).toHaveValue(name)
        await form(page).getByLabel('Name').fill(renamed)
        await form(page).getByRole('button', { name: 'Save', exact: true }).click()
        await expect(page).toHaveURL(/\/r\/tags\/\d+$/)
        await expect(page.getByRole('heading', { name: renamed, level: 1 })).toBeVisible()

        // it lists under its new name
        await page.goto('r/tags')
        await page.getByRole('textbox', { name: 'Search' }).fill(renamed)
        await expect(page.locator('.v-data-table tbody tr').filter({ hasText: renamed })).toHaveCount(1)

        // delete, from its page, after confirming
        await page.locator('.v-data-table tbody tr').filter({ hasText: renamed }).click()
        await page.getByRole('button', { name: 'Delete' }).click()
        const dialog = page.getByRole('dialog')
        await expect(dialog).toContainText(`Delete tag “${renamed}”?`)
        await expect(dialog).toContainText('This cannot be undone.')
        await dialog.getByRole('button', { name: 'Delete' }).click()

        await expect(page.getByText('Tag deleted.')).toBeVisible()
        await expect(page).toHaveURL(/\/r\/tags$/)
        await page.getByRole('textbox', { name: 'Search' }).fill(renamed)
        await expect(page.getByText('Nothing matches')).toBeVisible()
    })

    test('a delete can be cancelled', async ({ admin: page, api, cleanup }) => {
        const name = unique('E2E tag')
        cleanup.byName('tags', name)
        const tag = await api.create('tags', { name, color: '#b45309' })

        await page.goto(`r/tags/${tag.id}`)
        await page.getByRole('button', { name: 'Delete' }).click()
        await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()

        await expect(page.getByRole('dialog')).toBeHidden()
        await page.reload()
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
    })

    test('the form checks what it can before saving', async ({ admin: page, api }) => {
        await page.goto('r/tags/create')

        await form(page).getByRole('button', { name: 'Create', exact: true }).click()
        await expect(form(page).getByText('Required')).toHaveCount(2)

        await form(page).getByLabel('Name').fill('x'.repeat(61))
        await form(page).getByLabel('Color').fill('#000000')
        await form(page).getByRole('button', { name: 'Create', exact: true }).click()
        await expect(form(page).getByText('At most 60 characters')).toBeVisible()
        await expect(page).toHaveURL(/\/r\/tags\/create$/)

        expect(await api.search('tags', 'x'.repeat(61))).toHaveLength(0)
    })

    test('cancel leaves the form without saving, once the changes are discarded', async ({ admin: page, api }) => {
        await page.goto('r/tags/create')
        await form(page).getByLabel('Name').fill('never saved')

        await form(page).getByRole('button', { name: 'Cancel' }).click()
        await page.getByRole('dialog').getByRole('button', { name: 'Discard' }).click()

        await expect(page).toHaveURL(/\/r\/tags$/)
        expect(await api.search('tags', 'never saved')).toHaveLength(0)
    })
})
