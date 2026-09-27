import { expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Each user's language: switched from the app bar, remembered as their
// preference, applied to the UI's strings and the application's labels
// alike — and Hebrew turns the page right to left.

const languageMenu = (page: Page) => page.getByRole('banner').locator('button:has(.mdi-translate)')
const nav = (page: Page) => page.getByRole('navigation').first()

test('switching language translates the admin and is remembered', async ({ admin: page, api }) => {
    try {
        await page.goto('')
        await expect(nav(page).getByRole('link', { name: 'Categories', exact: true })).toBeVisible()
        await expect(languageMenu(page)).toHaveAccessibleName('Language: English')

        await languageMenu(page).click()
        await page.getByRole('listitem').filter({ hasText: 'Deutsch' }).click()

        await expect(page.locator('html')).toHaveAttribute('lang', 'de')
        await expect(nav(page).getByRole('link', { name: 'Kategorien', exact: true })).toBeVisible()
        await expect(languageMenu(page)).toHaveAccessibleName('Sprache: Deutsch')

        // a preference: a new visit opens in German
        await page.reload()
        await expect(nav(page).getByRole('link', { name: 'Kategorien', exact: true })).toBeVisible()
    } finally {
        await api.preferences({ locale: null })
    }
})

test('the dashboard and its widgets change language without a reload', async ({ admin: page, api }) => {
    try {
        await page.goto('')
        const main = page.getByRole('main')
        await expect(main.getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        await expect(main.getByText('Orders by status', { exact: true })).toBeVisible()
        await expect(main.getByText('Paid', { exact: true }).first()).toBeVisible()

        await languageMenu(page).click()
        await page.getByRole('listitem').filter({ hasText: 'Deutsch' }).click()

        // the dashboard's title, each card's title and its data's labels, all fetched again in German
        await expect(main.getByRole('heading', { name: 'Übersicht', level: 1 })).toBeVisible()
        await expect(main.getByText('Bestellungen nach Status', { exact: true })).toBeVisible()
        await expect(main.getByText('Bezahlt', { exact: true }).first()).toBeVisible()
        await expect(main.getByText('Orders by status', { exact: true })).toHaveCount(0)
    } finally {
        await api.preferences({ locale: null })
    }
})

test('the language menu follows the Languages settings as soon as they are saved', async ({ admin: page, api }) => {
    try {
        await page.goto('settings')
        await page.getByRole('tab', { name: 'Languages' }).click()
        await languageMenu(page).click()
        await expect(page.getByRole('listitem').filter({ hasText: 'Deutsch' })).toBeVisible()
        await page.keyboard.press('Escape')

        await page.getByLabel('Deutsch', { exact: true }).click()
        await page.getByRole('button', { name: 'Save', exact: true }).click()
        await expect(page.getByText('Languages saved.')).toBeVisible()

        // no reload: the same menu, without the language just switched off
        await languageMenu(page).click()
        await expect(page.getByRole('listitem').filter({ hasText: 'English' })).toBeVisible()
        await expect(page.getByRole('listitem').filter({ hasText: 'Deutsch' })).toHaveCount(0)
    } finally {
        await api.settings('languages', {})
    }
})

test('Hebrew lays the admin out right to left', async ({ admin: page, api }) => {
    try {
        await page.goto('')
        await languageMenu(page).click()
        await page.getByRole('listitem').filter({ hasText: 'עברית' }).click()

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
        await expect(page.locator('html')).toHaveAttribute('lang', 'he')
        await expect(page.locator('.v-application')).toHaveClass(/v-locale--is-rtl/)
        // the drawer opens from the right edge
        const drawer = page.locator('.v-navigation-drawer')
        const box = await drawer.boundingBox()
        const width = page.viewportSize()!.width
        expect(box!.x + box!.width).toBeGreaterThan(width - 5)

        await languageMenu(page).click()
        await page.getByRole('listitem').filter({ hasText: 'English' }).click()
        await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
    } finally {
        await api.preferences({ locale: null })
    }
})
