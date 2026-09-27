import { defineConfig, devices } from '@playwright/test'
import type { Options } from './support/fixtures'

/**
 * Both deployments of the same admin, against the running demo stack:
 *
 * - standalone: the Nuxt dev server on :3000, a bearer token from /auth/login;
 * - hosted: the published bundle the PHP application serves at /vector/ui,
 *   behind its own session login.
 *
 * A spec named *.standalone.spec.ts or *.hosted.spec.ts runs in that
 * project only; every other spec runs in both.
 *
 * The suite writes to the demo databases, so it runs one test at a time
 * (never two runs at once either) and cleans up the records it creates.
 */
export default defineConfig<Options>({
    testDir: './tests',
    globalSetup: './support/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // the dev server compiles a page the first time it is visited
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        ...devices['Desktop Chrome'],
        navigationTimeout: 60_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'standalone',
            testIgnore: /\.hosted\.spec\.ts$/,
            use: { baseURL: 'http://localhost:3000/', mode: 'standalone' },
        },
        {
            name: 'hosted',
            testIgnore: /\.standalone\.spec\.ts$/,
            use: { baseURL: 'http://localhost:8080/vector/ui/', mode: 'hosted' },
        },
    ],
})
