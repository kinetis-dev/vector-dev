#!/usr/bin/env node
// Structural checks over the monorepo, run by
// .github/workflows/monorepo-validate.yml: nothing under packages/
// references the demo's App\ namespace. Versions are checked by
// scripts/validate-versions.mjs, in .github/workflows/versions.yml.
//
//     node scripts/validate-monorepo.mjs
import { readFile, readdir } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))

// Built or installed output, never source.
const SKIP = new Set(['node_modules', '.nuxt', '.output', 'dist', 'vendor'])

/** Every `App\` reference in the PHP sources, stubs and composer.json under dir, as "path:line". */
export async function appReferences(dir, base = dir) {
    const found = []

    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name)

        if (entry.isDirectory()) {
            if (!SKIP.has(entry.name)) {
                found.push(...await appReferences(file, base))
            }
        } else if (/\.(php|stub)$/.test(entry.name) || entry.name === 'composer.json') {
            const lines = (await readFile(file, 'utf8')).split('\n')

            lines.forEach((line, index) => {
                // App\ as a namespace root, written plainly (use App\X), fully
                // qualified (\App\X) or with its backslash escaped in a string
                // ("\\App\\X"): up to two backslashes before it, and before
                // those neither a word character nor another backslash — so
                // not the tail of Kinetis\App\ or MyApp\.
                if (/(^|[^\w\\])\\{0,2}App\\/.test(line)) {
                    found.push(`${path.relative(base, file).split(path.sep).join('/')}:${index + 1}`)
                }
            })
        }
    }

    return found
}

async function main() {
    const problems = []

    for (const reference of await appReferences(path.join(root, 'packages'))) {
        problems.push(`packages/${reference} references App\\, the demo's namespace; nothing under packages/ may.`)
    }

    if (problems.length > 0) {
        for (const problem of problems) {
            console.error(process.env.GITHUB_ACTIONS === 'true' ? `::error::${problem}` : problem)
        }

        process.exitCode = 1

        return
    }

    console.log('No App\\ reference under packages/.')
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(error.message)
        process.exitCode = 1
    })
}
