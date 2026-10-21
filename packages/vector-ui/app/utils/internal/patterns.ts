// Linear-time scans the form rules use in place of backtracking regular
// expressions. Nested under utils/ so Nuxt does not auto-import them:
// they are not part of the layer's public API.

/**
 * An e-mail address as the Email rule takes it: no whitespace, one `@`
 * with text before it, and a domain with a dot that has text on both
 * sides. Scanned, not matched, so a long input costs linear time.
 */
export function isEmailAddress(value: string): boolean {
    if (/\s/.test(value)) return false

    const at = value.indexOf('@')

    if (at < 1 || value.includes('@', at + 1)) return false

    const domain = value.slice(at + 1)

    return domain.lastIndexOf('.', domain.length - 2) >= 1
}

/**
 * The sign-in form's looser check: somewhere on one line, text, an `@`,
 * text, a dot and text again.
 */
export function hasEmailShape(value: string): boolean {
    return value.split(/[\n\r\u2028\u2029]/).some((line) => {
        const at = line.indexOf('@', 1)

        return at !== -1 && line.lastIndexOf('.', line.length - 2) >= at + 2
    })
}

/**
 * A PCRE pattern with its delimiters and flags, as the framework's Regex
 * constraint takes it, as a JavaScript RegExp: the first character is the
 * delimiter, its last occurrence closes the pattern, and only letters may
 * follow it. Anything else is taken as a bare pattern.
 */
export function phpRegex(pattern: string): RegExp {
    const delimiter = pattern.charAt(0)
    const end = pattern.lastIndexOf(delimiter)
    const modifiers = pattern.slice(end + 1)

    if (end < 1 || '\n\r\u2028\u2029'.includes(delimiter) || !/^[a-zA-Z]*$/.test(modifiers)) return new RegExp(pattern)

    const flags = [...modifiers].filter(f => 'gimsuy'.includes(f)).join('')

    return new RegExp(pattern.slice(1, end), flags)
}
