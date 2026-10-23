#!/usr/bin/env node
// Bumps one product's version, in the pull request that changes it:
//
//     node scripts/bump.mjs <vector|vector-ui> <patch|minor> [--date=YYYY-MM-DD]
//
// It moves the product's version in packages.manifest.json one step under
// scripts/version-policy.mjs; writes it to the product's package.json when
// it has one (packages/vector-ui; packages/vector's composer.json has no
// version, since Packagist reads it from the mirror's tag); and in the
// product's CHANGELOG.md turns `## [Unreleased]` into `## [X.Y.Z] - <date>`
// under a fresh, empty `## [Unreleased]`. The date is today's, in UTC.
// Link references at the end of a CHANGELOG are left as they are; neither
// product's CHANGELOG uses them.
//
// Bumping vector-ui also moves kinetis/vector's pin of it,
// packages/vector/resources/ui.json, to the new version, so the pin always
// names the vector-ui release beside it. A product whose `includes` names
// the bumped product's path needs its own bump too — kinetis/vector
// includes packages/vector-ui, whose release it pins. That is printed, not
// done: each bump is its own decision, patch or minor, with its own
// CHANGELOG entry.
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { nextVersion, transitionProblem } from './version-policy.mjs'
import { MANIFEST_FILE, PIN_FILE, parseManifest } from './validate-versions.mjs'

const root = fileURLToPath(new URL('..', import.meta.url))

const UNRELEASED = /^## \[Unreleased\][ \t]*$/m

/** The CHANGELOG with its Unreleased section released as `version` on `date`. */
export function releaseChangelog(markdown, version, date, file = 'CHANGELOG.md') {
    const match = UNRELEASED.exec(markdown)

    if (match === null) {
        throw new Error(`${file} has no "## [Unreleased]" section to release.`)
    }

    const after = markdown.slice(match.index + match[0].length)
    const next = after.search(/^## /m)
    const body = (next === -1 ? after : after.slice(0, next))
        .split(/\r?\n/)
        .filter(line => !/^\[[^\]]+\]:\s/.test(line))
        .join('\n')
        .trim()

    if (body === '') {
        throw new Error(`${file}'s "## [Unreleased]" section is empty; write what ${version} changes there first.`)
    }

    return `${markdown.slice(0, match.index)}## [Unreleased]\n\n## [${version}] - ${date}${after}`
}

/** The package.json text with its top-level version replaced, formatting kept. */
export function setPackageVersion(json, version, file = 'package.json') {
    const pattern = /^(\s*"version"\s*:\s*)"[^"]*"/m

    if (!pattern.test(json)) {
        throw new Error(`${file} has no "version" to bump.`)
    }

    const updated = json.replace(pattern, `$1"${version}"`)

    if (JSON.parse(updated).version !== version) {
        throw new Error(`${file}'s first "version" key is not its top-level version; bump it by hand.`)
    }

    return updated
}

/** The pin text with its version replaced, formatting kept. */
export function setPinVersion(json, version, file = PIN_FILE) {
    const pattern = /("version"\s*:\s*)"[^"]*"/

    if (!pattern.test(json)) {
        throw new Error(`${file} has no "version" to move.`)
    }

    const updated = json.replace(pattern, `$1"${version}"`)

    if (JSON.parse(updated).version !== version) {
        throw new Error(`${file}'s "version" could not be moved; fix it by hand.`)
    }

    return updated
}

function today() {
    return new Date().toISOString().slice(0, 10)
}

/**
 * Bumps `key` by `component` in the checkout at `repo`. Every file is
 * computed before any is written, so a refusal leaves the tree as it was.
 * Returns { from, to, files, alsoBump }: `alsoBump` lists the products
 * whose `includes` covers this one's path, which need a bump of their own.
 */
export function bump(repo, key, component, { date = today() } = {}) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        throw new Error(`--date=${date} is not YYYY-MM-DD.`)
    }

    const manifestPath = path.join(repo, MANIFEST_FILE)
    const manifestText = readFileSync(manifestPath, 'utf8')
    const manifest = parseManifest(manifestText)
    const entry = manifest.packages[key]

    if (entry === undefined) {
        throw new Error(`${MANIFEST_FILE} has no product "${key}"; expected one of ${Object.keys(manifest.packages).join(', ')}.`)
    }

    const from = entry.version
    const to = nextVersion(from, component)
    const problem = transitionProblem(from, to)

    if (problem !== null) {
        throw new Error(`${key}: ${problem}`)
    }

    const writes = []
    entry.version = to
    // The manifest is rewritten whole, in its own four-space style.
    writes.push([manifestPath, `${JSON.stringify(manifest, null, 4)}\n`])

    const npm = path.join(repo, entry.path, 'package.json')

    if (existsSync(npm)) {
        writes.push([npm, setPackageVersion(readFileSync(npm, 'utf8'), to, `${entry.path}/package.json`)])
    }

    const changelog = path.join(repo, entry.path, 'CHANGELOG.md')
    writes.push([changelog, releaseChangelog(readFileSync(changelog, 'utf8'), to, date, `${entry.path}/CHANGELOG.md`)])

    const vector = manifest.packages.vector

    if (key === 'vector-ui' && vector !== undefined) {
        const pin = path.join(repo, vector.path, PIN_FILE)
        writes.push([pin, setPinVersion(readFileSync(pin, 'utf8'), to, `${vector.path}/${PIN_FILE}`)])
    }

    for (const [file, contents] of writes) {
        writeFileSync(file, contents)
    }

    const alsoBump = Object.entries(manifest.packages)
        .filter(([other, { includes = [] }]) => other !== key && includes.some(dir => dir === entry.path || entry.path.startsWith(`${dir}/`)))
        .map(([other]) => other)

    return { from, to, files: writes.map(([file]) => path.relative(repo, file)), alsoBump }
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const positional = process.argv.slice(2).filter(arg => !arg.startsWith('--'))
        const flags = process.argv.slice(2).filter(arg => arg.startsWith('--'))
        const unknown = flags.find(flag => !flag.startsWith('--date='))

        if (positional.length !== 2 || unknown !== undefined) {
            throw new Error('Usage: node scripts/bump.mjs <vector|vector-ui> <patch|minor> [--date=YYYY-MM-DD]')
        }

        const date = flags.find(flag => flag.startsWith('--date='))?.slice('--date='.length)
        const { from, to, files, alsoBump } = bump(root, positional[0], positional[1], date === undefined ? {} : { date })
        console.log(`${positional[0]}: ${from} -> ${to}`)
        files.forEach(file => console.log(`  updated ${file}`))
        alsoBump.forEach(other => console.log(`${other} includes this product's path, so it needs a bump too: node scripts/bump.mjs ${other} <patch|minor>`))
    } catch (error) {
        console.error(error.message)
        process.exitCode = 1
    }
}
