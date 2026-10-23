// node --test scripts/*.test.mjs — @kinetis/vector-ui's prepack check on
// fixture directories.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { afterEach, beforeEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { packProblem } from './check-vector-ui-pack.mjs'

let dir

beforeEach(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'vector-pack-'))
})

afterEach(() => rm(dir, { recursive: true, force: true }))

const manifest = version => writeFile(path.join(dir, 'vector-bundle.json'), JSON.stringify({ package: '@kinetis/vector-ui', version }))

test('a bundle built for the version being packed passes', async () => {
    await writeFile(path.join(dir, 'index.html'), '<!doctype html>')
    await manifest('0.11.0')

    assert.equal(await packProblem(dir, '0.11.0'), null)
})

test('no index.html fails', async () => {
    await manifest('0.11.0')

    assert.match(await packProblem(dir, '0.11.0'), /index\.html is missing/)
})

test('no manifest, or a malformed one, fails', async () => {
    await writeFile(path.join(dir, 'index.html'), '<!doctype html>')
    assert.match(await packProblem(dir, '0.11.0'), /vector-bundle\.json is missing/)

    await writeFile(path.join(dir, 'vector-bundle.json'), '{')
    assert.match(await packProblem(dir, '0.11.0'), /not JSON/)
})

test('a bundle of another version fails, as does packing without a version', async () => {
    await writeFile(path.join(dir, 'index.html'), '<!doctype html>')
    await manifest('dev-0123456789ab')

    assert.match(await packProblem(dir, '0.11.0'), /is version dev-0123456789ab, not 0\.11\.0/)
    assert.notEqual(await packProblem(dir, undefined), null)
})

test('the script exits non-zero when the check fails', () => {
    const script = fileURLToPath(new URL('./check-vector-ui-pack.mjs', import.meta.url))
    // a version no build of the repository carries
    const result = spawnSync(process.execPath, [script], { env: { ...process.env, npm_package_version: '0.0.0-never' }, encoding: 'utf8' })

    assert.equal(result.status, 1)
    assert.match(result.stderr, /build the hosted bundle/)
})
