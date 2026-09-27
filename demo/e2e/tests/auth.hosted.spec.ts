import { ADMIN, API, EDITOR, expect, test } from '../support/fixtures'
import type { Page } from '@playwright/test'

// Hosted: the PHP application serves the admin at /vector/ui and owns
// sign-in (its /login page and session cookie).

async function fillSignIn(page: Page, email: string, password: string) {
    await page.getByLabel('E-mail').fill(email)
    await page.getByLabel('Password').fill(password)
    await page.getByRole('button', { name: 'Sign in' }).click()
}

test.describe('signing in (hosted)', () => {
    test('the admin sends an anonymous visitor to the application\'s sign-in page', async ({ page }) => {
        await page.goto('')

        await expect(page).toHaveURL(`${API}/login`)
        await expect(page.getByRole('heading', { name: 'Vector Demo' })).toBeVisible()
        await expect(page.getByText('Sign in to the admin (hosted mode)')).toBeVisible()
    })

    test('says so when the credentials are wrong', async ({ page }) => {
        await page.goto(`${API}/login`)

        await fillSignIn(page, ADMIN.email, 'not-the-password')

        await expect(page.getByText('Invalid credentials.')).toBeVisible()
        await expect(page).toHaveURL(`${API}/login`)
    })

    test('signs in to the shell and out again', async ({ page }) => {
        await page.goto(`${API}/login`)
        await fillSignIn(page, ADMIN.email, ADMIN.password)

        await expect(page).toHaveURL(/\/vector\/ui\/?$/)
        await expect(page).toHaveTitle(/^Dashboard · /)
        const account = page.getByRole('banner').getByRole('button', { name: ADMIN.name })
        await expect(account).toBeVisible()

        await account.click()
        await page.getByText('Sign out').click()

        await expect(page).toHaveURL(`${API}/login`)
        await page.goto('')
        await expect(page).toHaveURL(`${API}/login`)
    })

    test('an account without admin access is not shown the admin', async ({ page }) => {
        await page.goto(`${API}/login`)

        await fillSignIn(page, EDITOR.email, EDITOR.password)

        await expect(page.getByRole('navigation', { name: 'Main' })).toHaveCount(0)
    })
})
