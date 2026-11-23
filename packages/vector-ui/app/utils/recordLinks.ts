// The assistant names every record it used as `resource#id`. In its
// answer, already rendered and sanitized, each such reference to a
// resource the descriptor knows becomes a link to that record's page; a
// slug the admin does not know stays text. Nothing inside a link is
// touched, and the links are built here, never parsed from the answer.

const REFERENCE = /(?<![\w-])([a-z0-9][a-z0-9-]*)#([A-Za-z0-9_-]+)/g

/**
 * $html with every `slug#id` whose slug is in $slugs made a link to
 * `{base}/r/{slug}/{id}`, marked `data-record-link`.
 */
export function linkRecords(html: string, slugs: ReadonlySet<string>, base: string): string {
    const template = document.createElement('template')
    template.innerHTML = html
    const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT)
    const texts: Text[] = []
    while (walker.nextNode()) texts.push(walker.currentNode as Text)

    for (const text of texts) {
        if (text.parentElement?.closest('a')) continue
        const value = text.nodeValue ?? ''
        const fragment = document.createDocumentFragment()
        let last = 0

        for (const match of value.matchAll(REFERENCE)) {
            const [whole, slug, id] = match as unknown as [string, string, string]
            if (!slugs.has(slug)) continue
            const at = match.index ?? 0
            fragment.append(value.slice(last, at))
            const link = document.createElement('a')
            link.setAttribute('href', `${base}/r/${encodeURIComponent(slug)}/${encodeURIComponent(id)}`)
            link.dataset.recordLink = `${slug}#${id}`
            link.textContent = whole
            fragment.append(link)
            last = at + whole.length
        }

        if (last === 0) continue
        fragment.append(value.slice(last))
        text.replaceWith(fragment)
    }

    return template.innerHTML
}
