// node --test scripts/*.test.mjs — scripts/bump.mjs on a scratch tree.
import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { bump, releaseChangelog, setPackageVersion, setPinVersion } from './bump.mjs'
import { checkConsistency, checkHistory, parseManifest, treeReader } from './validate-versions.mjs'

const changelog = name => `# Changelog

All notable changes to \`${name}\` are documented in this file.

## [Unreleased]

### Fixed

- A fix.

## [0.9.0] - 2026-10-18

### Added

- Everything.
`

const packageJson = `{
    "name": "@kinetis/vector-ui",
    "version": "0.9.0",
    "description": "The UI.",
    "devDependencies": {
        "nuxt": "^4.5.2"
    }
}
`

const pinJson = '{"package":"@kinetis/vector-ui","version":"0.9.0"}\n'

const manifestJson = `${JSON.stringify({
    packages: {
        'vector': { name: 'kinetis/vector', path: 'packages/vector', version: '0.9.0' },
        'vector-ui': { name: '@kinetis/vector-ui', path: 'packages/vector-ui', version: '0.9.0' },
    },
}, null, 4)}\n`

function scratch() {
    const repo = mkdtempSync(path.join(tmpdir(), 'vector-bump-test-'))
    const files = {
        'packages.manifest.json': manifestJson,
        'packages/vector/composer.json': '{\n    "name": "kinetis/vector"\n}\n',
        'packages/vector/CHANGELOG.md': changelog('kinetis/vector'),
        'packages/vector/resources/ui.json': pinJson,
        'packages/vector-ui/package.json': packageJson,
        'packages/vector-ui/CHANGELOG.md': changelog('@kinetis/vector-ui'),
    }

    for (const [file, contents] of Object.entries(files)) {
        mkdirSync(path.dirname(path.join(repo, file)), { recursive: true })
        writeFileSync(path.join(repo, file), contents)
    }

    const read = file => readFileSync(path.join(repo, file), 'utf8')

    return { repo, read, done: () => rmSync(repo, { recursive: true, force: true }) }
}

test('bump vector-ui patch: the manifest, package.json, the CHANGELOG and kinetis/vector\'s pin, and the checks accept the result', () => {
    const { repo, read, done } = scratch()

    try {
        const old = parseManifest(read('packages.manifest.json'))
        assert.deepEqual(bump(repo, 'vector-ui', 'patch', { date: '2026-10-19' }), {
            from: '0.9.0',
            to: '0.9.1',
            files: ['packages.manifest.json', 'packages/vector-ui/package.json', 'packages/vector-ui/CHANGELOG.md', 'packages/vector/resources/ui.json'],
            alsoBump: [],
        })

        // The pin moves with vector-ui, its formatting kept.
        assert.equal(read('packages/vector/resources/ui.json'), '{"package":"@kinetis/vector-ui","version":"0.9.1"}\n')

        assert.equal(read('packages.manifest.json'), manifestJson.replace(/("path": "packages\/vector-ui",\n\s+"version": )"0\.9\.0"/, '$1"0.9.1"'))
        // Only the version moved; the rest of package.json is byte for byte as it was.
        assert.equal(read('packages/vector-ui/package.json'), packageJson.replace('"version": "0.9.0"', '"version": "0.9.1"'))
        assert.match(read('packages/vector-ui/CHANGELOG.md'), /\n## \[Unreleased\]\n\n## \[0\.9\.1\] - 2026-10-19\n\n### Fixed\n\n- A fix\.\n\n## \[0\.9\.0\] - 2026-10-18\n/)
        // vector untouched.
        assert.equal(read('packages/vector/CHANGELOG.md'), changelog('kinetis/vector'))

        // Round trip: what bump wrote is what the versions check accepts.
        const now = parseManifest(read('packages.manifest.json'))
        assert.deepEqual(checkConsistency(now, treeReader(repo)), [])
        assert.deepEqual(checkHistory(old, now, { 'vector-ui': ['packages/vector-ui/CHANGELOG.md', 'packages/vector-ui/package.json'] }), [])
    } finally {
        done()
    }
})

test('bump vector minor: no package.json to write, and composer.json gets no version', () => {
    const { repo, read, done } = scratch()

    try {
        assert.deepEqual(bump(repo, 'vector', 'minor', { date: '2026-10-19' }).files, ['packages.manifest.json', 'packages/vector/CHANGELOG.md'])
        assert.equal(parseManifest(read('packages.manifest.json')).packages.vector.version, '0.10.0')
        assert.equal(read('packages/vector/composer.json'), '{\n    "name": "kinetis/vector"\n}\n')
        assert.equal(read('packages/vector/resources/ui.json'), pinJson, 'bumping kinetis/vector leaves the pin alone')
        assert.match(read('packages/vector/CHANGELOG.md'), /## \[Unreleased\]\n\n## \[0\.10\.0\] - 2026-10-19\n/)
        assert.deepEqual(checkConsistency(parseManifest(read('packages.manifest.json')), treeReader(repo)), [])
    } finally {
        done()
    }
})

test('a refused bump writes nothing', () => {
    const { repo, read, done } = scratch()

    try {
        writeFileSync(path.join(repo, 'packages/vector-ui/CHANGELOG.md'), '# Changelog\n\n## [Unreleased]\n\n## [0.9.0] - 2026-10-18\n\n- x\n')
        assert.throws(() => bump(repo, 'vector-ui', 'patch'), /"## \[Unreleased\]" section is empty/)
        assert.equal(read('packages.manifest.json'), manifestJson)
        assert.equal(read('packages/vector-ui/package.json'), packageJson)

        assert.throws(() => bump(repo, 'vector-uix', 'patch'), /has no product "vector-uix"; expected one of vector, vector-ui/)
        assert.throws(() => bump(repo, 'vector', 'major'), /Unknown bump component "major"/)
        assert.throws(() => bump(repo, 'vector', 'patch', { date: '19/10/2026' }), /is not YYYY-MM-DD/)
        assert.equal(read('packages.manifest.json'), manifestJson)

        // No pin to move: refused before anything is written.
        rmSync(path.join(repo, 'packages/vector/resources/ui.json'))
        writeFileSync(path.join(repo, 'packages/vector-ui/CHANGELOG.md'), changelog('@kinetis/vector-ui'))
        assert.throws(() => bump(repo, 'vector-ui', 'patch'), /ENOENT/)
        assert.equal(read('packages.manifest.json'), manifestJson)
        assert.equal(read('packages/vector-ui/package.json'), packageJson)
        assert.equal(read('packages/vector-ui/CHANGELOG.md'), changelog('@kinetis/vector-ui'))
    } finally {
        done()
    }
})

test('the CHANGELOG and package.json edits on their own', () => {
    assert.throws(() => releaseChangelog('# Changelog\n\n## [0.9.0]\n\n- x\n', '0.9.1', '2026-10-19'), /has no "## \[Unreleased\]" section/)
    // An Unreleased section holding only link references is still empty.
    assert.throws(() => releaseChangelog('## [Unreleased]\n\n[0.9.0]: https://example.com\n', '0.9.1', '2026-10-19'), /is empty/)
    assert.equal(releaseChangelog('## [Unreleased]\n\n- x\n', '0.9.1', '2026-10-19'), '## [Unreleased]\n\n## [0.9.1] - 2026-10-19\n\n- x\n')
    assert.throws(() => setPackageVersion('{"name":"x"}', '0.9.1'), /has no "version"/)
    assert.throws(() => setPackageVersion('{\n    "engines": {\n        "version": "1"\n    },\n    "version": "0.9.0"\n}', '0.9.1'), /not its top-level version/)
    assert.equal(setPinVersion('{"package":"@kinetis/vector-ui","version":"0.9.0"}\n', '0.10.0'), '{"package":"@kinetis/vector-ui","version":"0.10.0"}\n')
    assert.throws(() => setPinVersion('{"package":"@kinetis/vector-ui"}', '0.9.1'), /has no "version" to move/)
})

test('bumping an included product names the product that includes it, and leaves that one alone', () => {
    const { repo, read, done } = scratch()

    try {
        const withIncludes = manifestJson.replace('"path": "packages/vector",', '"path": "packages/vector",\n            "includes": [\n                "packages/vector-ui"\n            ],')
        writeFileSync(path.join(repo, 'packages.manifest.json'), withIncludes)

        assert.deepEqual(bump(repo, 'vector-ui', 'patch', { date: '2026-10-19' }).alsoBump, ['vector'])
        const after = parseManifest(read('packages.manifest.json'))
        assert.equal(after.packages.vector.version, '0.9.0', 'kinetis/vector is not bumped for you')
        assert.deepEqual(after.packages.vector.includes, ['packages/vector-ui'], 'includes survives the rewrite')
        assert.equal(read('packages.manifest.json'), withIncludes.replace(/("path": "packages\/vector-ui",\n\s+"version": )"0\.9\.0"/, '$1"0.9.1"'))

        // Bumping the including product itself names nothing else.
        assert.deepEqual(bump(repo, 'vector', 'patch', { date: '2026-10-19' }).alsoBump, [])
    } finally {
        done()
    }
})
