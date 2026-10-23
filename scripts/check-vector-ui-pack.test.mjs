// node --test scripts/*.test.mjs — @kinetis/vector-ui's prepack check on
// fixture directories.
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { afterEach, beforeEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { missingFromPack, packProblem, tarballFiles } from './check-vector-ui-pack.mjs'

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

// What `tar -tzf` prints for an npm tarball: everything under package/.
const tarList = paths => `${paths.map(file => `package/${file}`).join('\n')}\n`

const bundle = { version: '0.11.0', files: { 'index.html': 'a', '_nuxt/entry.js': 'b', '_nuxt/.vite/manifest.json': 'c', '.well-known/x.txt': 'd' } }
const whole = ['package.json', 'nuxt.config.ts', 'dist/static/vector-bundle.json', 'dist/static/index.html', 'dist/static/_nuxt/entry.js', 'dist/static/_nuxt/.vite/manifest.json', 'dist/static/.well-known/x.txt']

test('the packed list holds the manifest and every file it lists', () => {
    assert.deepEqual(tarballFiles(tarList(whole)), whole)
    assert.deepEqual(missingFromPack(tarballFiles(tarList(whole)), bundle), [])
    // a UUID-named build file, which npm's own output would redact, is listed as it is
    const uuid = 'dist/static/_nuxt/builds/meta/54a820c8-b086-410d-ba62-bed88bdac31d.json'
    assert.deepEqual(missingFromPack(tarballFiles(tarList([...whole, uuid])), { files: { ...bundle.files, '_nuxt/builds/meta/54a820c8-b086-410d-ba62-bed88bdac31d.json': 'e' } }), [])
})

test('a dotfile npm would drop is caught', () => {
    const packed = whole.filter(file => file !== 'dist/static/_nuxt/.vite/manifest.json' && file !== 'dist/static/.well-known/x.txt')
    assert.deepEqual(missingFromPack(packed, bundle), ['dist/static/_nuxt/.vite/manifest.json', 'dist/static/.well-known/x.txt'])
})

test('a pack without the manifest itself, or any bundle file, is caught', () => {
    assert.deepEqual(missingFromPack(whole.filter(file => file !== 'dist/static/vector-bundle.json'), bundle), ['dist/static/vector-bundle.json'])
    assert.equal(missingFromPack(['package.json'], bundle).length, 5)
    // a file elsewhere in the package does not stand in for the bundle's
    assert.deepEqual(missingFromPack(['vector-bundle.json', ...whole.filter(file => file !== 'dist/static/vector-bundle.json')], bundle), ['dist/static/vector-bundle.json'])
})

test('a tarball listing: directories left out, anything outside package/ refused', () => {
    assert.deepEqual(tarballFiles('package/dist/\npackage/dist/static/index.html\n'), ['dist/static/index.html'])
    assert.throws(() => tarballFiles('package/a\nother/b\n'), /holds other\/b, outside package\//)
})

test('the script refuses an unknown argument', () => {
    const script = fileURLToPath(new URL('./check-vector-ui-pack.mjs', import.meta.url))
    const result = spawnSync(process.execPath, [script, '--pack'], { encoding: 'utf8' })

    assert.equal(result.status, 2)
    assert.match(result.stderr, /Usage/)
})
