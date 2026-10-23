#!/usr/bin/env node
// @kinetis/vector-ui's prepack: the package ships the hosted bundle in
// dist/static, so it is not packed without one, or with one built for
// another version. Build it first with
// `node scripts/build-hosted-bundle.mjs --version=<its version>`.
import { readFile, stat } from 'node:fs/promises'
import { fileURLToPath, pathToFileURL } from 'node:url'

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

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const dir = fileURLToPath(new URL('../packages/vector-ui/dist/static', import.meta.url))
    const problem = await packProblem(dir, process.env.npm_package_version)

    if (problem !== null) {
        console.error(problem)
        process.exitCode = 1
    }
}
