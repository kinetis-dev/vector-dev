import { expect, test } from '../support/fixtures'

test.describe('the shell', () => {
    test('opens on the dashboard, with every resource group', async ({ admin: page }) => {
        await page.goto('')

        // the brand is the application's branding setting, so only the page part is fixed
        await expect(page).toHaveTitle(/^Dashboard · /)
        await expect(page.getByRole('banner').getByText('Vector Admin')).toBeVisible()
        for (const group of ['Access', 'Catalog', 'Sales', 'Support']) {
            await expect(page.getByRole('main').getByRole('heading', { name: group, level: 2 })).toBeVisible()
        }
    })

    test('goes to a resource from its dashboard card', async ({ admin: page }) => {
        await page.goto('')

        await page.getByRole('main').getByRole('link', { name: /^Products/ }).click()

        await expect(page).toHaveURL(/\/r\/products$/)
        await expect(page.getByRole('heading', { name: 'Products', level: 1 })).toBeVisible()
        await expect(page).toHaveTitle(/^Products · /)
    })

    test('goes to a resource from the navigation drawer', async ({ admin: page }) => {
        await page.goto('')

        await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Tags', exact: true }).click()

        await expect(page).toHaveURL(/\/r\/tags$/)
        await expect(page.getByRole('heading', { name: 'Tags', level: 1 })).toBeVisible()
    })

    test('says so for a resource that does not exist', async ({ admin: page }) => {
        await page.goto('r/nope')

        await expect(page.getByText('Unknown resource')).toBeVisible()
        await expect(page.getByText('Nothing is registered as “nope”.')).toBeVisible()
    })
})
