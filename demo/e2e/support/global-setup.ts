import net from 'node:net'
import { chromium } from '@playwright/test'
import { API, UI, signIn } from './fixtures'

/**
 * The admin runs in the browser, which calls the API at the origin the
 * stack publishes: localhost:8080, and the standalone UI at localhost:3000.
 * In the e2e container those are other services, so E2E_FORWARD
 * ("node:3000,nginx:8080") forwards each local port to its service; the
 * browser, CORS and cookies then see the same origins as on the host.
 * Without it (running on the host) nothing is forwarded.
 */
function forward(spec: string | undefined): net.Server[] {
    return (spec ?? '').split(',').filter(Boolean).map((target) => {
        const [host, port] = target.split(':') as [string, string]
        const server = net.createServer((client) => {
            const upstream = net.connect(Number(port), host)
            client.pipe(upstream).pipe(client)
            client.on('error', () => upstream.destroy())
            upstream.on('error', () => client.destroy())
        })
        server.listen(Number(port))
        return server
    })
}

async function waitFor(url: string, ready: (status: number) => boolean, what: string) {
    const deadline = Date.now() + 180_000
    let last = 'no answer'

    while (Date.now() < deadline) {
        try {
            const response = await fetch(url, { redirect: 'manual' })
            if (ready(response.status)) return
            last = `HTTP ${response.status}`
        } catch (error) {
            last = String(error)
        }
        await new Promise(resolve => setTimeout(resolve, 2000))
    }

    throw new Error(`${what} at ${url} is not up (${last}). Is the stack running? See demo/e2e/README.md.`)
}

/**
 * The dev server compiles each module the first time a browser asks for
 * it, which can take longer than a test's assertions wait. Visiting the
 * sign-in page, the shell and a list once here pays that cost up front.
 */
async function warmUp() {
    const browser = await chromium.launch()
    const page = await browser.newPage({ baseURL: UI })
    try {
        await page.goto('login')
        await page.getByRole('button', { name: 'Sign in' }).waitFor({ timeout: 180_000 })
        await signIn(page, 'standalone')
        await page.goto('r/products')
        await page.getByRole('heading', { name: 'Products', level: 1 }).waitFor({ timeout: 180_000 })
        await page.getByText('SKU-', { exact: false }).first().waitFor({ timeout: 180_000 })
        // a record page and an edit form: the cells, inputs and the rich-text editor compile on first use
        await page.goto('r/products/1')
        await page.locator('.vx-richtext-view').waitFor({ timeout: 180_000 })
        await page.goto('r/products/1/edit')
        await page.getByRole('button', { name: 'Save' }).waitFor({ timeout: 180_000 })
    } finally {
        await browser.close()
    }
}

export default async function globalSetup() {
    const servers = forward(process.env.E2E_FORWARD)

    await waitFor(`${API}/openapi`, status => status === 200, 'The API')
    // unauthenticated, the hosted shell sends the visitor to the application's sign-in page
    await waitFor(`${API}/vector/ui`, status => status >= 300 && status < 400, 'The hosted admin')
    // the dev server answers 200 once its first build is done
    await waitFor(UI, status => status === 200, 'The standalone admin (Nuxt dev server)')
    await warmUp()

    return async () => {
        await Promise.all(servers.map(server => new Promise(resolve => server.close(resolve))))
    }
}
