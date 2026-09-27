import { ADMIN, expect, test } from '../support/fixtures'

test.describe('the shell', () => {
    test('opens on the dashboard, with every resource group in the drawer', async ({ admin: page }) => {
        await page.goto('')

        // the brand is the application's branding setting, so only the page part is fixed
        await expect(page).toHaveTitle(/^Dashboard · /)
        await expect(page.getByRole('banner').getByText(ADMIN.name)).toBeVisible()
        await expect(page.getByRole('main').getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible()
        const drawer = page.getByRole('navigation', { name: 'Main' })
        for (const group of ['Access', 'Catalog', 'Sales', 'Support']) {
            // .first(): an extension's page may add its own section of the same name (the demo's Stock overview is under Catalog)
            await expect(drawer.getByText(group, { exact: true }).first()).toBeVisible()
        }
        // the home page is the dashboard alone: the drawer lists the resources
        await expect(page.getByRole('main').getByRole('heading', { level: 2 })).toHaveCount(0)
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
