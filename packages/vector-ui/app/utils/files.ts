/**
 * A file's bytes as a `data:` URL, for an <img>. The hosted shell's strict
 * Content Security Policy admits `data:` images (`img-src 'self' data: https:`)
 * but not `blob:` ones, so every image the admin fetches with its credentials
 * is shown this way; a download may still use a blob link.
 */
export function dataUrl(blob: Blob): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => {
            if (typeof reader.result === 'string') resolve(reader.result)
            else reject(new Error('The file was not read as a data URL'))
        }
        reader.onerror = () => reject(reader.error ?? new Error('The file could not be read'))
        reader.readAsDataURL(blob)
    })
}
