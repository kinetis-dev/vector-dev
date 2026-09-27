import { test as base, expect, type APIRequestContext, type Page } from '@playwright/test'

export { expect }

/** The API origin, and the standalone UI's, as the browser sees them. */
export const API = 'http://localhost:8080'
export const UI = 'http://localhost:3000/'

/** The seeded accounts (demo:seed). */
export const ADMIN = { email: 'admin@vector.local', password: 'password', name: 'Vector Admin' }
export const EDITOR = { email: 'editor@vector.local', password: 'password', name: 'Demo Editor' }
export type Account = typeof ADMIN

export type Mode = 'standalone' | 'hosted'
export interface Options { mode: Mode }

/** A name no seeded record has and no other run produced: `E2E tag lx3k9a2`. */
export function unique(prefix: string): string {
    return `${prefix} ${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`
}

/** The admin API as one account, for arranging and cleaning up data around a test. */
export class Api {
    private constructor(private readonly request: APIRequestContext, private readonly token: string) {}

    static async as(request: APIRequestContext, account: Account = ADMIN): Promise<Api> {
        const response = await request.post(`${API}/auth/login`, { data: { email: account.email, password: account.password } })
        expect(response.ok(), `signing in as ${account.email}`).toBeTruthy()
        return new Api(request, (await response.json()).accessToken)
    }

    private get headers() {
        return { Authorization: `Bearer ${this.token}` }
    }

    async search(resource: string, q: string): Promise<Record<string, unknown>[]> {
        const response = await this.request.get(`${API}/vector/r/${resource}`, { headers: this.headers, params: { q, perPage: 100 } })
        expect(response.ok()).toBeTruthy()
        return (await response.json()).data
    }

    async create(resource: string, values: Record<string, unknown>): Promise<Record<string, unknown>> {
        const response = await this.request.post(`${API}/vector/r/${resource}`, { headers: this.headers, data: values })
        expect(response.ok(), await response.text()).toBeTruthy()
        return (await response.json()).data
    }

    /** Sets this account's UI preferences (`columns.<resource>`, …); a null value removes a key. */
    async preferences(values: Record<string, unknown>): Promise<void> {
        const response = await this.request.put(`${API}/vector/preferences`, { headers: this.headers, data: values })
        expect(response.ok(), await response.text()).toBeTruthy()
    }

    /** Removes every preference this account saved: the lists show their default columns again. */
    async resetPreferences(): Promise<void> {
        const response = await this.request.get(`${API}/vector/preferences`, { headers: this.headers })
        expect(response.ok()).toBeTruthy()
        const saved: Record<string, unknown> = (await response.json()).data
        if (Object.keys(saved).length > 0) await this.preferences(Object.fromEntries(Object.keys(saved).map(key => [key, null])))
    }

    async show(resource: string, id: unknown): Promise<Record<string, unknown>> {
        const response = await this.request.get(`${API}/vector/r/${resource}/${id}`, { headers: this.headers })
        expect(response.ok(), await response.text()).toBeTruthy()
        return (await response.json()).data
    }

    async update(resource: string, id: unknown, values: Record<string, unknown>): Promise<Record<string, unknown>> {
        const response = await this.request.patch(`${API}/vector/r/${resource}/${id}`, { headers: this.headers, data: values })
        expect(response.ok(), await response.text()).toBeTruthy()
        return (await response.json()).data
    }

    async remove(resource: string, id: unknown): Promise<void> {
        const response = await this.request.delete(`${API}/vector/r/${resource}/${id}`, { headers: this.headers })
        expect([204, 200, 404]).toContain(response.status())
    }
}

/**
 * Signs a page in the way its deployment does.
 *
 * Hosted: the application's own sign-in form, which sets the session
 * cookie. Standalone: a token from /auth/login, placed where the admin
 * keeps it (sessionStorage) before any page script runs — on every load
 * of this page, so a test of signing out signs in through the form
 * instead (auth.standalone.spec.ts).
 */
export async function signIn(page: Page, mode: Mode, account: Account = ADMIN): Promise<void> {
    if (mode === 'hosted') {
        await page.goto(`${API}/login`)
        await page.getByLabel('E-mail').fill(account.email)
        await page.getByLabel('Password').fill(account.password)
        await page.getByRole('button', { name: 'Sign in' }).click()
        await page.waitForURL(/\/vector\/ui\/?/)
        return
    }

    const response = await page.request.post(`${API}/auth/login`, { data: { email: account.email, password: account.password } })
    expect(response.ok(), `signing in as ${account.email}`).toBeTruthy()
    const body = await response.json()
    const session = JSON.stringify({ token: body.accessToken, expiresAt: Date.now() + body.expiresIn * 1000, user: body.user })
    await page.addInitScript(value => sessionStorage.setItem('vector.session', value), session)
}

/** Records a test created, removed after it whatever its outcome. */
export class Cleanup {
    private readonly pending: { resource: string, name: string }[] = []

    /** Removes every `resource` record whose search matches `name` once the test ends. */
    byName(resource: string, name: string) {
        this.pending.push({ resource, name })
    }

    async run(api: Api) {
        for (const { resource, name } of this.pending.splice(0)) {
            for (const record of await api.search(resource, name)) await api.remove(resource, record.id)
        }
    }
}

export const test = base.extend<{ admin: Page, api: Api, cleanup: Cleanup }, Options>({
    mode: ['standalone', { option: true, scope: 'worker' }],

    /**
     * A page signed in as the seeded admin, whose saved UI preferences are
     * cleared first: whatever ran before (another test, the PHPUnit suite,
     * a person trying the demo) the lists start from their defaults.
     */
    admin: async ({ page, mode, api }, use) => {
        await api.resetPreferences()
        await signIn(page, mode)
        await use(page)
    },

    api: async ({ playwright }, use) => {
        const request = await playwright.request.newContext()
        await use(await Api.as(request))
        await request.dispose()
    },

    cleanup: async ({ api }, use) => {
        const cleanup = new Cleanup()
        await use(cleanup)
        await cleanup.run(api)
    },
})
