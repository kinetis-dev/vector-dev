#!/usr/bin/env node
// What @kinetis/vector-ui packs: the hosted bundle in dist/static, which
// `vector:install-ui` installs.
//
//     node scripts/check-vector-ui-pack.mjs            prepack: the bundle is built, for this version
//     node scripts/check-vector-ui-pack.mjs --packed   CI: npm would pack every file of it
//
// The prepack check refuses to pack without a bundle, or with one built for
// another version. --packed asks `npm pack --dry-run --json` what it would
// pack and refuses unless that holds vector-bundle.json and every file it
// lists — dotfiles included, which npm's own rules could drop. Build the
// bundle first with `pnpm generate:hosted`.
import { spawnSync } from 'node:child_process'
import { readFile, stat } from 'node:fs/promises'
import { fileURLToPath, pathToFileURL } from 'node:url'

/** Where the bundle sits in the package. */
export const BUNDLE_DIR = 'dist/static'

const packageDir = fileURLToPath(new URL('../packages/vector-ui', import.meta.url))

/** Why dir cannot be packed as version, or null when it can. */
export async function packProblem(dir, version) {
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

    return null
}

/** The paths `npm pack --dry-run --json` says it would pack. */
export function packedFiles(json) {
    const [pack] = JSON.parse(json)

    if (!Array.isArray(pack?.files)) {
        throw new Error('npm pack --json answered without a file list.')
    }

    return pack.files.map(file => file.path)
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

async function checkPacked() {
    const version = JSON.parse(await readFile(`${packageDir}/package.json`, 'utf8')).version
    const problem = await packProblem(`${packageDir}/${BUNDLE_DIR}`, version)

    if (problem !== null) {
        return [problem]
    }

    // The scripts are this check's own prepack and nuxt's prepare; neither changes what is packed.
    const result = spawnSync('npm', ['pack', '--dry-run', '--json', '--ignore-scripts'], { cwd: packageDir, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

    if (result.status !== 0) {
        return [`npm pack --dry-run failed: ${result.stderr.trim()}`]
    }

    const manifest = JSON.parse(await readFile(`${packageDir}/${BUNDLE_DIR}/vector-bundle.json`, 'utf8'))
    const missing = missingFromPack(packedFiles(result.stdout), manifest)

    if (missing.length > 0) {
        return missing.map(file => `${file} is in the bundle but npm would not pack it.`)
    }

    console.log(`@kinetis/vector-ui ${version} packs vector-bundle.json and all ${Object.keys(manifest.files).length} files it lists.`)

    return []
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const args = process.argv.slice(2)

    if (args.length > 1 || (args.length === 1 && args[0] !== '--packed')) {
        console.error('Usage: check-vector-ui-pack.mjs [--packed]')
        process.exitCode = 2
    } else {
        const problems = args[0] === '--packed'
            ? await checkPacked()
            : [await packProblem(`${packageDir}/${BUNDLE_DIR}`, process.env.npm_package_version)].filter(problem => problem !== null)

        problems.forEach(problem => console.error(problem))
        process.exitCode = problems.length === 0 ? 0 : 1
    }
}
