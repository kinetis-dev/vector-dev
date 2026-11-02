import { computed, watch, type Ref } from 'vue'

/**
 * Imports the runtime extension modules and turns `ready` true once every
 * one this page will load has settled, loaded or failed. Separate from the
 * plugin so the order and the waiting can be tested without a browser
 * importing real URLs.
 *
 * Two lists:
 * - `initial`, known at boot: the hosted shell's (the packages' modules,
 *   then `VECTOR_UI_EXTENSIONS`) and runtime config's. Imported as given,
 *   at once.
 * - the packages' modules the descriptor lists (`ui.extensions`), read in
 *   standalone mode only, once the descriptor is loaded after sign-in
 *   (on a deep link, the `vector` layout's `load()`; after the login
 *   form, its own). Each is a path on the API's origin, resolved against
 *   `apiBase`; an entry that resolves anywhere but the API origin's
 *   `/vector/extensions/` is logged and skipped, never imported. The
 *   hosted shell already listed exactly these, from the same server-side
 *   list, so hosted mode skips the descriptor's copy instead of importing
 *   a module twice.
 *
 * In standalone mode `ready` also waits for the descriptor's modules:
 * a custom page a package declares, opened straight from a link, stays a
 * loading bar until they settle rather than reading "unknown page". A
 * descriptor that fails to load settles that wait too, as an empty list,
 * so the admin is never held back by it; one that loads later is still
 * read, once. Signed out, `ready` stays false: only a custom page reads
 * it, and every one is behind sign-in, so the login page never waits on
 * it, and after signing in the page the login form returns to still
 * waits for the modules.
 * Nothing is imported twice in a page's lifetime: the descriptor's list
 * is read once, so signing out and in again, or as someone else, loads
 * nothing more, and a URL already imported (the same module also named
 * in runtime config) is skipped.
 */
export interface ExtensionLoaderOptions {
    hosted: boolean
    initial: string[]
    apiBase: string
    /** The descriptor's `ui.extensions` once it is loaded, null before. */
    descriptorModules: Ref<string[] | null>
    /** Whether the latest descriptor load failed: settles the wait as if it listed nothing. */
    descriptorFailed: Ref<boolean>
    ready: Ref<boolean>
    importModule: (url: string) => Promise<unknown>
    /** Runs a loaded module, its default export called with `window.Vector`. */
    run: (module: unknown, url: string) => void
    base?: string
}

/** Where the API answers: `apiBase` resolved against the page, which an empty `apiBase` names itself. */
function apiRoot(apiBase: string, base: string): URL {
    return new URL(apiBase === '' ? new URL(base).origin : apiBase, base)
}

/**
 * A descriptor entry as the URL to import, or null for one that resolves
 * off the API's origin or outside `/vector/extensions/`: `\\host/x.js`,
 * `//host/x.js` or an absolute URL elsewhere is never imported, whatever
 * the descriptor says.
 */
export function apiModuleUrl(path: string, apiBase: string, base: string): string | null {
    let api: URL
    let url: URL
    try {
        api = apiRoot(apiBase, base)
        url = new URL(path, api)
    } catch {
        return null
    }

    return url.origin === api.origin && url.pathname.startsWith('/vector/extensions/') ? url.href : null
}

/** The descriptor as the loader reads it: the plugin's wiring, and the tests'. */
export function descriptorSignals(): Pick<ExtensionLoaderOptions, 'descriptorModules' | 'descriptorFailed'> {
    const { schema, failed } = useVectorSchema()
    return {
        descriptorModules: computed(() => (schema.value ? schema.value.ui?.extensions ?? [] : null)),
        descriptorFailed: failed,
    }
}

export function loadVectorExtensions(options: ExtensionLoaderOptions): Promise<void> {
    const base = options.base ?? window.location.href
    // by absolute URL: what a browser would import once and run twice
    const seen = new Set<string>()

    const load = (urls: string[]) => Promise.allSettled(urls.map(url => options.importModule(url)
        .then(module => options.run(module, url))
        .catch(error => console.error(`Vector extension ${url} failed to load`, error))))
        .then(() => undefined)

    // as given, repeats included, as before the descriptor listed any
    for (const url of options.initial) {
        try {
            seen.add(new URL(url, base).href)
        } catch {
            // not a URL at all: its import fails and is logged below, and nothing can repeat it
        }
    }
    const initial = load(options.initial)

    const packages = options.hosted
        ? Promise.resolve()
        : new Promise<void>((resolve) => {
            // a failed descriptor settles the wait; the import still happens, once, if one loads later
            const stopFailed = watch(options.descriptorFailed, (isFailed) => {
                if (isFailed) resolve()
            }, { immediate: true })

            let done = false
            const stop = watch(options.descriptorModules, (paths) => {
                if (paths === null || done) return
                done = true
                queueMicrotask(() => {
                    stop()
                    stopFailed()
                })
                const fresh: string[] = []
                for (const path of paths) {
                    const url = typeof path === 'string' ? apiModuleUrl(path, options.apiBase, base) : null
                    if (url === null) {
                        console.error(`Vector extension ${String(path)} is not a module of the API's /vector/extensions/; not loaded`)
                    } else if (!seen.has(url)) {
                        seen.add(url)
                        fresh.push(url)
                    }
                }
                void load(fresh).then(() => resolve())
            }, { immediate: true })
        })

    return Promise.all([initial, packages]).then(() => { options.ready.value = true })
}
