// Where a server-supplied path may send the browser. Nested under utils/
// so Nuxt does not auto-import it: it is not part of the layer's public API.

/**
 * A path of this admin, and nothing a browser would read as another
 * origin: one leading `/` not followed by `/` or `\` (`//host`, `/\host`),
 * no scheme, and no backslash, whitespace or control character anywhere —
 * a browser strips tab, CR and LF from a URL, so `/\t/host` is `//host`.
 */
export function isAdminPath(path: string): boolean {
    return /^\/(?![/\\])/.test(path) && !/[\\\s\p{Cc}]/u.test(path)
}
