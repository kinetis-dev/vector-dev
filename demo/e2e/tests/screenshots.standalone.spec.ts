import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { API, expect, test } from '../support/fixtures'
import type { Locator, Page } from '@playwright/test'

// The documentation's screenshots, not a test: docs/_static/screenshots/
// <name>-light.png and <name>-dark.png, one pair per screen. Skipped unless
// VECTOR_SCREENSHOTS=1, so the normal run neither captures nor slows down:
//
//   docker compose run --rm -e VECTOR_SCREENSHOTS=1 e2e test tests/screenshots.standalone.spec.ts
//
// Each pair is one prepared state captured twice: the admin's theme is
// Vuetify's "system" theme (while the Branding settings leave it at
// System), so switching the emulated prefers-color-scheme repaints the
// same page, dialog and selection in place. The `admin` fixture clears the
// suite account's preferences and resets the Languages settings, so every
// page is in English.
//
// The demo's data is shared, and this spec leaves it as it found it: no
// form or dialog is submitted, and every request from the page that is not
// a read is aborted and fails the capture (writes()).

const OUT = fileURLToPath(new URL('../../../docs/_static/screenshots/', import.meta.url))

test.skip(process.env.VECTOR_SCREENSHOTS !== '1', 'captures the docs screenshots; run with VECTOR_SCREENSHOTS=1')

test.use({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
    colorScheme: 'light',
    locale: 'en-US',
    timezoneId: 'UTC',
})

test.beforeAll(() => mkdirSync(OUT, { recursive: true }))

/** Aborts every request from the page that could change the API's data, and lists them. */
async function writes(page: Page): Promise<string[]> {
    const attempted: string[] = []
    await page.route(`${API}/**`, async (route) => {
        const request = route.request()
        if (['GET', 'HEAD', 'OPTIONS'].includes(request.method())) return route.continue()
        attempted.push(`${request.method()} ${request.url()}`)
        return route.abort()
    })
    return attempted
}

/** No request in flight, no progress bar or skeleton, the web fonts in: the page is done drawing. */
async function settle(page: Page) {
    await page.waitForLoadState('networkidle')
    await expect(page.locator('.v-progress-linear--indeterminate, .v-skeleton-loader, .v-progress-circular--indeterminate')).toHaveCount(0)
    await page.evaluate(() => document.fonts.ready)
}

/** The element's box once it stops moving: a dialog or menu grows into place as it opens. */
async function stableBox(element: Locator) {
    let last = await element.boundingBox()
    for (let i = 0; i < 50; i++) {
        await new Promise(resolve => setTimeout(resolve, 100))
        const box = await element.boundingBox()
        if (box && last && ['x', 'y', 'width', 'height'].every(k => box[k as keyof typeof box] === last![k as keyof typeof box])) return box
        last = box
    }
    throw new Error('the element kept moving')
}

/** The Nuxt dev server's own overlays (DevTools, the component inspector): not part of the admin. */
const HIDDEN = '#nuxt-devtools-container, nuxt-devtools-inspect-panel, #vue-tracer-overlay { display: none !important; }'

/**
 * Captures the page as it is, light then dark: the whole viewport, or one
 * element (with `margin` px of what surrounds it). Leaves it light.
 */
async function pair(page: Page, name: string, element?: Locator, margin = 0) {
    const application = page.locator('.v-application')
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')
    // no hover state on whatever was clicked last
    await page.mouse.move(0, 0)
    for (const scheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme: scheme })
        await expect(application).toHaveClass(new RegExp(`\\bv-theme--${scheme}\\b`))
        await settle(page)
        const path = `${OUT}${name}-${scheme}.png`
        if (!element) {
            await page.screenshot({ path, animations: 'disabled', caret: 'hide', style: HIDDEN })
            continue
        }
        const box = await stableBox(element)
        const viewport = page.viewportSize()!
        const x = Math.max(0, Math.floor(box.x - margin))
        const y = Math.max(0, Math.floor(box.y - margin))
        const clip = {
            x, y,
            width: Math.min(viewport.width, Math.ceil(box.x + box.width + margin)) - x,
            height: Math.min(viewport.height, Math.ceil(box.y + box.height + margin)) - y,
        }
        await page.screenshot({ path, clip, animations: 'disabled', caret: 'hide', style: HIDDEN })
    }
    await page.emulateMedia({ colorScheme: 'light' })
}

const main = (page: Page) => page.getByRole('main')
const rows = (page: Page) => page.locator('.v-data-table tbody tr').filter({ hasText: /SKU-\d{5}/ })
const form = (page: Page) => main(page).locator('form')

/** Opens the products list on its first page. */
async function products(page: Page) {
    await page.goto('r/products')
    await expect(rows(page)).toHaveCount(25)
}

/** A Vuetify select inside `scope`, by its label: opened, and `option` picked. */
async function choose(page: Page, scope: Locator, label: string, option: string) {
    await scope.locator('.v-select').filter({ has: page.getByRole('combobox', { name: label }) }).locator('.v-field').click()
    await page.getByRole('option', { name: option, exact: true }).click()
}

/** Adds one filter through the filter bar: a field, a condition and, unless it takes none, a value. */
async function addFilter(page: Page, field: string, condition: string, value: (card: Locator) => Promise<void>) {
    await settle(page)
    await main(page).getByRole('button', { name: 'Add filter' }).click()
    const card = page.locator('.v-overlay--active .v-card').filter({ has: page.getByRole('combobox', { name: 'Field' }) })
    await choose(page, card, 'Field', field)
    await choose(page, card, 'Condition', condition)
    await value(card)
    await card.getByRole('button', { name: 'Apply' }).click()
    await expect(card).toBeHidden()
}

/**
 * Filters the products to those with fewer than 20 in stock. One filter:
 * Add filter does not open a second time from the same button (its menu's
 * activator toggles it shut again), so a second one is not added here.
 */
async function lowStock(page: Page) {
    await addFilter(page, 'Stock', '<', card => card.getByLabel('Value').fill('20'))
    await expect(main(page).locator('.v-chip').filter({ hasText: 'Stock' })).toBeVisible()
    await settle(page)
}

test.describe('docs screenshots', () => {
    let attempted: string[] = []

    test.beforeEach(async ({ page }) => {
        // the browser's remembered language, read before the descriptor answers (the sign-in page): English
        await page.addInitScript(() => localStorage.setItem('vector.locale', 'en'))
        attempted = await writes(page)
    })

    test.afterEach(() => {
        expect(attempted, 'the capture sent a request that could change the demo data').toEqual([])
    })

    test('sign-in', async ({ page }) => {
        await page.goto('login')
        const card = main(page).locator('.v-card').first()
        await expect(card.getByRole('button', { name: 'Sign in' })).toBeVisible()
        await pair(page, 'sign-in', card, 48)
    })

    test('dashboard', async ({ admin: page }) => {
        await page.goto('')
        await expect(main(page).getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        await pair(page, 'dashboard')
    })

    test('command palette', async ({ admin: page }) => {
        await page.goto('')
        await expect(main(page).getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        await page.keyboard.press('Control+K')
        const dialog = page.getByRole('dialog')
        await dialog.getByRole('textbox', { name: 'Search' }).fill('lighting')
        await expect(dialog.getByText('Records', { exact: true })).toBeVisible()
        await pair(page, 'command-palette')
        await page.keyboard.press('Escape')
    })

    test('products list', async ({ admin: page }) => {
        await products(page)
        await pair(page, 'products-list')
    })

    test('products list, filtered', async ({ admin: page }) => {
        await products(page)
        await lowStock(page)
        await pair(page, 'products-filters')
    })

    test('products list, a selection and its bulk actions', async ({ admin: page }) => {
        await products(page)
        for (const row of [0, 1, 3]) await rows(page).nth(row).getByRole('checkbox').check()
        await expect(main(page).getByText('3 selected')).toBeVisible()
        await pair(page, 'products-bulk')
    })

    test('products list, saving a view', async ({ admin: page }) => {
        await products(page)
        await lowStock(page)
        await main(page).getByRole('button', { name: 'Views' }).click()
        await page.getByText('Save current as view…').click()
        const dialog = page.getByRole('dialog').filter({ hasText: 'Save view' })
        await dialog.getByLabel('Name').fill('Low stock')
        await pair(page, 'products-save-view')
        // cancelled: no view is created
        await dialog.getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog).toBeHidden()
    })

    test('product record', async ({ admin: page }) => {
        await page.goto('r/products/1')
        await expect(main(page).getByText('SKU-00001')).toBeVisible()
        await expect(page.locator('.vx-richtext-view')).toBeVisible()
        await pair(page, 'product-record')
    })

    test('product form', async ({ admin: page }) => {
        await page.goto('r/products/1/edit')
        await expect(form(page).getByLabel('Name')).not.toHaveValue('')
        await pair(page, 'product-form')
    })

    test('product form, invalid', async ({ admin: page }) => {
        await page.goto('r/products/create')
        await expect(form(page).getByLabel('Name')).toBeVisible()
        // submitted empty: the form refuses it and marks what it needs; nothing reaches the API (writes())
        await form(page).locator('button[type=submit]').first().click()
        await expect(form(page).locator('.v-input--error').first()).toBeVisible()
        await pair(page, 'product-form-errors')
    })

    test('action confirmation', async ({ admin: page }) => {
        await page.goto('r/products/1')
        await expect(main(page).getByText('SKU-00001')).toBeVisible()
        await main(page).getByRole('button', { name: 'Actions' }).click()
        await page.getByRole('menuitem', { name: 'Archive' }).or(page.getByRole('listitem').filter({ hasText: 'Archive' })).first().click()
        const dialog = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: 'Cancel' }) })
        await expect(dialog.getByRole('button', { name: 'Archive' })).toBeVisible()
        await pair(page, 'action-confirm', dialog.locator('.v-card').first(), 64)
        // cancelled: the action does not run
        await dialog.getByRole('button', { name: 'Cancel' }).click()
        await expect(dialog).toBeHidden()
    })

    test('notifications', async ({ admin: page }) => {
        await page.goto('')
        await expect(main(page).getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        // opening the inbox reads it; marking read is a click on an entry, or Mark all read, neither done here
        await page.getByRole('banner').getByRole('button', { name: 'Notifications' }).click()
        const inbox = page.locator('.v-overlay__content').filter({ hasText: 'Mark all read' })
        await expect(inbox.locator('.v-list-item').first()).toBeVisible()
        await pair(page, 'notifications')
        await page.keyboard.press('Escape')
    })

    test('jobs', async ({ admin: page }) => {
        await page.goto('jobs')
        await expect(main(page).getByRole('heading', { level: 1 })).toBeVisible()
        await pair(page, 'jobs')
    })

    test('settings', async ({ admin: page }) => {
        await page.goto('settings')
        await expect(main(page).getByRole('tab', { name: 'Branding' })).toBeVisible()
        await pair(page, 'settings')
    })

    test('queue dashboard', async ({ admin: page }) => {
        await page.goto('queues')
        await expect(main(page).getByRole('heading', { level: 1 })).toBeVisible()
        await pair(page, 'queues')
    })

    test('tickets list', async ({ admin: page }) => {
        await page.goto('r/tickets')
        await expect(page.locator('.v-data-table tbody tr').first()).toBeVisible()
        await pair(page, 'tickets-list')
    })

    test('stock overview', async ({ admin: page }) => {
        await page.goto('p/stock-overview')
        await expect(main(page).getByText('Stock overview').first()).toBeVisible()
        await pair(page, 'stock-overview')
    })

    test('language menu', async ({ admin: page }) => {
        await page.goto('')
        await expect(main(page).getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        // opened, not used: choosing a language would save it as a preference
        await page.getByRole('banner').locator('button:has(.mdi-translate)').click()
        await expect(page.getByRole('listitem').filter({ hasText: 'Deutsch' })).toBeVisible()
        await pair(page, 'language-menu')
        await page.keyboard.press('Escape')
    })
})
