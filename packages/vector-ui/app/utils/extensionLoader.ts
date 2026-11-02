import { watch, type Ref } from 'vue'

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
 *   standalone mode only, once the descriptor is loaded after sign-in.
 *   Each is a same-origin path of the API, so it is joined to `apiBase`
 *   as the API client joins its requests. The hosted shell already
 *   listed exactly these, from the same server-side list, so hosted mode
 *   skips the descriptor's copy instead of importing a module twice.
 *
 * In standalone mode `ready` also waits for the descriptor's modules:
 * a custom page a package declares, opened straight from a link, stays a
 * loading bar until they settle rather than reading "unknown page".
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
    ready: Ref<boolean>
    importModule: (url: string) => Promise<unknown>
    /** Runs a loaded module, its default export called with `window.Vector`. */
    run: (module: unknown, url: string) => void
    base?: string
}

/** A descriptor path on the API origin, joined to apiBase the way the API client joins a request path. */
export function apiModuleUrl(path: string, apiBase: string, base: string): string {
    return new URL(`${apiBase.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`, base).href
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
            let done = false
            const stop = watch(options.descriptorModules, (paths) => {
                if (paths === null || done) return
                done = true
                queueMicrotask(() => stop())
                const fresh = paths
                    .map(path => apiModuleUrl(path, options.apiBase, base))
                    .filter((url) => {
                        if (seen.has(url)) return false
                        seen.add(url)
                        return true
                    })
                void load(fresh).then(resolve)
            }, { immediate: true })
        })

    return Promise.all([initial, packages]).then(() => { options.ready.value = true })
}
