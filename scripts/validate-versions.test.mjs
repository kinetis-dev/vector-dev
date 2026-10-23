// node --test scripts/*.test.mjs — the versions check, on its pure parts
// and on throwaway git repositories (skipped where git is not installed).
import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { checkConsistency, checkHistory, checkIncludes, checkPin, onlyTestToolingChanged, parseArguments, parseManifest, validate } from './validate-versions.mjs'

const manifest = (vector, ui) => ({
    packages: {
        'vector': { name: 'kinetis/vector', path: 'packages/vector', version: vector },
        'vector-ui': { name: '@kinetis/vector-ui', path: 'packages/vector-ui', version: ui },
    },
})

const changelog = version => `# Changelog\n\n## [Unreleased]\n\n## [${version}] - 2026-10-18\n\n### Added\n\n- Things.\n`

const pin = version => `${JSON.stringify({ package: '@kinetis/vector-ui', version })}\n`

/** The files a consistent tree at these versions holds. */
const tree = (vector, ui) => ({
    'packages.manifest.json': `${JSON.stringify(manifest(vector, ui), null, 4)}\n`,
    'packages/vector/composer.json': JSON.stringify({ name: 'kinetis/vector' }),
    'packages/vector/CHANGELOG.md': changelog(vector),
    'packages/vector/src/A.php': '<?php\n',
    'packages/vector/resources/ui.json': pin(ui),
    'packages/vector-ui/package.json': JSON.stringify({ name: '@kinetis/vector-ui', version: ui }),
    'packages/vector-ui/CHANGELOG.md': changelog(ui),
})

const reader = files => file => files[file] ?? null

test('the manifest has one shape: packages, each with name, path and version', () => {
    assert.deepEqual(parseManifest(JSON.stringify(manifest('0.9.0', '0.9.0'))), manifest('0.9.0', '0.9.0'))
    assert.throws(() => parseManifest('{'), /not valid JSON/)
    assert.throws(() => parseManifest('{"packages":{}}'), /lists no packages/)
    assert.throws(() => parseManifest('{"packages":{"vector":{}},"defaults":{}}'), /nothing else/)
    assert.throws(() => parseManifest('{"packages":{"vector":{"name":"kinetis/vector","path":"packages/vector"}}}'), /must have exactly "name", "path", "version"/)
    assert.throws(() => parseManifest('{"packages":{"vector":{"name":"kinetis/vector","path":"packages/vector","version":"0.9.0","bin":"x"}}}'), /must have exactly/)
    assert.throws(() => parseManifest('{"packages":{"vector":{"name":"kinetis/vector","path":"../vector","version":"0.9.0"}}}'), /expected packages\/<directory>/)
})

const withIncludes = (includes) => {
    const m = manifest('0.9.0', '0.9.0')
    m.packages.vector = { ...m.packages.vector, includes }

    return JSON.stringify(m)
}

test('includes is optional, and every other key is still rejected', () => {
    assert.deepEqual(parseManifest(withIncludes(['packages/vector-ui'])).packages.vector.includes, ['packages/vector-ui'])
    const unknown = manifest('0.9.0', '0.9.0')
    unknown.packages.vector.include = ['packages/vector-ui']
    assert.throws(() => parseManifest(JSON.stringify(unknown)), /"vector" must have exactly "name", "path", "version", each a non-empty string, and optionally "includes"/)
})

test('a malformed includes is rejected', () => {
    for (const [includes, message] of [
        ['packages/vector-ui', /not a non-empty array of paths/],
        [[], /not a non-empty array of paths/],
        [[''], /includes "", which is not a repo-relative directory/],
        [[7], /includes 7, which is not a repo-relative directory/],
        [['/packages/vector-ui'], /not a repo-relative directory/],
        [['packages/vector-ui/'], /not a repo-relative directory/],
        [['packages/../vector-ui'], /not a repo-relative directory/],
        [['./packages/vector-ui'], /not a repo-relative directory/],
        [['packages\\vector-ui'], /not a repo-relative directory/],
        [['packages/vector'], /includes "packages\/vector", which overlaps its own path "packages\/vector"/],
        [['packages/vector/resources'], /overlaps its own path/],
        [['packages'], /overlaps its own path/],
        [['packages/vector-ui', 'packages/vector-ui'], /includes "packages\/vector-ui" more than once/],
    ]) {
        assert.throws(() => parseManifest(withIncludes(includes)), message, JSON.stringify(includes))
    }
})

test('consistency: an included directory must exist', () => {
    const m = parseManifest(withIncludes(['packages/vector-ui', 'packages/gone']))
    assert.deepEqual(checkIncludes(m, dir => dir === 'packages/vector-ui'), ['vector: includes "packages/gone", which is not a directory in this tree'])
    assert.deepEqual(checkIncludes(manifest('0.9.0', '0.9.0'), () => false), [])
})

test('history: a change under an included path needs the including product bumped too, and says why', () => {
    const old = parseManifest(withIncludes(['packages/vector-ui']))
    const ui = ['packages/vector-ui/app/x.ts']
    const at = (vector, uiVersion) => ({ packages: { ...manifest(vector, uiVersion).packages, vector: { ...manifest(vector, uiVersion).packages.vector, includes: ['packages/vector-ui'] } } })

    // vector-ui changed and only vector-ui bumped: kinetis/vector fails, naming the included path.
    assert.deepEqual(checkHistory(old, at('0.9.0', '0.9.1'), { 'vector': ui, 'vector-ui': ui }), [
        'vector: files under packages/vector-ui (which kinetis/vector includes) changed but its version is still 0.9.0 (packages/vector-ui/app/x.ts); run node scripts/bump.mjs vector <patch|minor>',
    ])
    // Both bumped: passes, and vector's bump is no "unchanged product" bump.
    assert.deepEqual(checkHistory(old, at('0.9.1', '0.9.1'), { 'vector': ui, 'vector-ui': ui }), [])
    // vector-ui keeps its own obligation.
    assert.match(checkHistory(old, at('0.9.1', '0.9.0'), { 'vector': ui, 'vector-ui': ui }).join('\n'), /^vector-ui: files under packages\/vector-ui changed but its version is still 0\.9\.0/)
    // A vector-only change still needs only vector.
    assert.deepEqual(checkHistory(old, at('0.9.1', '0.9.0'), { vector: ['packages/vector/src/A.php'] }), [])
    // Both directories named when both changed.
    assert.match(checkHistory(old, at('0.9.0', '0.9.1'), { 'vector': ['packages/vector/src/A.php', ...ui], 'vector-ui': ui }).join('\n'),
        /^vector: files under packages\/vector and packages\/vector-ui \(which kinetis\/vector includes\) changed/)
    // An unchanged product's bump names every path it covers.
    assert.match(checkHistory(old, at('0.9.1', '0.9.0'), {}).join('\n'), /^vector: version moved from 0\.9\.0 to 0\.9\.1, but nothing under packages\/vector or packages\/vector-ui changed/)
})

test('consistency: the clean tree passes', () => {
    assert.deepEqual(checkConsistency(manifest('0.9.0', '0.9.1'), reader(tree('0.9.0', '0.9.1'))), [])
})

test('consistency: every manifest version must parse and stay on the 0.x line', () => {
    const files = tree('0.9.0', '0.9.0')
    assert.match(checkConsistency(manifest('0.9', '0.9.0'), reader(files)).join('\n'), /^vector: version '0\.9' is not a canonical X\.Y\.Z version$/m)
    assert.match(checkConsistency(manifest('0.9.0', '1.0.0'), reader(files)).join('\n'), /^vector-ui: version '1\.0\.0' leaves the 0\.x line/m)
})

test("consistency: vector-ui's package.json must carry the manifest's version and name", () => {
    const files = tree('0.9.0', '0.9.0')
    files['packages/vector-ui/package.json'] = JSON.stringify({ name: '@kinetis/vector-ui', version: '0.1.0' })
    assert.deepEqual(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)), [
        'vector-ui: packages/vector-ui/package.json is at 0.1.0, the manifest at 0.9.0; run scripts/bump.mjs rather than editing either by hand',
        // the pin follows package.json, so it disagrees as well
        'vector: packages/vector/resources/ui.json pins @kinetis/vector-ui 0.9.0, but packages/vector-ui/package.json is at 0.1.0; run scripts/bump.mjs vector-ui, which moves the pin with it',
    ])
    files['packages/vector-ui/package.json'] = JSON.stringify({ name: 'vector-ui', version: '0.9.0' })
    assert.match(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)).join('\n'), /is named "vector-ui", the manifest says "@kinetis\/vector-ui"/)
})

test("consistency: vector's composer.json carries no version, since Packagist reads the tag", () => {
    const files = tree('0.9.0', '0.9.0')
    files['packages/vector/composer.json'] = JSON.stringify({ name: 'kinetis/vector', version: '0.9.0' })
    assert.deepEqual(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)), [
        'vector: packages/vector/composer.json has a "version"; Packagist reads it from the mirror\'s tag, so it must not',
    ])
})

test('consistency: each CHANGELOG needs a section for its version', () => {
    const files = tree('0.9.0', '0.9.0')
    files['packages/vector/CHANGELOG.md'] = changelog('0.9.0').replace('## [0.9.0]', '## [0.9.00]')
    assert.deepEqual(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)), ['vector: packages/vector/CHANGELOG.md has no "## [0.9.0]" section'])
    delete files['packages/vector/CHANGELOG.md']
    assert.deepEqual(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)), ['vector: packages/vector/CHANGELOG.md is missing'])
    // 0.9.0 is not 0x9x0: the heading is matched literally
    files['packages/vector/CHANGELOG.md'] = '## [0x9x0]\n'
    assert.equal(checkConsistency(manifest('0.9.0', '0.9.0'), reader(files)).length, 1)
})

test("consistency: kinetis/vector's pin names exactly vector-ui's package.json version", () => {
    const files = tree('0.9.0', '0.9.1')
    const check = () => checkConsistency(manifest('0.9.0', '0.9.1'), reader(files))

    files['packages/vector/resources/ui.json'] = pin('0.9.0')
    assert.deepEqual(check(), ['vector: packages/vector/resources/ui.json pins @kinetis/vector-ui 0.9.0, but packages/vector-ui/package.json is at 0.9.1; run scripts/bump.mjs vector-ui, which moves the pin with it'])

    files['packages/vector/resources/ui.json'] = JSON.stringify({ package: '@kinetis/vector-uix', version: '0.9.1' })
    assert.deepEqual(check(), ['vector: packages/vector/resources/ui.json pins "@kinetis/vector-uix", not @kinetis/vector-ui'])

    for (const malformed of [JSON.stringify({ package: '@kinetis/vector-ui', version: '0.9.1', integrity: 'sha512-x' }), JSON.stringify({ version: '0.9.1' }), '["0.9.1"]', 'null']) {
        files['packages/vector/resources/ui.json'] = malformed
        assert.deepEqual(check(), ['vector: packages/vector/resources/ui.json must be exactly {"package": "@kinetis/vector-ui", "version": "<x.y.z>"}'], malformed)
    }

    files['packages/vector/resources/ui.json'] = '{'
    assert.deepEqual(check(), ['vector: packages/vector/resources/ui.json is not valid JSON'])

    delete files['packages/vector/resources/ui.json']
    assert.deepEqual(check(), ['vector: packages/vector/resources/ui.json is missing; it pins the @kinetis/vector-ui release kinetis/vector expects'])
})

test('the pin is checked only when the manifest lists both products', () => {
    const only = { packages: { vector: manifest('0.9.0', '0.9.0').packages.vector } }
    assert.deepEqual(checkPin(only, reader({})), [])
})

test('history: a changed product needs a bump, an unchanged one keeps its version', () => {
    const old = manifest('0.9.0', '0.9.0')
    assert.deepEqual(checkHistory(old, manifest('0.9.0', '0.9.0'), {}), [])
    assert.deepEqual(checkHistory(old, manifest('0.9.1', '0.9.0'), { vector: ['packages/vector/src/A.php'] }), [])

    assert.match(checkHistory(old, manifest('0.9.0', '0.9.0'), { vector: ['packages/vector/src/A.php', 'packages/vector/src/B.php', 'packages/vector/src/C.php', 'packages/vector/README.md'] }).join('\n'),
        /^vector: files under packages\/vector changed but its version is still 0\.9\.0 \(packages\/vector\/src\/A\.php, packages\/vector\/src\/B\.php, packages\/vector\/src\/C\.php, …\); run node scripts\/bump\.mjs vector <patch\|minor>$/)
    assert.match(checkHistory(old, manifest('0.9.0', '0.9.1'), {}).join('\n'), /^vector-ui: version moved from 0\.9\.0 to 0\.9\.1, but nothing under packages\/vector-ui changed/)
})

test('history: a version change is exactly one legal step', () => {
    const old = manifest('0.9.0', '0.9.0')
    const changed = { 'vector': ['x'], 'vector-ui': ['y'] }
    assert.deepEqual(checkHistory(old, manifest('0.10.0', '0.9.1'), changed), [])
    assert.deepEqual(checkHistory(old, manifest('0.9.2', '0.9.0'), { vector: ['x'] }), [
        'vector: version jumped from 0.9.0 to 0.9.2; the only steps allowed are 0.9.1 or 0.10.0, so every version in between stays reachable',
    ])
    assert.match(checkHistory(old, manifest('0.8.9', '1.0.0'), changed).join('\n'), /vector: version 0\.8\.9 is lower than 0\.9\.0\nvector-ui: version '1\.0\.0' leaves the 0\.x line/)
})

test('history: a product new to the manifest only needs a version the policy accepts', () => {
    const old = { packages: { vector: manifest('0.9.0', '0.9.0').packages.vector } }
    assert.deepEqual(checkHistory(old, manifest('0.9.0', '0.3.0'), { 'vector-ui': ['z'] }), [])
    assert.match(checkHistory(old, manifest('0.9.0', '1.0.0'), {}).join('\n'), /vector-ui: version '1\.0\.0' leaves the 0\.x line/)
})

/** A vector-ui package.json at 0.9.0, with `change` applied to its parsed form. */
const uiPackage = (change = () => {}) => {
    const pkg = {
        name: '@kinetis/vector-ui',
        version: '0.9.0',
        scripts: { test: 'vitest run' },
        dependencies: { pinia: '^4.0.3' },
        peerDependencies: { nuxt: '^4.5.0' },
        devDependencies: { 'happy-dom': '^20.14.5', 'nuxt': '^4.5.2', 'vitest': '^4.0.2' },
    }
    change(pkg)

    return `${JSON.stringify(pkg, null, 4)}\n`
}

test('a package.json changed only in test-tooling devDependencies needs no bump', () => {
    const base = uiPackage()
    assert.equal(onlyTestToolingChanged(base, uiPackage(p => { p.devDependencies['happy-dom'] = '^21.0.0' })), true)
    assert.equal(onlyTestToolingChanged(base, uiPackage(p => { p.devDependencies['@types/node'] = '^22.0.0' })), true, 'added')
    assert.equal(onlyTestToolingChanged(base, uiPackage(p => { delete p.devDependencies.vitest })), true, 'removed')
    assert.equal(onlyTestToolingChanged(base, base.replace(/ {4}/g, '  ')), true, 'whitespace only')
})

test('any other package.json change still needs a bump', () => {
    const base = uiPackage()
    for (const [label, change] of [
        ['an unlisted devDependency alongside a listed one', p => { p.devDependencies['happy-dom'] = '^21.0.0'; p.devDependencies.nuxt = '^4.6.0' }],
        ['a listed package moved into dependencies', p => { delete p.devDependencies['happy-dom']; p.dependencies['happy-dom'] = '^20.14.5' }],
        ['a listed package added to peerDependencies', p => { p.peerDependencies.vitest = '^4.0.2' }],
        ['scripts changed alongside a listed change', p => { p.devDependencies['happy-dom'] = '^21.0.0'; p.scripts.test = 'vitest' }],
        ['devDependencies turned into something else', p => { p.devDependencies = null }],
        ['a reordered field', p => { const { scripts, ...rest } = p; Object.keys(p).forEach(k => delete p[k]); Object.assign(p, rest, { scripts }) }],
    ]) {
        assert.equal(onlyTestToolingChanged(base, uiPackage(change)), false, label)
    }

    assert.equal(onlyTestToolingChanged(null, base), false, 'added file')
    assert.equal(onlyTestToolingChanged(base, null), false, 'deleted file')
    assert.equal(onlyTestToolingChanged(base, '{'), false, 'not JSON')
    assert.equal(onlyTestToolingChanged('[]', '[]'), false, 'not an object')
})

test('history: a product changed only in exempt files may keep its version or bump it', () => {
    const old = manifest('0.9.0', '0.9.0')
    const pkg = ['packages/vector-ui/package.json']

    assert.deepEqual(checkHistory(old, manifest('0.9.0', '0.9.0'), { 'vector-ui': [] }, { 'vector-ui': pkg }), [])
    // A bump alongside is a human's choice, not an "unchanged product" bump.
    assert.deepEqual(checkHistory(old, manifest('0.9.0', '0.9.1'), { 'vector-ui': [] }, { 'vector-ui': pkg }), [])
    // Nothing at all changed: that refusal still fires.
    assert.match(checkHistory(old, manifest('0.9.0', '0.9.1'), {}, {}).join('\n'), /^vector-ui: version moved from 0\.9\.0 to 0\.9\.1, but nothing under packages\/vector-ui changed/)
    // Another file beside an exempt package.json still needs the bump.
    assert.deepEqual(checkHistory(old, manifest('0.9.0', '0.9.0'), { 'vector-ui': ['packages/vector-ui/app/x.ts'] }, { 'vector-ui': pkg }), [
        'vector-ui: files under packages/vector-ui changed but its version is still 0.9.0 (packages/vector-ui/app/x.ts); run node scripts/bump.mjs vector-ui <patch|minor>',
    ])
})

test('arguments: --base only, and never empty', () => {
    assert.deepEqual(parseArguments([]), { base: undefined })
    assert.deepEqual(parseArguments(['--base=abc']), { base: 'abc' })
    assert.throws(() => parseArguments(['--base=']), /needs a commit id/)
    assert.throws(() => parseArguments(['--base=a', '--base=b']), /more than once/)
    assert.throws(() => parseArguments(['--bse=a']), /Unknown argument/)
})

const hasGit = spawnSync('git', ['--version']).status === 0
const run = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8' }).trim()

function scratch() {
    const repo = mkdtempSync(path.join(tmpdir(), 'vector-versions-test-'))
    run(repo, 'init', '--quiet', '--initial-branch=main')
    run(repo, 'config', 'user.name', 'Test')
    run(repo, 'config', 'user.email', 'test@example.com')

    const write = (files) => {
        for (const [file, contents] of Object.entries(files)) {
            mkdirSync(path.dirname(path.join(repo, file)), { recursive: true })
            writeFileSync(path.join(repo, file), contents)
        }
    }
    const commit = (files, message) => {
        write(files)
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', message)

        return run(repo, 'rev-parse', 'HEAD')
    }

    return { repo, write, commit, done: () => rmSync(repo, { recursive: true, force: true }) }
}

test('a run with no base skips the history check and says so', { skip: !hasGit && 'git is not installed' }, () => {
    const { repo, commit, done } = scratch()

    try {
        commit(tree('0.9.0', '0.9.0'), 'First')
        const first = validate(repo, {})
        assert.deepEqual(first.problems, [])
        assert.match(first.lines.join('\n'), /\[consistency\] OK\.\n\[history\] Skipped: there is no earlier commit/)

        // The all-zero `before` of a branch's first push.
        commit({ 'packages/vector/src/A.php': '<?php // changed\n' }, 'Unbumped')
        assert.match(validate(repo, { before: '0'.repeat(40) }).lines.join('\n'), /\[history\] Skipped: there is no earlier commit/)
        // A named base that cannot be read fails rather than skipping.
        assert.throws(() => validate(repo, { base: 'f'.repeat(40) }), /is not a commit this checkout can read/)
    } finally {
        done()
    }
})

test('a base without a manifest skips the history check: that change introduces the versions', { skip: !hasGit && 'git is not installed' }, () => {
    const { repo, commit, done } = scratch()

    try {
        const files = tree('0.9.0', '0.9.0')
        const { 'packages.manifest.json': manifestFile, ...rest } = files
        commit({ ...rest, 'packages/vector-ui/package.json': JSON.stringify({ name: '@kinetis/vector-ui', version: '0.1.0' }) }, 'Before versions')
        commit({ 'packages.manifest.json': manifestFile, 'packages/vector-ui/package.json': files['packages/vector-ui/package.json'] }, 'Versions')
        const result = validate(repo, {})
        assert.deepEqual(result.problems, [])
        assert.match(result.lines.join('\n'), /\[history\] Skipped: the base [0-9a-f]{40} carries no packages\.manifest\.json/)
    } finally {
        done()
    }
})

test('history on a real history: base to head end to end, the working tree included', { skip: !hasGit && 'git is not installed' }, () => {
    const { repo, write, commit, done } = scratch()

    try {
        const base = commit(tree('0.9.0', '0.9.0'), 'First')

        // A clean change: vector changed and bumped one step.
        commit({ ...tree('0.9.1', '0.9.0'), 'packages/vector/src/B.php': '<?php\n' }, 'Vector 0.9.1')
        assert.deepEqual(validate(repo, { base }).problems, [])
        assert.match(validate(repo, { base }).lines.join('\n'), /\[history\] Comparing against [0-9a-f]{40}\.\n\[history\] OK\./)

        // Another legal step on top passes commit by commit, but measured
        // from the base the move is 0.9.0 -> 0.9.2: no laundering.
        const middle = run(repo, 'rev-parse', 'HEAD')
        commit({ ...tree('0.9.2', '0.9.0'), 'packages/vector/src/C.php': '<?php\n' }, 'Vector 0.9.2')
        assert.deepEqual(validate(repo, { base: middle }).problems, [])
        assert.deepEqual(validate(repo, { base }).problems, ['[history] vector: version jumped from 0.9.0 to 0.9.2; the only steps allowed are 0.9.1 or 0.10.0, so every version in between stays reachable'])

        // GITHUB_EVENT_BEFORE is the base when no --base is given.
        assert.equal(validate(repo, { before: base }).problems.length, 1)

        // An uncommitted change to vector-ui, unbumped, is seen too.
        write({ 'packages/vector-ui/app/new.ts': 'export {}\n' })
        assert.deepEqual(validate(repo, { base: middle }).problems, [
            '[history] vector-ui: files under packages/vector-ui changed but its version is still 0.9.0 (packages/vector-ui/app/new.ts); run node scripts/bump.mjs vector-ui <patch|minor>',
        ])
        rmSync(path.join(repo, 'packages/vector-ui/app'), { recursive: true })

        // A bump with nothing changed under the product's path.
        commit({ 'packages.manifest.json': tree('0.9.2', '0.9.1')['packages.manifest.json'] }, 'Bump only the manifest')
        const result = validate(repo, { base: run(repo, 'rev-parse', 'HEAD^') })
        assert.match(result.problems.join('\n'), /\[history\] vector-ui: version moved from 0\.9\.0 to 0\.9\.1, but nothing under packages\/vector-ui changed/)
        // ...which is also an inconsistent tree: package.json and the CHANGELOG still say 0.9.0.
        assert.match(result.problems.join('\n'), /\[consistency\] vector-ui: packages\/vector-ui\/package\.json is at 0\.9\.0, the manifest at 0\.9\.1/)
        assert.match(result.problems.join('\n'), /\[consistency\] vector-ui: packages\/vector-ui\/CHANGELOG\.md has no "## \[0\.9\.1\]" section/)
    } finally {
        done()
    }
})

test('history on a real history: a vector-ui change needs kinetis/vector bumped too, through includes', { skip: !hasGit && 'git is not installed' }, () => {
    const { repo, write, commit, done } = scratch()
    const including = (vector, ui) => `${JSON.stringify({ packages: { ...manifest(vector, ui).packages, vector: { ...manifest(vector, ui).packages.vector, includes: ['packages/vector-ui'] } } }, null, 4)}\n`

    try {
        const base = commit({ ...tree('0.9.0', '0.9.0'), 'packages.manifest.json': including('0.9.0', '0.9.0'), 'packages/vector-ui/app/x.ts': 'export {}\n' }, 'First')

        // vector-ui changed, only vector-ui bumped: its pin in packages/vector moved too.
        write({ ...tree('0.9.0', '0.9.1'), 'packages.manifest.json': including('0.9.0', '0.9.1'), 'packages/vector-ui/app/x.ts': 'export const x = 1\n' })
        assert.deepEqual(validate(repo, { base }).problems, [
            '[history] vector: files under packages/vector and packages/vector-ui (which kinetis/vector includes) changed but its version is still 0.9.0 (packages/vector-ui/CHANGELOG.md, packages/vector-ui/app/x.ts, packages/vector-ui/package.json, …); run node scripts/bump.mjs vector <patch|minor>',
        ])

        // Both bumped.
        write({ ...tree('0.9.1', '0.9.1'), 'packages.manifest.json': including('0.9.1', '0.9.1') })
        assert.deepEqual(validate(repo, { base }).problems, [])

        // An included directory that does not exist is a consistency problem.
        write({ 'packages.manifest.json': including('0.9.1', '0.9.1').replace('"packages/vector-ui"\n', '"packages/vector-ui",\n                "packages/gone"\n') })
        assert.deepEqual(validate(repo, { base }).problems, ['[consistency] vector: includes "packages/gone", which is not a directory in this tree'])
    } finally {
        done()
    }
})

test('history on a real history: a test-tooling devDependency bump needs no release, anything beside it does', { skip: !hasGit && 'git is not installed' }, () => {
    const { repo, write, commit, done } = scratch()
    const including = (vector, ui) => `${JSON.stringify({ packages: { ...manifest(vector, ui).packages, vector: { ...manifest(vector, ui).packages.vector, includes: ['packages/vector-ui'] } } }, null, 4)}\n`
    const files = (vector, ui, change) => ({
        ...tree(vector, ui),
        'packages.manifest.json': including(vector, ui),
        'packages/vector-ui/package.json': uiPackage(p => { p.version = ui; change?.(p) }),
    })
    const tooling = p => { p.devDependencies['happy-dom'] = '^21.0.0' }

    try {
        const base = commit({ ...files('0.9.0', '0.9.0'), 'packages/vector-ui/app/x.ts': 'export {}\n' }, 'First')

        // Only happy-dom moved: neither product needs a bump.
        write(files('0.9.0', '0.9.0', tooling))
        assert.deepEqual(validate(repo, { base }).problems, [])

        // ...and bumping both anyway is accepted.
        write(files('0.9.1', '0.9.1', tooling))
        assert.deepEqual(validate(repo, { base }).problems, [])

        // nuxt moved too: both products need a bump, and the refusal names the file.
        write(files('0.9.0', '0.9.0', p => { tooling(p); p.devDependencies.nuxt = '^4.6.0' }))
        assert.deepEqual(validate(repo, { base }).problems, [
            '[history] vector: files under packages/vector-ui (which kinetis/vector includes) changed but its version is still 0.9.0 (packages/vector-ui/package.json); run node scripts/bump.mjs vector <patch|minor>',
            '[history] vector-ui: files under packages/vector-ui changed but its version is still 0.9.0 (packages/vector-ui/package.json); run node scripts/bump.mjs vector-ui <patch|minor>',
        ])

        // Another file under packages/vector-ui beside the exempt change: both need a bump.
        write({ ...files('0.9.0', '0.9.0', tooling), 'packages/vector-ui/app/x.ts': 'export const x = 1\n' })
        assert.match(validate(repo, { base }).problems.join('\n'), /vector: files under packages\/vector-ui \(which kinetis\/vector includes\) changed but its version is still 0\.9\.0 \(packages\/vector-ui\/app\/x\.ts\)/)
    } finally {
        done()
    }
})
