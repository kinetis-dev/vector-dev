#!/usr/bin/env node
// Structural checks over the monorepo, run by
// .github/workflows/monorepo-validate.yml:
//
// - One shared version. kinetis/vector and @kinetis/vector-ui release
//   together under one v* tag. The npm side records the version in
//   packages/vector-ui/package.json. The Composer side has no version
//   field: Packagist reads it from the split mirror's tag. What the
//   repository does record for it is its CHANGELOG, so the newest
//   released heading of each package's CHANGELOG.md must equal the
//   package.json version. Before the first release neither CHANGELOG
//   has a released heading, and there is nothing to compare yet.
// - Nothing under packages/ references the demo's App\ namespace.
//
//     node scripts/validate-monorepo.mjs
import { readFile, readdir } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))

// semver.org's own pattern, as in build-hosted-bundle.mjs
const SEMVER = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/

// Built or installed output, never source.
const SKIP = new Set(['node_modules', '.nuxt', '.output', 'dist', 'vendor'])

/** The newest released version a Keep a Changelog file records, or null. */
export function releasedVersion(changelog) {
    for (const [, heading] of changelog.matchAll(/^## \[([^\]]+)\]/gm)) {
        if (heading.toLowerCase() !== 'unreleased') {
            return heading
        }
    }

    return null
}

/**
 * The problems with the shared version, given the package.json version
 * and the two CHANGELOGs, each keyed by its path for the messages.
 */
export function versionProblems(packageVersion, changelogs) {
    const problems = []

    if (typeof packageVersion !== 'string' || !SEMVER.test(packageVersion)) {
        problems.push(`packages/vector-ui/package.json's version ${JSON.stringify(packageVersion)} is not SemVer.`)

        return problems
    }

    for (const [file, changelog] of Object.entries(changelogs)) {
        const released = releasedVersion(changelog)

        if (released !== null && released !== packageVersion) {
            problems.push(`${file}'s newest release is ${released}, but packages/vector-ui/package.json's version is ${packageVersion}; both packages release one shared version.`)
        }
    }

    return problems
}

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
                // App\ as a namespace root: not the tail of Foo\App\ or MyApp\
                if (/(^|[^\w\\])App\\/.test(line)) {
                    found.push(`${path.relative(base, file).split(path.sep).join('/')}:${index + 1}`)
                }
            })
        }
    }

    return found
}

async function main() {
    const { version } = JSON.parse(await readFile(path.join(root, 'packages/vector-ui/package.json'), 'utf8'))
    const changelogs = {}

    for (const file of ['packages/vector/CHANGELOG.md', 'packages/vector-ui/CHANGELOG.md']) {
        changelogs[file] = await readFile(path.join(root, file), 'utf8')
    }

    const problems = versionProblems(version, changelogs)

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

    const released = Object.values(changelogs).map(releasedVersion)

    console.log(released.every(entry => entry === null)
        ? `Shared version ${version}: no CHANGELOG records a release yet. No App\\ reference under packages/.`
        : `Shared version ${version} matches both CHANGELOGs. No App\\ reference under packages/.`)
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(error.message)
        process.exitCode = 1
    })
}
