#!/usr/bin/env node
// Builds the hosted admin bundle into packages/vector/resources/dist and
// writes its manifest, vector-bundle.json: the package and version that
// built it, the vector-dev commit, the descriptor contract it speaks and
// the sha256 of every file. `vector:install` refuses a bundle that does
// not match its manifest, so a stale build is never published.
//
//     node scripts/build-hosted-bundle.mjs                  a dev build: version dev-<short sha>
//     node scripts/build-hosted-bundle.mjs --version=0.9.0  a release build
//
// A release version must be canonical X.Y.Z and equal kinetis/vector's
// version in packages.manifest.json: the bundle ships inside that package,
// and on a released install `vector:install` refuses a bundle built for any
// version but the installed kinetis/vector's.
import { execFileSync, spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { createReadStream } from 'node:fs'
import { cp, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { pipeline } from 'node:stream/promises'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { parseVersion } from './version-policy.mjs'

export const MANIFEST = 'vector-bundle.json'
export const PACKAGE = 'kinetis/vector'

const root = fileURLToPath(new URL('..', import.meta.url))
const dist = path.join(root, 'packages/vector/resources/dist')
const output = path.join(root, 'packages/vector-ui/hosted/.output/public')
const registry = path.join(root, 'packages/vector/src/Registry/VectorRegistry.php')
const versions = path.join(root, 'packages.manifest.json')

/** The requested release version, or null for a dev build. */
export function parseArgs(argv) {
    let version = null

    for (const arg of argv) {
        if (arg.startsWith('--version=')) {
            version = arg.slice('--version='.length)
        } else {
            throw new Error(`Unknown argument ${arg}. Usage: build-hosted-bundle.mjs [--version=<x.y.z>]`)
        }
    }

    return { version }
}

/** The manifest version: the release, checked, or dev-<12-hex short sha>. */
export function resolveVersion(requested, packageVersion, source) {
    if (requested === null) {
        return `dev-${source.slice(0, 12)}`
    }

    if (parseVersion(requested) === null) {
        throw new Error(`--version=${requested} is not a canonical X.Y.Z version (no leading v, no prerelease).`)
    }

    if (requested !== packageVersion) {
        throw new Error(`--version=${requested} differs from kinetis/vector's version ${packageVersion} in packages.manifest.json; the bundle ships in that release.`)
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

async function sha256(file) {
    const hash = createHash('sha256')
    await pipeline(createReadStream(file), hash)

    return hash.digest('hex')
}

/** Writes dir/vector-bundle.json describing every other file in dir, and returns it. */
export async function writeManifest(dir, { version, descriptorVersion, source }) {
    const files = {}

    for (const file of await listFiles(dir)) {
        files[file] = await sha256(path.join(dir, file))
    }

    const manifest = { package: PACKAGE, version, descriptorVersion, source, files }
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
    const { version: requested } = parseArgs(process.argv.slice(2))
    const source = await headCommit(root)

    const packageVersion = JSON.parse(await readFile(versions, 'utf8')).packages.vector.version
    const version = resolveVersion(requested, packageVersion, source)
    const descriptor = descriptorVersion(await readFile(registry, 'utf8'))

    const build = spawnSync('pnpm', ['--filter', '@kinetis/vector-ui', 'exec', 'nuxt', 'generate', 'hosted'], {
        cwd: root,
        env: { ...process.env, NUXT_APP_BASE_URL: '/vector/ui/' },
        stdio: 'inherit',
    })

    if (build.status !== 0) {
        throw new Error(build.error?.message ?? `nuxt generate hosted exited with ${build.status ?? build.signal}.`)
    }

    await rm(dist, { recursive: true, force: true })
    await cp(output, dist, { recursive: true })
    const manifest = await writeManifest(dist, { version, descriptorVersion: descriptor, source })

    console.log(`Built the hosted admin bundle ${version} (${Object.keys(manifest.files).length} files, descriptor version ${descriptor}) into packages/vector/resources/dist.`)
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        console.error(error.message)
        process.exitCode = 1
    })
}
