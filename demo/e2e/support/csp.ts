import type { Page } from '@playwright/test'

/**
 * Collects every Content-Security-Policy violation the page reports, from
 * before its first script and across full navigations. The hosted admin
 * runs under the shell's strict policy; the standalone dev server sends
 * none, so there it collects nothing. Call before the test's first
 * navigation; the returned function answers what was reported so far.
 */
export async function watchCsp(page: Page): Promise<() => Promise<string[]>> {
    const seen: string[] = []
    await page.exposeFunction('__vectorCspViolation', (violation: string) => {
        seen.push(violation)
    })
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            void (window as unknown as { __vectorCspViolation: (violation: string) => Promise<void> }).__vectorCspViolation(`${event.violatedDirective} ${event.blockedURI}`)
        })
    })

    return async () => {
        // a round trip, so every report sent before it has arrived
        await page.evaluate(() => 0)

        return [...seen]
    }
}
