#!/usr/bin/env node
// Builds a package's hosted admin bundle into packages/<package>/dist/static
// and writes its manifest, vector-bundle.json: the package and version that
// built it, the vector-dev commit, the descriptor contract it speaks, the
// npm packages and versions it was built with (`includes`, for a bundle
// composed over @kinetis/vector-ui) and the sha256 of every file. The npm
// package ships that directory, and its install command refuses a bundle
// that does not match its manifest, so a stale build is never installed.
//
//     node scripts/build-hosted-bundle.mjs                  @kinetis/vector-ui, at its package.json version
//     node scripts/build-hosted-bundle.mjs --version=0.9.0  the same, named: it must be that version
//     node scripts/build-hosted-bundle.mjs --dev            a dev build: version dev-<short sha>
//     node scripts/build-hosted-bundle.mjs --package=vector-ai-ui [--version=… | --dev]
//
// The bundle is labelled with its package's version in its package.json:
// it ships inside that package, and the install command installs it as
// that version. Each package's `pnpm generate:hosted` runs this script. A
// dev build, which an install command accepts only on a dev install, is
// never packed (scripts/check-vector-ui-pack.mjs).
import { execFileSync, spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { createReadStream } from 'node:fs'
import { cp, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { pipeline } from 'node:stream/promises'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { parseVersion } from './version-policy.mjs'

export const MANIFEST = 'vector-bundle.json'
export const PACKAGE = '@kinetis/vector-ui'

/**
 * The packages that ship a hosted bundle, by their directory under
 * packages/: the npm package, and the npm packages the bundle is built
 * with, at the exact version the package's package.json depends on.
 */
export const HOSTED_PACKAGES = {
    'vector-ui': { name: PACKAGE, includes: [] },
    'vector-ai-ui': { name: '@kinetis/vector-ai-ui', includes: [PACKAGE] },
}

const root = fileURLToPath(new URL('..', import.meta.url))
const registry = path.join(root, 'packages/vector/src/Registry/VectorRegistry.php')

const USAGE = `Usage: build-hosted-bundle.mjs [--package=${Object.keys(HOSTED_PACKAGES).join('|')}] [--version=<x.y.z> | --dev]`

/** The package directory named (vector-ui by default), the version named or null for package.json's, and whether this is a dev build. */
export function parseArgs(argv) {
    let pkg = 'vector-ui'
    let version = null
    let dev = false

    for (const arg of argv) {
        if (arg.startsWith('--package=')) {
            pkg = arg.slice('--package='.length)

            if (!Object.hasOwn(HOSTED_PACKAGES, pkg)) {
                throw new Error(`--package=${pkg} ships no hosted bundle. ${USAGE}`)
            }
        } else if (arg.startsWith('--version=')) {
            version = arg.slice('--version='.length)
        } else if (arg === '--dev') {
            dev = true
        } else {
            throw new Error(`Unknown argument ${arg}. ${USAGE}`)
        }
    }

    if (dev && version !== null) {
        throw new Error('--dev builds dev-<short sha>; it takes no --version.')
    }

    return { package: pkg, version, dev }
}

/**
 * What the manifest's `includes` names for the package with this
 * HOSTED_PACKAGES entry and package.json: each included npm package at
 * the exact version the package depends on. Undefined when it includes
 * none.
 */
export function resolveIncludes(entry, packageJson) {
    if (entry.includes.length === 0) {
        return undefined
    }

    const includes = {}

    for (const name of entry.includes) {
        const version = packageJson.dependencies?.[name]

        if (typeof version !== 'string' || parseVersion(version) === null) {
            throw new Error(`${entry.name} must depend on exactly one ${name} release (X.Y.Z), not ${JSON.stringify(version)}: its bundle includes it.`)
        }

        includes[name] = version
    }

    return includes
}

/** The manifest version: package.json's, the one named and checked against it, or dev-<12-hex short sha>. */
export function resolveVersion(requested, packageVersion, source, dev = false, name = PACKAGE) {
    if (dev) {
        return `dev-${source.slice(0, 12)}`
    }

    if (requested === null) {
        return packageVersion
    }

    if (parseVersion(requested) === null) {
        throw new Error(`--version=${requested} is not a canonical X.Y.Z version (no leading v, no prerelease).`)
    }

    if (requested !== packageVersion) {
        throw new Error(`--version=${requested} differs from ${name}'s version ${packageVersion} in its package.json; the bundle ships in that release.`)
    }

    return requested
}

/** VectorRegistry::VERSION, read from the PHP source rather than repeated here. */
export function descriptorVersion(php) {
    const match = /\bpublic const int VERSION = (\d+);/.exec(php)

    if (match === null) {
        throw new Error('Could not read VectorRegistry::VERSION from packages/vector/src/Registry/VectorRegistry.php.')
    }

    return Number(match[1])
}

/** Every file under dir except the manifest, as sorted `/`-separated relative paths. */
export async function listFiles(dir) {
    const entries = await readdir(dir, { recursive: true, withFileTypes: true })

    return entries
        .filter(entry => entry.isFile())
        .map(entry => path.relative(dir, path.join(entry.parentPath, entry.name)).split(path.sep).join('/'))
        .filter(file => file !== MANIFEST)
        .sort()
}

/** Replaces dist with the generated site's contents, as real files: npm packs no symlink. */
export async function copyBundle(from, dist) {
    await rm(dist, { recursive: true, force: true })
    await cp(from, dist, { recursive: true, dereference: true })
}

async function sha256(file) {
    const hash = createHash('sha256')
    await pipeline(createReadStream(file), hash)

    return hash.digest('hex')
}

/** Writes dir/vector-bundle.json describing every other file in dir, and returns it. */
export async function writeManifest(dir, { package: name = PACKAGE, version, descriptorVersion, source, includes }) {
    const files = {}

    for (const file of await listFiles(dir)) {
        files[file] = await sha256(path.join(dir, file))
    }

    const manifest = { package: name, version, descriptorVersion, source, ...(includes === undefined ? {} : { includes }), files }
    await writeFile(path.join(dir, MANIFEST), `${JSON.stringify(manifest, null, 4)}\n`)

    return manifest
}

/**
 * The commit checked out in repo: `git rev-parse HEAD`, or, where git is
 * not installed (the demo's node container), HEAD resolved from the
 * repository's own files — a detached commit, a loose ref or a packed
 * one, in a clone or a worktree.
 */
export async function headCommit(repo) {
    let commit

    try {
        commit = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repo, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw new Error('git rev-parse HEAD failed; the bundle needs the commit it is built from.', { cause: error })
        }

        let gitDir = path.join(repo, '.git')

        if ((await stat(gitDir)).isFile()) {
            gitDir = path.resolve(repo, (await readFile(gitDir, 'utf8')).replace(/^gitdir:\s*/, '').trim())
        }

        const commonDir = await readFile(path.join(gitDir, 'commondir'), 'utf8')
            .then(dir => path.resolve(gitDir, dir.trim()), () => gitDir)
        const head = (await readFile(path.join(gitDir, 'HEAD'), 'utf8')).trim()
        const ref = /^ref:\s*(.+)$/.exec(head)?.[1]

        commit = ref === undefined
            ? head
            : await readFile(path.join(commonDir, ref), 'utf8').then(
                    loose => loose.trim(),
                    async () => (await readFile(path.join(commonDir, 'packed-refs'), 'utf8').catch(() => ''))
                        .split('\n')
                        .find(line => line.endsWith(` ${ref}`))
                        ?.split(' ')[0] ?? '',
                )
    }

    if (!/^[0-9a-f]{40}$/.test(commit)) {
        throw new Error(`Could not resolve HEAD to a commit (got "${commit}"); the bundle needs the commit it is built from.`)
    }

    return commit
}

async function main() {
    const { package: pkg, version: requested, dev } = parseArgs(process.argv.slice(2))
    const entry = HOSTED_PACKAGES[pkg]
    const packageDir = path.join(root, 'packages', pkg)
    const dist = path.join(packageDir, 'dist/static')
    const output = path.join(packageDir, 'hosted/.output/public')
    const source = await headCommit(root)

    const packageJson = JSON.parse(await readFile(path.join(packageDir, 'package.json'), 'utf8'))
    const version = resolveVersion(requested, packageJson.version, source, dev, entry.name)
    const includes = resolveIncludes(entry, packageJson)
    const descriptor = descriptorVersion(await readFile(registry, 'utf8'))

    const build = spawnSync('pnpm', ['--filter', entry.name, 'exec', 'nuxt', 'generate', 'hosted'], {
        cwd: root,
        env: { ...process.env, NUXT_APP_BASE_URL: '/vector/ui/' },
        stdio: 'inherit',
    })

    if (build.status !== 0) {
        throw new Error(build.error?.message ?? `nuxt generate hosted exited with ${build.status ?? build.signal}.`)
    }

    await copyBundle(output, dist)
    const manifest = await writeManifest(dist, { package: entry.name, version, descriptorVersion: descriptor, source, includes })
    const built = includes === undefined ? '' : `, built with ${Object.entries(includes).map(([name, at]) => `${name} ${at}`).join(', ')}`

    console.log(`Built the hosted admin bundle ${entry.name} ${version} (${Object.keys(manifest.files).length} files, descriptor version ${descriptor}${built}) into packages/${pkg}/dist/static.`)
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(error.message)
        process.exitCode = 1
    })
}
