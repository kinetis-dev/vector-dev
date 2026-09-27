import { expect, test, unique } from '../support/fixtures'
import type { Page } from '@playwright/test'

// The create and edit forms: leaving one with unsaved changes asks
// first; saving does not; the save variants and Ctrl/Cmd+S.

const form = (page: Page) => page.getByRole('main').locator('form')
const nav = (page: Page, name: string) => page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name, exact: true })

test.describe('unsaved changes', () => {
    test('leaving an edited form asks first, and cancelling keeps the edit', async ({ admin: page, api, cleanup }) => {
        const name = unique('E2E tag')
        cleanup.byName('tags', name)
        const tag = await api.create('tags', { name, color: '#1f5f8b' })

        await page.goto(`r/tags/${tag.id}/edit`)
        await form(page).getByLabel('Name').fill(`${name} changed`)
        await nav(page, 'Categories').click()

        const dialog = page.getByRole('dialog')
        await expect(dialog).toContainText('Discard your changes?')
        await dialog.getByRole('button', { name: 'Cancel' }).click()
        await expect(page).toHaveURL(new RegExp(`/r/tags/${tag.id}/edit$`))
        await expect(form(page).getByLabel('Name')).toHaveValue(`${name} changed`)

        await nav(page, 'Categories').click()
        await page.getByRole('dialog').getByRole('button', { name: 'Discard' }).click()
        await expect(page).toHaveURL(/\/r\/categories$/)
        expect((await api.show('tags', tag.id)).name).toBe(name)
    })

    test('an unchanged form, or a saved one, leaves without asking', async ({ admin: page, api, cleanup }) => {
        const name = unique('E2E tag')
        cleanup.byName('tags', name)
        const tag = await api.create('tags', { name, color: '#1f5f8b' })

        await page.goto(`r/tags/${tag.id}/edit`)
        await nav(page, 'Categories').click()
        await expect(page).toHaveURL(/\/r\/categories$/)

        await page.goto(`r/tags/${tag.id}/edit`)
        await form(page).getByLabel('Name').fill(`${name} saved`)
        await form(page).getByRole('button', { name: 'Save', exact: true }).click()
        await expect(page).toHaveURL(new RegExp(`/r/tags/${tag.id}$`))
        await expect(page.getByRole('dialog')).toHaveCount(0)
    })
})

test.describe('save variants', () => {
    test('create and add another reopens an empty form with the defaults, and is remembered', async ({ admin: page, cleanup }) => {
        const first = unique('E2E tag')
        cleanup.byName('tags', first)

        await page.goto('r/tags/create')
        await form(page).getByLabel('Name').fill(first)
        await form(page).getByLabel('Color').fill('#b45309')
        await form(page).getByRole('button', { name: 'More ways to save' }).click()
        await page.getByRole('option', { name: 'Create and add another' }).or(page.getByText('Create and add another')).click()

        await expect(page.getByText('Tag saved.')).toBeVisible()
        await expect(page).toHaveURL(/\/r\/tags\/create$/)
        await expect(form(page).getByLabel('Name')).toHaveValue('')
        await expect(form(page).getByLabel('Color')).toHaveValue('')
        await expect(form(page).getByRole('button', { name: 'Create and add another' })).toBeVisible()

        // a fresh form leaves without asking
        await nav(page, 'Tags').click()
        await expect(page).toHaveURL(/\/r\/tags$/)
    })

    test('create and continue editing opens the new record\'s edit form', async ({ admin: page, cleanup }) => {
        const name = unique('E2E tag')
        cleanup.byName('tags', name)

        await page.goto('r/tags/create')
        await form(page).getByLabel('Name').fill(name)
        await form(page).getByLabel('Color').fill('#2e7d32')
        await form(page).getByRole('button', { name: 'More ways to save' }).click()
        await page.getByText('Create and continue editing').click()

        await expect(page).toHaveURL(/\/r\/tags\/\d+\/edit$/)
        await expect(form(page).getByLabel('Name')).toHaveValue(name)
    })

    test('Ctrl/Cmd+S saves', async ({ admin: page, api, cleanup }) => {
        const name = unique('E2E tag')
        cleanup.byName('tags', name)
        const tag = await api.create('tags', { name, color: '#1f5f8b' })

        await page.goto(`r/tags/${tag.id}/edit`)
        await form(page).getByLabel('Name').fill(`${name} by keyboard`)
        await page.keyboard.press('ControlOrMeta+s')

        await expect(page).toHaveURL(new RegExp(`/r/tags/${tag.id}$`))
        expect((await api.show('tags', tag.id)).name).toBe(`${name} by keyboard`)
    })
})
