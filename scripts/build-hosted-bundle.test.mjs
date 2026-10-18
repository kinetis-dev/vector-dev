// node --test scripts/*.test.mjs — the manifest writer on a fixture
// directory; the Nuxt build itself is not run here.
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { after, before, test } from 'node:test'
import { descriptorVersion, headCommit, listFiles, parseArgs, resolveVersion, writeManifest } from './build-hosted-bundle.mjs'

const source = '0123456789abcdef0123456789abcdef01234567'
let dir

before(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'vector-bundle-'))
    await mkdir(path.join(dir, '_nuxt/b'), { recursive: true })
    await writeFile(path.join(dir, 'index.html'), '<!doctype html>')
    await writeFile(path.join(dir, '_nuxt/b/z.js'), 'z')
    await writeFile(path.join(dir, '_nuxt/a.css'), 'a')
    // a manifest from an earlier run is never listed in the new one
    await writeFile(path.join(dir, 'vector-bundle.json'), '{}')
})

after(() => rm(dir, { recursive: true, force: true }))

const sha256 = contents => createHash('sha256').update(contents).digest('hex')

test('the manifest lists every other file, sorted, with its sha256', async () => {
    const written = await writeManifest(dir, { version: '0.9.0', descriptorVersion: 4, source })
    const manifest = JSON.parse(await readFile(path.join(dir, 'vector-bundle.json'), 'utf8'))

    assert.deepEqual(manifest, written)
    assert.deepEqual(Object.keys(manifest), ['package', 'version', 'descriptorVersion', 'source', 'files'])
    assert.equal(manifest.package, 'kinetis/vector')
    assert.equal(manifest.version, '0.9.0')
    assert.equal(manifest.descriptorVersion, 4)
    assert.equal(manifest.source, source)
    assert.deepEqual(manifest.files, {
        '_nuxt/a.css': sha256('a'),
        '_nuxt/b/z.js': sha256('z'),
        'index.html': sha256('<!doctype html>'),
    })
    assert.deepEqual(Object.keys(manifest.files), await listFiles(dir))
})

test('a dev build is versioned by the short commit', () => {
    assert.equal(resolveVersion(null, '0.9.0', source), 'dev-0123456789ab')
})

test('a release version must be SemVer and the shared package version', () => {
    assert.equal(resolveVersion('0.9.0', '0.9.0', source), '0.9.0')
    assert.equal(resolveVersion('1.0.0-rc.1', '1.0.0-rc.1', source), '1.0.0-rc.1')
    assert.throws(() => resolveVersion('v0.9.0', 'v0.9.0', source), /not a SemVer version/)
    assert.throws(() => resolveVersion('0.9', '0.9', source), /not a SemVer version/)
    assert.throws(() => resolveVersion('0.9.1', '0.9.0', source), /differs from packages\/vector-ui\/package.json/)
})

test('arguments: --version only', () => {
    assert.deepEqual(parseArgs([]), { version: null })
    assert.deepEqual(parseArgs(['--version=0.9.0']), { version: '0.9.0' })
    assert.throws(() => parseArgs(['--release']), /Unknown argument --release/)
})

test('the descriptor version is read from VectorRegistry', async () => {
    const php = await readFile(new URL('../packages/vector/src/Registry/VectorRegistry.php', import.meta.url), 'utf8')

    assert.ok(Number.isInteger(descriptorVersion(php)))
    assert.equal(descriptorVersion('    public const int VERSION = 7;'), 7)
    assert.throws(() => descriptorVersion('<?php'), /Could not read VectorRegistry::VERSION/)
})

test('the source commit is HEAD', async () => {
    assert.match(await headCommit(new URL('..', import.meta.url).pathname), /^[0-9a-f]{40}$/)
})
