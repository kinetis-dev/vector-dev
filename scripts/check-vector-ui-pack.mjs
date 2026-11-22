#!/usr/bin/env node
// What a package that ships a hosted bundle packs: the bundle in
// dist/static, which its install command installs. @kinetis/vector-ui by
// default; --package=vector-ai-ui for @kinetis/vector-ai-ui.
//
//     node scripts/check-vector-ui-pack.mjs [--package=…]            prepack: the bundle is built, for this version
//     node scripts/check-vector-ui-pack.mjs [--package=…] --packed   CI: npm would pack every file of it
//
// The prepack check refuses to pack without a bundle, or with one built for
// another package or version, or, for a composed bundle, with another
// version of what it includes than the package depends on. --packed packs the package with npm, as the release
// publishes it, lists the tarball, and refuses unless it holds
// vector-bundle.json and every file it lists — dotfiles included, which
// npm's own rules could drop. The tarball is listed rather than npm's
// `--json` report, because npm redacts anything shaped like a UUID in its
// output, and Nuxt names a build file by one. Build the bundle first with
// `pnpm generate:hosted`.
import { spawnSync } from 'node:child_process'
import { mkdtemp, readFile, readdir, rm, stat } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { HOSTED_PACKAGES, PACKAGE, resolveIncludes } from './build-hosted-bundle.mjs'

/** Where the bundle sits in the package. */
export const BUNDLE_DIR = 'dist/static'

const USAGE = `Usage: check-vector-ui-pack.mjs [--package=${Object.keys(HOSTED_PACKAGES).join('|')}] [--packed]`

/** The package directory (vector-ui by default) and whether to check the packed tarball. */
export function parseArgs(argv) {
    let pkg = 'vector-ui'
    let packed = false

    for (const arg of argv) {
        if (arg.startsWith('--package=') && Object.hasOwn(HOSTED_PACKAGES, arg.slice('--package='.length))) {
            pkg = arg.slice('--package='.length)
        } else if (arg === '--packed') {
            packed = true
        } else {
            throw new Error(`Unknown argument ${arg}. ${USAGE}`)
        }
    }

    return { package: pkg, packed }
}

/**
 * Why dir cannot be packed as version of the package named, or null when
 * it can. includes names, for a composed bundle, each included package's
 * version its manifest must carry.
 */
export async function packProblem(dir, version, { name = PACKAGE, includes = undefined } = {}) {
    if (!await stat(`${dir}/index.html`).then(s => s.isFile(), () => false)) {
        return `${dir}/index.html is missing: build the hosted bundle before packing.`
    }

    let manifest

    try {
        manifest = JSON.parse(await readFile(`${dir}/vector-bundle.json`, 'utf8'))
    } catch {
        return `${dir}/vector-bundle.json is missing or not JSON: build the hosted bundle before packing.`
    }

    if (version === undefined || manifest?.version !== version) {
        return `${dir}/vector-bundle.json is version ${manifest?.version}, not ${version}: build the hosted bundle with --version=${version}.`
    }

    if (manifest.package !== name) {
        return `${dir}/vector-bundle.json was built for ${manifest.package}, not ${name}: build this package's hosted bundle.`
    }

    for (const [included, at] of Object.entries(includes ?? {})) {
        if (manifest.includes?.[included] !== at) {
            return `${dir}/vector-bundle.json includes ${included} ${manifest.includes?.[included]}, not ${at}, the release ${name} depends on: build the hosted bundle again.`
        }
    }

    return null
}

/** The HOSTED_PACKAGES entry, directory and package.json of the package named. */
async function hostedPackage(pkg) {
    const dir = fileURLToPath(new URL(`../packages/${pkg}`, import.meta.url))
    const packageJson = JSON.parse(await readFile(`${dir}/package.json`, 'utf8'))
    const entry = HOSTED_PACKAGES[pkg]

    return { dir, packageJson, expect: { name: entry.name, includes: resolveIncludes(entry, packageJson) } }
}

/**
 * The files `tar -tzf` lists in an npm tarball, relative to the package:
 * npm packs everything under `package/`. Directory entries are left out.
 */
export function tarballFiles(listing) {
    const lines = listing.split('\n').filter(line => line !== '' && !line.endsWith('/'))
    const outside = lines.find(line => !line.startsWith('package/'))

    if (outside !== undefined) {
        throw new Error(`The tarball holds ${outside}, outside package/.`)
    }

    return lines.map(line => line.slice('package/'.length))
}

/**
 * The bundle files `packed` lacks: vector-bundle.json itself and every
 * file its `files` lists, under dist/static. Empty when nothing is missing.
 */
export function missingFromPack(packed, manifest) {
    const have = new Set(packed)
    const wanted = ['vector-bundle.json', ...Object.keys(manifest?.files ?? {})].map(file => `${BUNDLE_DIR}/${file}`)

    return wanted.filter(file => !have.has(file))
}

async function checkPacked(pkg) {
    const { dir: packageDir, packageJson, expect } = await hostedPackage(pkg)
    const version = packageJson.version
    const problem = await packProblem(`${packageDir}/${BUNDLE_DIR}`, version, expect)

    if (problem !== null) {
        return [problem]
    }

    const scratch = await mkdtemp(path.join(tmpdir(), 'vector-ui-pack-'))
    let packed

    try {
        // The scripts are this check's own prepack, run above, and nuxt's prepare; neither changes what is packed.
        const pack = spawnSync('npm', ['pack', '--ignore-scripts', '--pack-destination', scratch], { cwd: packageDir, encoding: 'utf8' })

        if (pack.status !== 0) {
            return [`npm pack failed: ${pack.stderr.trim()}`]
        }

        const [tarball] = (await readdir(scratch)).filter(file => file.endsWith('.tgz'))
        const list = spawnSync('tar', ['-tzf', path.join(scratch, tarball)], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

        if (list.status !== 0) {
            return [`Could not list ${tarball}: ${list.stderr.trim()}`]
        }

        packed = tarballFiles(list.stdout)
    } finally {
        await rm(scratch, { recursive: true, force: true })
    }

    const manifest = JSON.parse(await readFile(`${packageDir}/${BUNDLE_DIR}/vector-bundle.json`, 'utf8'))
    const missing = missingFromPack(packed, manifest)

    if (missing.length > 0) {
        return missing.map(file => `${file} is in the bundle but npm would not pack it.`)
    }

    console.log(`${expect.name} ${version} packs vector-bundle.json and all ${Object.keys(manifest.files).length} files it lists.`)

    return []
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    let args

    try {
        args = parseArgs(process.argv.slice(2))
    } catch (error) {
        console.error(error.message)
        process.exitCode = 2
    }

    if (args !== undefined) {
        const { dir, expect } = await hostedPackage(args.package)
        const problems = args.packed
            ? await checkPacked(args.package)
            : [await packProblem(`${dir}/${BUNDLE_DIR}`, process.env.npm_package_version, expect)].filter(problem => problem !== null)

        problems.forEach(problem => console.error(problem))
        process.exitCode = problems.length === 0 ? 0 : 1
    }
}
