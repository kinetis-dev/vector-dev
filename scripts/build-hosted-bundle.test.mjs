// node --test scripts/*.test.mjs — the manifest writer on a fixture
// directory; the Nuxt build itself is not run here.
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { lstat, mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { after, before, test } from 'node:test'
import { copyBundle, descriptorVersion, HOSTED_PACKAGES, headCommit, listFiles, parseArgs, resolveIncludes, resolveVersion, writeManifest } from './build-hosted-bundle.mjs'

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
    assert.equal(manifest.package, '@kinetis/vector-ui')
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

test('the bundle is copied as real files, replacing what an earlier build left', async () => {
    const work = await mkdtemp(path.join(tmpdir(), 'vector-copy-'))

    try {
        const from = path.join(work, 'public')
        const dist = path.join(work, 'dist/static')
        await mkdir(path.join(from, '_nuxt'), { recursive: true })
        await writeFile(path.join(work, 'real.js'), 'real')
        await writeFile(path.join(from, 'index.html'), '<!doctype html>')
        await symlink(path.join(work, 'real.js'), path.join(from, '_nuxt/linked.js'))
        await mkdir(dist, { recursive: true })
        await writeFile(path.join(dist, 'stale.js'), 'stale')

        await copyBundle(from, dist)

        assert.deepEqual(await listFiles(dist), ['_nuxt/linked.js', 'index.html'])
        assert.ok((await lstat(path.join(dist, '_nuxt/linked.js'))).isFile())
        assert.equal(await readFile(path.join(dist, '_nuxt/linked.js'), 'utf8'), 'real')
    } finally {
        await rm(work, { recursive: true, force: true })
    }
})

test('a dev build (--dev) is versioned by the short commit', () => {
    assert.equal(resolveVersion(null, '0.9.0', source, true), 'dev-0123456789ab')
})

test("a release version must be canonical X.Y.Z and @kinetis/vector-ui's package.json version", () => {
    assert.equal(resolveVersion(null, '0.9.0', source), '0.9.0', "unnamed, it is package.json's")
    assert.equal(resolveVersion('0.9.0', '0.9.0', source), '0.9.0')
    assert.throws(() => resolveVersion('1.0.0-rc.1', '1.0.0-rc.1', source), /not a canonical X\.Y\.Z version/)
    assert.throws(() => resolveVersion('v0.9.0', 'v0.9.0', source), /not a canonical X\.Y\.Z version/)
    assert.throws(() => resolveVersion('0.9', '0.9', source), /not a canonical X\.Y\.Z version/)
    assert.throws(() => resolveVersion('0.9.1', '0.9.0', source), /differs from @kinetis\/vector-ui's version 0\.9\.0 in its package\.json/)
})

test('arguments: --version or --dev, not both', () => {
    assert.deepEqual(parseArgs([]), { package: 'vector-ui', version: null, dev: false })
    assert.deepEqual(parseArgs(['--version=0.9.0']), { package: 'vector-ui', version: '0.9.0', dev: false })
    assert.deepEqual(parseArgs(['--dev']), { package: 'vector-ui', version: null, dev: true })
    assert.throws(() => parseArgs(['--dev', '--version=0.9.0']), /takes no --version/)
    assert.throws(() => parseArgs(['--release']), /Unknown argument --release/)
})

test('arguments: --package names a package that ships a hosted bundle, vector-ui by default', () => {
    assert.deepEqual(parseArgs(['--package=vector-ai-ui', '--version=0.1.0']), { package: 'vector-ai-ui', version: '0.1.0', dev: false })
    assert.deepEqual(parseArgs(['--package=vector-ui', '--dev']), { package: 'vector-ui', version: null, dev: true })
    assert.throws(() => parseArgs(['--package=vector']), /--package=vector ships no hosted bundle/)
    assert.throws(() => parseArgs(['--package=']), /ships no hosted bundle/)
})

test("a composed bundle includes the exact @kinetis/vector-ui release its package.json depends on", () => {
    assert.equal(resolveIncludes(HOSTED_PACKAGES['vector-ui'], { dependencies: {} }), undefined)
    assert.deepEqual(resolveIncludes(HOSTED_PACKAGES['vector-ai-ui'], { dependencies: { '@kinetis/vector-ui': '0.13.0' } }), { '@kinetis/vector-ui': '0.13.0' })
    assert.throws(() => resolveIncludes(HOSTED_PACKAGES['vector-ai-ui'], { dependencies: { '@kinetis/vector-ui': '^0.13.0' } }), /must depend on exactly one @kinetis\/vector-ui release/)
    assert.throws(() => resolveIncludes(HOSTED_PACKAGES['vector-ai-ui'], {}), /must depend on exactly one @kinetis\/vector-ui release/)
})

test("the repository's vector-ai-ui includes the @kinetis/vector-ui it depends on", async () => {
    const ai = JSON.parse(await readFile(new URL('../packages/vector-ai-ui/package.json', import.meta.url), 'utf8'))

    assert.deepEqual(resolveIncludes(HOSTED_PACKAGES['vector-ai-ui'], ai), { '@kinetis/vector-ui': ai.dependencies['@kinetis/vector-ui'] })
})

test('a composed bundle\'s manifest names its package and what it includes, before its files', async () => {
    const written = await writeManifest(dir, { package: '@kinetis/vector-ai-ui', version: '0.1.0', descriptorVersion: 6, source, includes: { '@kinetis/vector-ui': '0.13.0' } })
    const manifest = JSON.parse(await readFile(path.join(dir, 'vector-bundle.json'), 'utf8'))

    assert.deepEqual(manifest, written)
    assert.deepEqual(Object.keys(manifest), ['package', 'version', 'descriptorVersion', 'source', 'includes', 'files'])
    assert.equal(manifest.package, '@kinetis/vector-ai-ui')
    assert.deepEqual(manifest.includes, { '@kinetis/vector-ui': '0.13.0' })
    assert.deepEqual(Object.keys(manifest.files), await listFiles(dir))
})

test("a named version must be the named package's", () => {
    assert.throws(() => resolveVersion('0.2.0', '0.1.0', source, false, '@kinetis/vector-ai-ui'), /differs from @kinetis\/vector-ai-ui's version 0\.1\.0 in its package\.json/)
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
