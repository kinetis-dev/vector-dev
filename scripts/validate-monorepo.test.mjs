// node --test scripts/*.test.mjs — the monorepo checks on fixtures.
import assert from 'node:assert/strict'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { after, before, test } from 'node:test'
import { appReferences, releasedVersion, versionProblems } from './validate-monorepo.mjs'

const unreleased = '# Changelog\n\n## [Unreleased]\n\n### Added\n\n- Everything.\n'
const released = version => `# Changelog\n\n## [Unreleased]\n\n## [${version}] - 2026-11-01\n\n### Added\n\n- Everything.\n\n## [0.0.1] - 2026-01-01\n`

test('the newest released heading, skipping Unreleased', () => {
    assert.equal(releasedVersion(unreleased), null)
    assert.equal(releasedVersion(released('0.9.0')), '0.9.0')
    assert.equal(releasedVersion('## [unreleased]\n## [1.0.0-rc.1]\n'), '1.0.0-rc.1')
})

test('nothing released yet: nothing to compare', () => {
    assert.deepEqual(versionProblems('0.1.0', { 'a.md': unreleased, 'b.md': unreleased }), [])
})

test('both CHANGELOGs at the package.json version', () => {
    assert.deepEqual(versionProblems('0.9.0', { 'a.md': released('0.9.0'), 'b.md': released('0.9.0') }), [])
})

test('a version that disagrees fails, naming the file', () => {
    const problems = versionProblems('0.9.1', { 'a.md': released('0.9.0'), 'b.md': released('0.9.1') })

    assert.equal(problems.length, 1)
    assert.match(problems[0], /^a\.md's newest release is 0\.9\.0, but packages\/vector-ui\/package\.json's version is 0\.9\.1/)
})

test('a package.json version that is not SemVer fails', () => {
    assert.match(versionProblems('v0.9.0', {})[0], /is not SemVer/)
    assert.match(versionProblems(undefined, {})[0], /is not SemVer/)
})

let dir

before(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'vector-validate-'))
    await mkdir(path.join(dir, 'vector/src'), { recursive: true })
    await mkdir(path.join(dir, 'vector/resources/migrations'), { recursive: true })
    await mkdir(path.join(dir, 'vector-ui/node_modules/x'), { recursive: true })
    await writeFile(path.join(dir, 'vector/src/Clean.php'), '<?php\nnamespace Kinetis\\Vector;\nuse Kinetis\\App\\Thing;\nclass MyApp\\Clean {}\n')
    await writeFile(path.join(dir, 'vector/src/Leak.php'), '<?php\nnamespace Kinetis\\Vector;\n\nuse App\\Domain\\Product;\n')
    await writeFile(path.join(dir, 'vector/resources/migrations/x.mysql.stub'), "<?php\n$class = 'App\\\\Migration';\n")
    await writeFile(path.join(dir, 'vector/composer.json'), '{"autoload":{"psr-4":{"Kinetis\\\\Vector\\\\":"src/"}}}')
    // installed output is not the package's own
    await writeFile(path.join(dir, 'vector-ui/node_modules/x/a.php'), '<?php use App\\X;\n')
})

after(() => rm(dir, { recursive: true, force: true }))

/** appReferences over one file written to its own directory. */
async function referencesIn(name, contents) {
    const own = await mkdtemp(path.join(tmpdir(), 'vector-validate-one-'))

    try {
        await writeFile(path.join(own, name), contents)

        return await appReferences(own)
    } finally {
        await rm(own, { recursive: true, force: true })
    }
}

test('a fully qualified App\\ is a reference', async () => {
    assert.deepEqual(await referencesIn('Use.php', '<?php\nuse \\App\\X;\n'), ['Use.php:2'])
    assert.deepEqual(await referencesIn('Class.php', '<?php\n$class = \\App\\Foo::class;\n'), ['Class.php:2'])
    assert.deepEqual(await referencesIn('New.php', '<?php\n$product = new \\App\\Domain\\Product();\n'), ['New.php:2'])
    // a fully qualified name inside a double-quoted string, its backslashes escaped
    assert.deepEqual(await referencesIn('String.php', '<?php\n$class = "\\\\App\\\\Foo";\n'), ['String.php:2'])
})

test('an App\\ autoload root in composer.json is a reference', async () => {
    const composer = JSON.stringify({ autoload: { 'psr-4': { 'App\\': 'src/' } } }, null, 4)

    assert.deepEqual(await referencesIn('composer.json', composer), ['composer.json:4'])
})

test('App inside another name is not a reference', async () => {
    assert.deepEqual(await referencesIn('Other.php', '<?php\nuse Kinetis\\App\\Thing;\nuse \\Kinetis\\App\\Thing;\nclass MyApp\\Clean {}\n$s = "Kinetis\\\\App\\\\Thing";\n'), [])
})

test('App\\ references in PHP sources and stubs, not in built output', async () => {
    assert.deepEqual((await appReferences(dir)).sort(), ['vector/resources/migrations/x.mysql.stub:2', 'vector/src/Leak.php:4'])
})
