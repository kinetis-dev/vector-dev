/**
 * A file's bytes as a `data:` URL, for an <img>. The hosted shell's strict
 * Content Security Policy admits `data:` images (`img-src 'self' data: https:`)
 * but not `blob:` ones, so every image the admin fetches with its credentials
 * is shown this way; a download may still use a blob link.
 */
export function dataUrl(blob: Blob): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result))
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(blob)
    })
}
