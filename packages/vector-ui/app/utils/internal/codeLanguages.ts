// The languages a Code field highlights, each loaded the first time an
// editor needs it, so the base bundle carries none of them. Nested under
// utils/ so Nuxt does not auto-import it: it is not part of the layer's
// public API.
import type { Extension } from '@codemirror/state'
import type { VectorCodeLanguage } from '#vector/app/types/vector'

const loaders: Record<Exclude<VectorCodeLanguage, 'plain'>, () => Promise<Extension>> = {
    json: () => import('@codemirror/lang-json').then(m => m.json()),
    html: () => import('@codemirror/lang-html').then(m => m.html()),
    css: () => import('@codemirror/lang-css').then(m => m.css()),
    javascript: () => import('@codemirror/lang-javascript').then(m => m.javascript()),
    typescript: () => import('@codemirror/lang-javascript').then(m => m.javascript({ typescript: true })),
    sql: () => import('@codemirror/lang-sql').then(m => m.sql()),
    php: () => import('@codemirror/lang-php').then(m => m.php()),
    yaml: () => import('@codemirror/lang-yaml').then(m => m.yaml()),
    markdown: () => import('@codemirror/lang-markdown').then(m => m.markdown()),
    xml: () => import('@codemirror/lang-xml').then(m => m.xml()),
}

/** The highlighting of a language, or none for `plain` and a name the layer does not know. */
export async function loadLanguage(language: string): Promise<Extension> {
    const load = (loaders as Record<string, (() => Promise<Extension>) | undefined>)[language]

    return load ? await load() : []
}
