import type { Ref } from 'vue'

/**
 * Imports the runtime extension modules and turns `ready` true once every
 * one has settled, loaded or failed. Separate from the plugin so the
 * order and the waiting can be tested without a browser importing real
 * URLs.
 *
 * The list is known at boot: the hosted shell's (`VECTOR_UI_EXTENSIONS`)
 * and runtime config's, imported as given, at once. A custom page an
 * extension defines, opened straight from a link, stays a loading bar
 * until they settle rather than reading "unknown page".
 * The modules are fetched at once but run in the order listed, a later
 * one waiting for those before it to load or fail, so the custom pages
 * they define reach the menu in the same order on every load. A module
 * that fails is logged and never holds the admin back.
 */
export interface ExtensionLoaderOptions {
    initial: string[]
    ready: Ref<boolean>
    importModule: (url: string) => Promise<unknown>
    /** Runs a loaded module, its default export called with `window.Vector`. */
    run: (module: unknown, url: string) => void
}

export function loadVectorExtensions(options: ExtensionLoaderOptions): Promise<void> {
    // fetched in parallel, run one after another in the order listed, so what they register (the
    // menu's custom pages and groups) comes in that order rather than whichever arrived first
    const imports = options.initial.map(url => options.importModule(url))

    return options.initial
        .reduce<Promise<void>>((previous, url, index) => previous.then(() => imports[index]!
            .then(module => options.run(module, url))
            .catch(error => console.error(`Vector extension ${url} failed to load`, error))), Promise.resolve())
        .then(() => { options.ready.value = true })
}
