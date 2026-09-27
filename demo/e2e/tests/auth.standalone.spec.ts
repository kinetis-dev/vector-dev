import { ADMIN, API, EDITOR, expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Standalone: the admin's own sign-in page, a bearer token held for the tab.

async function fillSignIn(page: Page, email: string, password: string) {
    await page.getByLabel('E-mail').fill(email)
    await page.getByLabel('Password').fill(password)
    await page.getByRole('button', { name: 'Sign in' }).click()
}

test.describe('signing in (standalone)', () => {
    test('sends an anonymous visitor to sign in, then on to the page they asked for', async ({ page }) => {
        await page.goto('r/products')

        await expect(page).toHaveURL(/\/login\?redirect=(%2F|\/)r(%2F|\/)products$/)
        await fillSignIn(page, ADMIN.email, ADMIN.password)

        await expect(page).toHaveURL(/\/r\/products$/)
        await expect(page.getByRole('heading', { name: 'Products', level: 1 })).toBeVisible()
    })

    test('checks the form before asking the server', async ({ page }) => {
        let asked = false
        page.on('request', (request) => { if (request.url().endsWith('/auth/login')) asked = true })
        await page.goto('login')

        await page.getByRole('button', { name: 'Sign in' }).click()
        await expect(page.getByText('Required')).toHaveCount(2)

        await page.getByLabel('E-mail').fill('admin')
        await page.getByLabel('Password').fill('x')
        await page.getByRole('button', { name: 'Sign in' }).click()
        await expect(page.getByText('Enter a valid e-mail address')).toBeVisible()

        expect(asked).toBe(false)
    })

    test('says so when the credentials are wrong, and stays on the page', async ({ page }) => {
        await page.goto('login')

        await fillSignIn(page, ADMIN.email, 'not-the-password')

        await expect(page.getByRole('alert').filter({ hasText: 'Invalid credentials.' })).toBeVisible()
        await expect(page).toHaveURL(/\/login$/)
    })

    test('never follows a redirect to another origin', async ({ page }) => {
        await page.goto('login?redirect=//evil.example/steal')

        await fillSignIn(page, ADMIN.email, ADMIN.password)

        await expect(page).toHaveURL(/^http:\/\/localhost:3000\/$/)
        await expect(page).toHaveTitle(/^Dashboard · /)
    })

    test('keeps the session over a reload, and ends it on sign out', async ({ page }) => {
        await page.goto('login')
        await fillSignIn(page, ADMIN.email, ADMIN.password)
        await expect(page).toHaveTitle(/^Dashboard · /)

        await page.reload()
        await expect(page).toHaveTitle(/^Dashboard · /)

        await page.getByRole('banner').getByRole('button', { name: ADMIN.name }).click()
        await page.getByRole('menuitem', { name: 'Sign out' }).or(page.getByText('Sign out')).click()
        await expect(page).toHaveURL(/\/login$/)

        await page.goto('r/tags')
        await expect(page).toHaveURL(/\/login\?redirect=/)
    })

    // The credentials are right, but the admin refuses the account (403):
    // the page says why, welcomes no one, and keeps no token.
    test('an account without admin access is told so, not shown the admin', async ({ page }) => {
        await page.goto('login')

        await fillSignIn(page, EDITOR.email, EDITOR.password)

        await expect(page.getByRole('alert').filter({ hasText: 'This account has no access to the admin.' })).toBeVisible()
        await expect(page).toHaveURL(/\/login$/)
        await expect(page.getByText(`Welcome back, ${EDITOR.name}.`)).toHaveCount(0)
        await expect(page.getByRole('navigation', { name: 'Main' })).toHaveCount(0)
        expect(await page.evaluate(() => sessionStorage.getItem('vector.session'))).toBeNull()

        // and the admin can still sign in from the same page
        await fillSignIn(page, ADMIN.email, ADMIN.password)
        await expect(page).toHaveTitle(/^Dashboard · /)
    })
})

test.describe('staying signed in (standalone)', () => {
    test('a session outlives its access token: the refresh token renews it', async ({ page }) => {
        await page.goto('login')
        await fillSignIn(page, ADMIN.email, ADMIN.password)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

        // the access token has run out (and was never valid again); the refresh token has not
        const before = await page.evaluate(() => {
            const saved = JSON.parse(sessionStorage.getItem('vector.session')!)
            sessionStorage.setItem('vector.session', JSON.stringify({ ...saved, token: 'expired', expiresAt: Date.now() - 1000 }))
            return saved.refreshToken as string
        })
        expect(before).toMatch(/^[0-9a-f]{64}$/)

        await page.goto('r/products')
        await expect(page.getByRole('heading', { name: 'Products', level: 1 })).toBeVisible()
        await expect(page).not.toHaveURL(/\/login/)
        const after = await page.evaluate(() => JSON.parse(sessionStorage.getItem('vector.session')!) as { token: string, refreshToken: string })
        expect(after.token).not.toBe('expired')
        expect(after.refreshToken).not.toBe(before) // rotated
    })

    test('signing out revokes the refresh token', async ({ page, request }) => {
        await page.goto('login')
        await fillSignIn(page, ADMIN.email, ADMIN.password)
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
        const refreshToken = await page.evaluate(() => JSON.parse(sessionStorage.getItem('vector.session')!).refreshToken as string)

        await page.getByRole('banner').getByRole('button', { name: ADMIN.name }).click()
        await page.getByText('Sign out').click()
        await expect(page).toHaveURL(/\/login/)

        const refused = await request.post(`${API}/auth/refresh`, { data: { refreshToken } })
        expect(refused.status()).toBe(401)
    })
})
