#!/usr/bin/env node
// The checks .github/workflows/versions.yml runs against
// packages.manifest.json, the one place each product's version is written.
// After tools/validate-manifest.php in kinetis, checks 5 and 6.
//
//     node scripts/validate-versions.mjs [--base=<ref>]
//
// consistency, on the tree as it stands:
//   - the manifest has the expected shape, and every version in it is one
//     scripts/version-policy.mjs accepts;
//   - a package.json under a product's path carries the manifest's name
//     and version; a composer.json carries the name and no version
//     (Packagist reads kinetis/vector's from the mirror's tag);
//   - each product's CHANGELOG.md has a `## [<version>]` section;
//   - each directory a product's optional `includes` names exists;
//   - kinetis/vector's resources/ui.json pins exactly the vector-ui
//     release in packages/vector-ui/package.json (see checkPin()).
//
// history, against a comparison base:
//   - a product whose files under its path changed has its version bumped,
//     and so does a product whose `includes` names a directory that changed
//     (kinetis/vector includes packages/vector-ui: it pins the vector-ui
//     release it expects, so every vector-ui release is a kinetis/vector
//     release too);
//   - a version that changed moved exactly one legal step;
//   - a product with no changed files keeps its version.
// A package.json whose only difference is in devDependencies entries
// version-policy.mjs lists as test-only tooling is changed but needs no
// bump: a product changed only that way may bump or not.
//
// The base is --base when given — the workflow passes the pull request's
// merge base, so the branch is judged as one change — else
// GITHUB_EVENT_BEFORE on a push, else HEAD's parent. Base and the working
// tree are compared end to end, so a commit in between cannot launder a
// skipped version. With no base at all (the repository's first commit, or
// the all-zero `before` of a branch's first push), or a base that carries
// no manifest yet (the change that introduces it), the history check is
// skipped and says so. A base that was named and cannot be read fails.
import { execFileSync, spawnSync } from 'node:child_process'
import { existsSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { isDeepStrictEqual } from 'node:util'
import { TEST_ONLY_DEV_DEPENDENCIES, transitionProblem, versionProblem } from './version-policy.mjs'

export const MANIFEST_FILE = 'packages.manifest.json'

/** kinetis/vector's pin of the @kinetis/vector-ui release it expects, under its own path. */
export const PIN_FILE = 'resources/ui.json'

const ENTRY_KEYS = ['name', 'path', 'version']
const OPTIONAL_KEYS = ['includes']

const root = fileURLToPath(new URL('..', import.meta.url))

/**
 * The manifest, parsed and shape-checked: `{ packages: { <key>: { name,
 * path, version[, includes] } } }` and nothing else, every path a
 * `packages/<dir>`. Everything below indexes into it, so a malformed one
 * throws here.
 *
 * `includes` names other directories whose changes count toward the
 * entry's version as well as its own path's: kinetis/vector includes
 * packages/vector-ui, because it pins the vector-ui release it expects
 * (resources/ui.json) and `vector:install-ui` installs that release. Each is a repo-relative directory, listed once, outside
 * the entry's own path; checkIncludes() checks that it exists.
 */
export function parseManifest(json, where = MANIFEST_FILE) {
    let manifest

    try {
        manifest = JSON.parse(json)
    } catch (error) {
        throw new Error(`${where} is not valid JSON: ${error.message}`, { cause: error })
    }

    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value)

    if (!isObject(manifest) || !isObject(manifest.packages) || Object.keys(manifest).some(key => key !== 'packages')) {
        throw new Error(`${where} must be { "packages": { ... } } and nothing else.`)
    }

    if (Object.keys(manifest.packages).length === 0) {
        throw new Error(`${where} lists no packages.`)
    }

    for (const [key, entry] of Object.entries(manifest.packages)) {
        const required = isObject(entry) ? Object.keys(entry).filter(field => !OPTIONAL_KEYS.includes(field)).sort().join() : null

        if (required !== ENTRY_KEYS.join() || ENTRY_KEYS.some(field => typeof entry[field] !== 'string' || entry[field] === '')) {
            throw new Error(`${where}: "${key}" must have exactly ${ENTRY_KEYS.map(field => `"${field}"`).join(', ')}, each a non-empty string, and optionally ${OPTIONAL_KEYS.map(field => `"${field}"`).join(', ')}.`)
        }

        if (!/^packages\/[a-z0-9][a-z0-9-]*$/.test(entry.path)) {
            throw new Error(`${where}: "${key}" has path "${entry.path}"; expected packages/<directory>.`)
        }

        const problem = Object.hasOwn(entry, 'includes') ? includesProblem(entry) : null

        if (problem !== null) {
            throw new Error(`${where}: "${key}" ${problem}.`)
        }
    }

    return manifest
}

/** Why an entry's `includes` is malformed, or null when it is not. */
function includesProblem(entry) {
    const includes = entry.includes

    if (!Array.isArray(includes) || includes.length === 0) {
        return 'has "includes" that is not a non-empty array of paths'
    }

    for (const dir of includes) {
        // Repo-relative, as git prints it: no leading or trailing slash, no
        // empty, "." or ".." segment, no backslash.
        if (typeof dir !== 'string' || dir === '' || dir.split('/').some(part => part === '' || part === '.' || part === '..') || dir.includes('\\')) {
            return `includes ${JSON.stringify(dir)}, which is not a repo-relative directory such as packages/vector-ui`
        }

        if (dir === entry.path || dir.startsWith(`${entry.path}/`) || entry.path.startsWith(`${dir}/`)) {
            return `includes "${dir}", which overlaps its own path "${entry.path}"`
        }
    }

    const duplicate = includes.find((dir, index) => includes.indexOf(dir) !== index)

    return duplicate === undefined ? null : `includes "${duplicate}" more than once`
}

/** Whether `file` lies under the directory `dir`. */
function under(file, dir) {
    return file.startsWith(`${dir}/`)
}

/**
 * The problems of each entry's `includes` against the tree: every path
 * must be a directory that exists — another product's path, or any other
 * directory. `isDirectory(dir)` answers for a repo-relative path.
 */
export function checkIncludes(manifest, isDirectory) {
    const problems = []

    for (const [key, entry] of Object.entries(manifest.packages)) {
        for (const dir of entry.includes ?? []) {
            if (!isDirectory(dir)) {
                problems.push(`${key}: includes "${dir}", which is not a directory in this tree`)
            }
        }
    }

    return problems
}

/** Whether a CHANGELOG has a `## [version]` heading (Keep a Changelog, with or without a date after it). */
export function changelogHeading(version) {
    return new RegExp(`^## \\[${version.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\](\\s.*)?$`)
}

/**
 * The consistency problems of a manifest against the files it describes.
 * `read(file)` returns a repo-relative file's contents, or null when it
 * does not exist — the working tree here, a commit in scripts/release.mjs.
 */
export function checkConsistency(manifest, read) {
    const problems = []

    for (const [key, entry] of Object.entries(manifest.packages)) {
        const problem = versionProblem(entry.version)

        if (problem !== null) {
            problems.push(`${key}: ${problem}`)
            continue
        }

        const npm = read(`${entry.path}/package.json`)

        if (npm !== null) {
            const pkg = JSON.parse(npm)

            if (pkg.name !== entry.name) {
                problems.push(`${key}: ${entry.path}/package.json is named "${pkg.name}", the manifest says "${entry.name}"`)
            }

            if (pkg.version !== entry.version) {
                problems.push(`${key}: ${entry.path}/package.json is at ${pkg.version}, the manifest at ${entry.version}; run scripts/bump.mjs rather than editing either by hand`)
            }
        }

        const composer = read(`${entry.path}/composer.json`)

        if (composer !== null) {
            const pkg = JSON.parse(composer)

            if (pkg.name !== entry.name) {
                problems.push(`${key}: ${entry.path}/composer.json is named "${pkg.name}", the manifest says "${entry.name}"`)
            }

            if (Object.hasOwn(pkg, 'version')) {
                problems.push(`${key}: ${entry.path}/composer.json has a "version"; Packagist reads it from the mirror's tag, so it must not`)
            }
        }

        const changelog = read(`${entry.path}/CHANGELOG.md`)
        const heading = changelogHeading(entry.version)

        if (changelog === null) {
            problems.push(`${key}: ${entry.path}/CHANGELOG.md is missing`)
        } else if (!changelog.split(/\r?\n/).some(line => heading.test(line))) {
            problems.push(`${key}: ${entry.path}/CHANGELOG.md has no "## [${entry.version}]" section`)
        }
    }

    return [...problems, ...checkPin(manifest, read)]
}

/**
 * The problems of kinetis/vector's UI pin, `<vector path>/resources/ui.json`:
 * it must be exactly `{"package": "@kinetis/vector-ui", "version": "<x.y.z>"}`,
 * the version the one in packages/vector-ui/package.json. The two products
 * version independently, but every vector-ui release moves the pin, and the
 * pin lives in kinetis/vector, whose `includes` then asks for its bump too:
 * each kinetis/vector release names the vector-ui release cut beside it.
 * scripts/bump.mjs moves the pin with vector-ui's version. Checked only when
 * the manifest lists both products.
 */
export function checkPin(manifest, read) {
    const vector = manifest.packages.vector
    const ui = manifest.packages['vector-ui']

    if (vector === undefined || ui === undefined) {
        return []
    }

    const file = `${vector.path}/${PIN_FILE}`
    const json = read(file)

    if (json === null) {
        return [`vector: ${file} is missing; it pins the ${ui.name} release kinetis/vector expects`]
    }

    let pin

    try {
        pin = JSON.parse(json)
    } catch {
        return [`vector: ${file} is not valid JSON`]
    }

    if (pin === null || typeof pin !== 'object' || Array.isArray(pin) || Object.keys(pin).sort().join() !== 'package,version') {
        return [`vector: ${file} must be exactly {"package": "${ui.name}", "version": "<x.y.z>"}`]
    }

    const problems = []

    if (pin.package !== ui.name) {
        problems.push(`vector: ${file} pins "${pin.package}", not ${ui.name}`)
    }

    const npm = read(`${ui.path}/package.json`)
    const uiVersion = npm === null ? ui.version : JSON.parse(npm).version
    const where = npm === null ? `the manifest's ${ui.name}` : `${ui.path}/package.json`

    if (pin.version !== uiVersion) {
        problems.push(`vector: ${file} pins ${ui.name} ${pin.version}, but ${where} is at ${uiVersion}; run scripts/bump.mjs vector-ui, which moves the pin with it`)
    }

    return problems
}

/**
 * Whether a package.json changed from `before` to `after` (its contents at
 * the base and in the tree, null where it does not exist) only in
 * devDependencies entries TEST_ONLY_DEV_DEPENDENCIES lists: added, removed
 * or moved to another range. Every other field must be identical once
 * parsed, key order included (the order of `exports` conditions matters),
 * so a whitespace-only change is exempt and anything else is not. A file
 * added, deleted or not valid JSON on either side is never exempt.
 */
export function onlyTestToolingChanged(before, after) {
    if (before === null || after === null) {
        return false
    }

    let old
    let now

    try {
        old = JSON.parse(before)
        now = JSON.parse(after)
    } catch {
        return false
    }

    const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value)

    if (!isObject(old) || !isObject(now)) {
        return false
    }

    const { devDependencies: oldDev = {}, ...oldRest } = old
    const { devDependencies: newDev = {}, ...newRest } = now

    if (!isObject(oldDev) || !isObject(newDev) || JSON.stringify(oldRest) !== JSON.stringify(newRest)) {
        return false
    }

    return [...new Set([...Object.keys(oldDev), ...Object.keys(newDev)])]
        .filter(name => !isDeepStrictEqual(oldDev[name], newDev[name]))
        .every(name => TEST_ONLY_DEV_DEPENDENCIES.includes(name))
}

/**
 * The history problems between the manifest at the base and now.
 * `changed[key]` lists the files under that product's path, and under the
 * paths its `includes` names, that differ between the base and the tree
 * and need a bump; `exempt[key]` lists those that differ but need none
 * (see onlyTestToolingChanged). A product with only exempt changes may
 * keep its version or bump it. A product the base manifest does not list
 * is new: its version only has to be one the policy accepts.
 */
export function checkHistory(oldManifest, newManifest, changed, exempt = {}) {
    const problems = []

    for (const [key, entry] of Object.entries(newManifest.packages)) {
        const old = oldManifest.packages[key]
        const files = changed[key] ?? []
        const exemptFiles = exempt[key] ?? []

        if (old === undefined) {
            const problem = versionProblem(entry.version)

            if (problem !== null) {
                problems.push(`${key}: ${problem}`)
            }

            continue
        }

        const bumped = old.version !== entry.version

        if (!bumped && files.length > 0) {
            const shown = files.slice(0, 3).join(', ') + (files.length > 3 ? ', …' : '')
            // Name each directory that changed, an included one as such, so a
            // contributor who touched only packages/vector-ui sees why
            // kinetis/vector needs a bump too.
            const where = [entry.path, ...(entry.includes ?? [])]
                .filter(dir => files.some(file => under(file, dir)))
                .map(dir => (dir === entry.path ? dir : `${dir} (which ${entry.name} includes)`))
                .join(' and ')
            problems.push(`${key}: files under ${where} changed but its version is still ${entry.version} (${shown}); run node scripts/bump.mjs ${key} <patch|minor>`)
            continue
        }

        if (bumped && files.length === 0 && exemptFiles.length === 0) {
            const where = [entry.path, ...(entry.includes ?? [])].join(' or ')
            problems.push(`${key}: version moved from ${old.version} to ${entry.version}, but nothing under ${where} changed; an unchanged product keeps its version`)
            continue
        }

        if (bumped) {
            const problem = transitionProblem(old.version, entry.version)

            if (problem !== null) {
                problems.push(`${key}: ${problem}`)
            }
        }
    }

    return problems
}

function git(repo, args) {
    const result = spawnSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

    return result.status === 0 ? result.stdout : null
}

function isShallow(repo) {
    return git(repo, ['rev-parse', '--is-shallow-repository'])?.trim() === 'true'
}

/**
 * The commit the history check compares against, or null when there is
 * none: the all-zero SHA GitHub sends for a branch's first push, or a
 * HEAD proven to have no parent. A HEAD with no parent in a shallow
 * checkout is a history this cannot read, not a first commit, and throws.
 */
export function comparisonBase(repo, { base, before }) {
    const named = base ?? (before !== undefined && before.trim() !== '' ? before.trim() : null)

    if (named !== null && /^0+$/.test(named)) {
        return null
    }

    if (named === null) {
        const fields = (git(repo, ['rev-list', '--parents', '-n', '1', 'HEAD']) ?? '').trim().split(/\s+/).filter(Boolean)

        if (fields.length === 0) {
            throw new Error('Could not read HEAD, so there is nothing to compare this change against.')
        }

        if (fields.length === 1) {
            if (isShallow(repo)) {
                throw new Error('HEAD records no parent and the repository is shallow; fetch full history.')
            }

            return null
        }

        return comparisonBase(repo, { base: fields[1] })
    }

    const resolved = git(repo, ['rev-parse', '--verify', '--quiet', `${named}^{commit}`])?.trim()

    if (!resolved) {
        throw new Error(`Comparison base '${named}' is not a commit this checkout can read${isShallow(repo) ? '; the repository is shallow, fetch full history' : ''}.`)
    }

    return resolved
}

/** The manifest at a commit, or null when that commit carries none. */
export function manifestAt(repo, commit) {
    const listed = git(repo, ['ls-tree', '--name-only', commit, '--', MANIFEST_FILE])

    if (listed === null) {
        throw new Error(`Could not read the tree of ${commit}.`)
    }

    if (listed.trim() === '') {
        return null
    }

    return parseManifest(execFileSync('git', ['show', `${commit}:${MANIFEST_FILE}`], { cwd: repo, encoding: 'utf8' }), `${MANIFEST_FILE} at ${commit}`)
}

/**
 * Every file under `dirs` (one directory or several) that differs between
 * `commit` and the working tree, untracked files included. --no-renames
 * makes a file moved between two products count under both.
 */
export function changedFiles(repo, commit, dirs) {
    const paths = [dirs].flat()
    const diff = git(repo, ['diff', '--no-renames', '--name-only', '-z', commit, '--', ...paths])
    const untracked = git(repo, ['ls-files', '--others', '--exclude-standard', '-z', '--', ...paths])

    if (diff === null || untracked === null) {
        throw new Error(`Could not diff ${paths.join(', ')} against ${commit}.`)
    }

    return [...new Set([...diff.split('\0'), ...untracked.split('\0')].filter(file => file !== ''))].sort()
}

/** Reads a repo-relative file at a commit, or null when that commit does not carry it. */
export function commitReader(repo, commit) {
    return file => git(repo, ['show', `${commit}:${file}`])
}

/** Reads a repo-relative file from the working tree, or null when it does not exist. */
export function treeReader(repo) {
    return (file) => {
        const full = path.join(repo, file)

        return existsSync(full) ? readFileSync(full, 'utf8') : null
    }
}

/** Both checks: `lines` says what ran, `problems` what failed; the run passes when `problems` is empty. */
export function validate(repo, { base, before } = {}) {
    const lines = []
    const problems = []
    const report = (label, found) => {
        if (found.length === 0) {
            lines.push(`[${label}] OK.`)
        } else {
            found.forEach(problem => problems.push(`[${label}] ${problem}`))
        }
    }

    const read = treeReader(repo)
    const json = read(MANIFEST_FILE)

    if (json === null) {
        return { lines, problems: [`[consistency] ${MANIFEST_FILE} is missing.`] }
    }

    let manifest

    try {
        manifest = parseManifest(json)
    } catch (error) {
        return { lines, problems: [`[consistency] ${error.message}`] }
    }

    report('consistency', [
        ...checkConsistency(manifest, read),
        ...checkIncludes(manifest, dir => existsSync(path.join(repo, dir)) && statSync(path.join(repo, dir)).isDirectory()),
    ])

    const commit = comparisonBase(repo, { base, before })

    if (commit === null) {
        lines.push('[history] Skipped: there is no earlier commit to compare against.')

        return { lines, problems }
    }

    const old = manifestAt(repo, commit)

    if (old === null) {
        lines.push(`[history] Skipped: the base ${commit} carries no ${MANIFEST_FILE}, so this change introduces the versions.`)

        return { lines, problems }
    }

    const atBase = commitReader(repo, commit)
    const isExempt = file => path.posix.basename(file) === 'package.json' && onlyTestToolingChanged(atBase(file), read(file))
    const changed = {}
    const exempt = {}

    for (const [key, entry] of Object.entries(manifest.packages)) {
        const files = changedFiles(repo, commit, [entry.path, ...(entry.includes ?? [])])
        exempt[key] = files.filter(isExempt)
        changed[key] = files.filter(file => !exempt[key].includes(file))
    }

    lines.push(`[history] Comparing against ${commit}.`)
    report('history', checkHistory(old, manifest, changed, exempt))

    return { lines, problems }
}

/** --base=<ref>, and nothing else. */
export function parseArguments(argv) {
    let base

    for (const arg of argv) {
        if (!arg.startsWith('--base=')) {
            throw new Error(`Unknown argument ${arg}. Usage: validate-versions.mjs [--base=<ref>]`)
        }

        if (base !== undefined) {
            throw new Error('--base is given more than once.')
        }

        base = arg.slice('--base='.length).trim()

        // An empty --base was meant to name something; reading it as no
        // base would quietly answer a different question.
        if (base === '') {
            throw new Error('--base needs a commit id or a ref name.')
        }
    }

    return { base }
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const { base } = parseArguments(process.argv.slice(2))
        const { lines, problems } = validate(root, { base, before: process.env.GITHUB_EVENT_BEFORE })
        lines.forEach(line => console.log(line))
        problems.forEach(problem => console.error(process.env.GITHUB_ACTIONS ? `::error::${problem}` : problem))
        process.exitCode = problems.length === 0 ? 0 : 1
    } catch (error) {
        console.error(process.env.GITHUB_ACTIONS ? `::error::${error.message}` : error.message)
        process.exitCode = 1
    }
}
