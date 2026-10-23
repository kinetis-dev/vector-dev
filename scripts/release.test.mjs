// node --test scripts/*.test.mjs — the release rules, and the mirror push
// on throwaway git repositories (skipped where git is not installed, as in
// the demo's node container).
import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import {
    changelogSection, npmPublished, npmPublishedVersions, plan, productPlan, publicationPlan, publishMirror, split,
} from './release.mjs'

const changelog = `# Changelog

## [Unreleased]

- Not yet.

## [0.9.0] - 2026-10-20

### Added

- Everything.

## [0.1.0]

- Older.

[0.9.0]: https://github.com/kinetis-dev/vector-dev/releases/tag/v0.9.0
`

test("a CHANGELOG section is the version's own, up to the next heading", () => {
    assert.equal(changelogSection(changelog, '0.9.0'), '### Added\n\n- Everything.')
    assert.equal(changelogSection(changelog, '0.1.0'), '- Older.')
})

test('a missing or empty CHANGELOG section fails', () => {
    assert.throws(() => changelogSection(changelog, '0.9.1', 'packages/vector/CHANGELOG.md'), /packages\/vector\/CHANGELOG\.md has no "## \[0\.9\.1\]" section/)
    // 0.9.0 is not 0x9x0
    assert.throws(() => changelogSection('## [0x9x0]\n\n- x\n', '0.9.0'), /no "## \[0\.9\.0\]" section/)
    assert.throws(() => changelogSection('## [0.9.0]\n\n## [0.1.0]\n- x\n', '0.9.0'), /section is empty/)
})

test('plan: a published version is done; an unpublished one goes out after its predecessor', () => {
    const product = 'kinetis/vector'
    assert.deepEqual(productPlan({ product, version: '0.9.0', published: true, publishedVersions: ['0.9.0'] }), { publish: false, notice: 'kinetis/vector 0.9.0 is already published; nothing to do.' })
    assert.equal(productPlan({ product, version: '0.9.1', published: false, publishedVersions: ['0.9.0'] }).publish, true)
    assert.equal(productPlan({ product, version: '0.10.0', published: false, publishedVersions: ['0.9.0', '0.9.3'] }).publish, true)
})

test('plan: the first release of a product needs no predecessor', () => {
    assert.deepEqual(productPlan({ product: '@kinetis/vector-ui', version: '0.9.0', published: false, publishedVersions: [] }), { publish: true, notice: '@kinetis/vector-ui 0.9.0 is its first release.' })
})

test('plan: a version whose predecessor is not published is refused', () => {
    assert.throws(() => productPlan({ product: 'kinetis/vector', version: '0.9.2', published: false, publishedVersions: ['0.9.0'] }),
        /kinetis\/vector 0\.9\.2 does not follow what is published \(highest 0\.9\.0\): version jumped from 0\.9\.0 to 0\.9\.2/)
    assert.throws(() => productPlan({ product: 'kinetis/vector', version: '0.9.1', published: false, publishedVersions: ['0.9.0', '0.9.2'] }), /lower than 0\.9\.2/)
})

test('npm: published, not yet, or no answer', () => {
    assert.equal(npmPublished('0.9.0', () => ({ status: 0, stdout: '0.9.0\n' })), true)
    assert.equal(npmPublished('0.9.0', () => ({ status: 0, stdout: '' })), false)
    assert.equal(npmPublished('0.9.0', () => ({ status: 1, stdout: '', stderr: 'npm error code E404\n' })), false)
    assert.throws(() => npmPublished('0.9.0', () => ({ status: 1, stdout: '', stderr: 'npm error code ETIMEDOUT\n' })), /Could not ask npm/)
})

test("npm: every published version, none before the first publish", () => {
    assert.deepEqual(npmPublishedVersions(() => ({ status: 0, stdout: '["0.9.0","0.9.1"]\n' })), ['0.9.0', '0.9.1'])
    // one version: npm prints a bare string
    assert.deepEqual(npmPublishedVersions(() => ({ status: 0, stdout: '"0.9.0"\n' })), ['0.9.0'])
    assert.deepEqual(npmPublishedVersions(() => ({ status: 1, stdout: '', stderr: 'npm error code E404\n' })), [])
    assert.throws(() => npmPublishedVersions(() => ({ status: 1, stdout: '', stderr: 'npm error code EAI_AGAIN\n' })), /Could not ask npm which versions/)
})

const S = 'a'.repeat(40)
const OLD = 'c'.repeat(40)
const base = { split: S, splitDescends: false, mainDescends: false }

test('mirror plan: a first release writes main and the tag', () => {
    assert.deepEqual(publicationPlan({ ...base, remote: { main: null, tag: null } }), { main: true, tag: true, notices: [] })
})

test('mirror plan: main only fast-forwards', () => {
    assert.equal(publicationPlan({ ...base, remote: { main: OLD, tag: null }, splitDescends: true }).main, true)
    assert.equal(publicationPlan({ ...base, remote: { main: S, tag: null } }).main, false)
    assert.equal(publicationPlan({ ...base, remote: { main: OLD, tag: null }, mainDescends: true }).main, false)
    assert.throws(() => publicationPlan({ ...base, remote: { main: OLD, tag: null } }), /not an ancestor/)
})

test('mirror plan: the tag is S; a tag at S is done, a tag anywhere else is refused', () => {
    const done = publicationPlan({ ...base, remote: { main: S, tag: S } })
    assert.equal(done.tag, false)
    assert.match(done.notices.join('\n'), /already this release's split/)
    assert.throws(() => publicationPlan({ ...base, remote: { main: S, tag: OLD } }), /points at c{40}, not this release's split a{40}/)
})

const hasGit = spawnSync('git', ['--version']).status === 0
const run = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8' }).trim()

const pin = version => `${JSON.stringify({ package: '@kinetis/vector-ui', version })}\n`

const manifest = (vector, ui = '0.9.0') => `${JSON.stringify({
    packages: {
        'vector': { name: 'kinetis/vector', path: 'packages/vector', version: vector },
        'vector-ui': { name: '@kinetis/vector-ui', path: 'packages/vector-ui', version: ui },
    },
}, null, 4)}\n`

test('the mirror push, end to end on scratch repositories', { skip: !hasGit && 'git is not installed' }, () => {
    const scratch = mkdtempSync(path.join(tmpdir(), 'vector-release-test-'))

    try {
        const repo = path.join(scratch, 'vector-dev')
        const mirror = path.join(scratch, 'mirror.git')
        mkdirSync(path.join(repo, 'packages/vector/src'), { recursive: true })
        mkdirSync(path.join(repo, 'packages/vector/resources'), { recursive: true })
        run(scratch, 'init', '--quiet', '--initial-branch=main', repo)
        run(scratch, 'init', '--quiet', '--bare', mirror)
        run(repo, 'config', 'user.name', 'Test')
        run(repo, 'config', 'user.email', 'test@example.com')
        writeFileSync(path.join(repo, 'packages/vector/src/A.php'), '<?php\n')
        writeFileSync(path.join(repo, 'packages/vector/resources/ui.json'), pin('0.9.0'))
        writeFileSync(path.join(repo, 'packages.manifest.json'), manifest('0.9.0'))
        writeFileSync(path.join(repo, 'README.md'), 'monorepo\n')
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'First')
        // origin, for the source re-check: the repository itself
        run(repo, 'remote', 'add', 'origin', repo)

        const source = run(repo, 'rev-parse', 'HEAD')
        const s = split(repo, source)
        assert.equal(split(repo, source), s, 'the split is deterministic')
        assert.match(run(repo, 'ls-tree', '-r', '--name-only', s), /^resources\/ui\.json$/m, 'the pin ships in the split')

        const log = () => {}

        // A rehearsal says what it would push, and writes nothing.
        const rehearsed = []
        publishMirror(repo, { version: '0.9.0', source, split: s, mirror, dryRun: true, log: line => rehearsed.push(line) })
        assert.match(rehearsed.join('\n'), /Dry run: would run git push --atomic/)
        assert.equal(run(mirror, 'for-each-ref'), '', 'a rehearsal writes nothing')

        // A real run: main and the tag both at S, without asking npm for the pinned UI.
        assert.deepEqual(publishMirror(repo, { version: '0.9.0', source, split: s, mirror, log }), { main: true, tag: true, notices: [] })
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s)
        assert.equal(run(mirror, 'rev-parse', 'refs/tags/v0.9.0'), s)
        assert.deepEqual(publishMirror(repo, { version: '0.9.0', source, split: s, mirror, log }), { main: false, tag: false, notices: [
            `The mirror's main is already ${s}.`,
            `The mirror's tag is already this release's split ${s}.`,
        ] }, 'a rerun is a no-op')
        assert.deepEqual(run(repo, 'tag', '--list'), '', 'the monorepo gets no tag')

        // Any other commit under the tag is refused.
        writeFileSync(path.join(repo, 'packages/vector/src/A.php'), '<?php // changed\n')
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'Changed source')
        run(repo, 'push', '--quiet', '--force', mirror, `${split(repo, run(repo, 'rev-parse', 'HEAD'))}:refs/tags/v0.9.0`)
        assert.throws(() => publishMirror(repo, { version: '0.9.0', source, split: s, mirror, log }), /not this release's split/)
        run(repo, 'push', '--quiet', '--force', mirror, `${s}:refs/tags/v0.9.0`)
        run(repo, 'reset', '--quiet', '--hard', 'HEAD^')

        // The version must be the one the manifest at the source states.
        assert.throws(() => publishMirror(repo, { version: '0.9.1', source, split: s, mirror, log }), /the manifest at [0-9a-f]{40} states kinetis\/vector 0\.9\.0, not 0\.9\.1; refusing to write the mirror/)

        // A source origin's main no longer contains (main force-pushed past it) stops a write.
        writeFileSync(path.join(repo, 'packages/vector/src/B.php'), '<?php\n')
        writeFileSync(path.join(repo, 'packages/vector/resources/ui.json'), pin('0.9.1'))
        writeFileSync(path.join(repo, 'packages.manifest.json'), manifest('0.9.1', '0.9.1'))
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'Second')
        const later = run(repo, 'rev-parse', 'HEAD')
        const s2 = split(repo, later)
        run(repo, 'reset', '--quiet', '--hard', source)
        assert.throws(() => publishMirror(repo, { version: '0.9.1', source: later, split: s2, mirror, log }), /origin's main \([0-9a-f]{40}\) no longer contains [0-9a-f]{40}; refusing to write the mirror/)
        // A rehearsal says so instead, and writes nothing.
        const said = []
        publishMirror(repo, { version: '0.9.1', source: later, split: s2, mirror, dryRun: true, log: line => said.push(line) })
        assert.match(said.join('\n'), /no longer contains[\s\S]*a real run would refuse[\s\S]*Dry run: would run git push --atomic --force-with-lease=refs\/heads\/main:/)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s)
        run(repo, 'reset', '--quiet', '--hard', later)

        // A later release fast-forwards main.
        publishMirror(repo, { version: '0.9.1', source: later, split: s2, mirror, log })
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s2)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main^'), s)
        assert.equal(run(mirror, 'rev-parse', 'refs/tags/v0.9.1'), s2)

        // Rerunning the older release, with main moved on to a newer version, still
        // passes the source guard and leaves main where it is.
        assert.equal(publishMirror(repo, { version: '0.9.0', source, split: s, mirror, log }).main, false)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s2)
    } finally {
        rmSync(scratch, { recursive: true, force: true })
    }
})

const npmAt = versions => ({
    npmView: () => ({ status: 0, stdout: '' }),
    npmVersionsView: () => (versions.length === 0 ? { status: 1, stdout: '', stderr: 'npm error code E404\n' } : { status: 0, stdout: JSON.stringify(versions) }),
})

test('plan reads the source commit, the mirror and npm, and a real release only comes from main', { skip: !hasGit && 'git is not installed' }, () => {
    const scratch = mkdtempSync(path.join(tmpdir(), 'vector-release-plan-'))

    try {
        const repo = path.join(scratch, 'vector-dev')
        const mirror = path.join(scratch, 'mirror.git')
        run(scratch, 'init', '--quiet', '--initial-branch=main', repo)
        run(scratch, 'init', '--quiet', '--bare', mirror)
        run(repo, 'config', 'user.name', 'Test')
        run(repo, 'config', 'user.email', 'test@example.com')

        const commit = (files, message) => {
            for (const [file, contents] of Object.entries(files)) {
                mkdirSync(path.dirname(path.join(repo, file)), { recursive: true })
                writeFileSync(path.join(repo, file), contents)
            }

            run(repo, 'add', '.')
            run(repo, 'commit', '--quiet', '-m', message)

            return run(repo, 'rev-parse', 'HEAD')
        }
        const at = (vector, ui) => ({
            'packages.manifest.json': manifest(vector, ui),
            'packages/vector/composer.json': JSON.stringify({ name: 'kinetis/vector' }),
            'packages/vector/CHANGELOG.md': changelog.replace('[0.9.0]', `[${vector}]`),
            'packages/vector/resources/ui.json': pin(ui),
            'packages/vector-ui/package.json': JSON.stringify({ name: '@kinetis/vector-ui', version: ui }),
            'packages/vector-ui/CHANGELOG.md': changelog.replace('[0.9.0]', `[${ui}]`),
        })
        const sync = () => run(repo, 'fetch', '--quiet', 'origin')
        const log = () => {}

        const first = commit(at('0.9.0', '0.9.0'), 'Versions')
        run(repo, 'remote', 'add', 'origin', repo)
        sync()

        // The first release of both: nothing on the mirror, npm answers E404.
        const result = plan(repo, { source: first, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt([]) })
        assert.deepEqual(result, {
            source: first,
            vector: { version: '0.9.0', publish: true, notes: '### Added\n\n- Everything.' },
            ui: { version: '0.9.0', publish: true, notes: '### Added\n\n- Everything.' },
        })

        // Both out: a no-op round.
        run(repo, 'push', '--quiet', mirror, `${first}:refs/tags/v0.9.0`)
        const published = { npmView: () => ({ status: 0, stdout: '0.9.0\n' }), npmVersionsView: () => ({ status: 0, stdout: '"0.9.0"' }) }
        const done = plan(repo, { source: first, ref: 'refs/heads/main', dryRun: false, mirror, log, ...published })
        assert.equal(done.vector.publish, false)
        assert.equal(done.ui.publish, false)
        assert.equal(done.vector.notes, null)

        // vector-ui bumped alone: only it goes out.
        const second = commit(at('0.9.0', '0.9.1'), 'vector-ui 0.9.1')
        sync()
        const uiOnly = plan(repo, { source: second, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.0']) })
        assert.equal(uiOnly.vector.publish, false)
        assert.deepEqual(uiOnly.ui, { version: '0.9.1', publish: true, notes: '### Added\n\n- Everything.' })

        // vector at 0.9.2 while the mirror has only 0.9.0: its predecessor is missing.
        const skipped = commit(at('0.9.2', '0.9.1'), 'vector 0.9.2, 0.9.1 never published')
        sync()
        assert.throws(() => plan(repo, { source: skipped, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.0']) }), /kinetis\/vector 0\.9\.2 does not follow what is published \(highest 0\.9\.0\)/)
        // A refusal stops the whole round: vector-ui 0.9.1 alone would go out,
        // but the plan throws rather than publish it without vector.
        assert.equal(productPlan({ product: '@kinetis/vector-ui', version: '0.9.1', published: false, publishedVersions: ['0.9.0'] }).publish, true)
        let askedNpm = false
        const npm = { ...npmAt(['0.9.0']), npmVersionsView: () => { askedNpm = true; return { status: 0, stdout: '["0.9.0"]' } } }
        assert.throws(() => plan(repo, { source: skipped, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npm }), /kinetis\/vector 0\.9\.2 does not follow/)
        assert.equal(askedNpm, false, 'the vector refusal comes before vector-ui is even considered')
        // ...and a rehearsal does not soften it.
        assert.throws(() => plan(repo, { source: skipped, ref: 'refs/heads/main', dryRun: true, mirror, log, ...npmAt(['0.9.0']) }), /kinetis\/vector 0\.9\.2 does not follow/)
        // ...and the same for npm.
        run(repo, 'push', '--quiet', mirror, `${skipped}:refs/tags/v0.9.1`)
        assert.throws(() => plan(repo, { source: skipped, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.8.0']) }), /@kinetis\/vector-ui 0\.9\.1 does not follow what is published \(highest 0\.8\.0\)/)

        // A real run publishes from main only; a rehearsal runs anywhere.
        assert.throws(() => plan(repo, { source: second, ref: 'refs/heads/feature', dryRun: false, mirror, log, ...npmAt([]) }), /runs from main \(refs\/heads\/main\), not refs\/heads\/feature/)
        run(repo, 'checkout', '--quiet', '-b', 'feature', second)
        const side = commit(at('0.9.0', '0.9.2'), 'Not on main')
        run(repo, 'checkout', '--quiet', 'main')
        sync()
        assert.throws(() => plan(repo, { source: side, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.1']) }), /is not on origin\/main; a release only comes from main/)
        const said = []
        assert.equal(plan(repo, { source: side, ref: 'refs/heads/feature', dryRun: true, mirror, log: line => said.push(line), ...npmAt(['0.9.1']) }).ui.publish, true)
        assert.match(said.join('\n'), /is not on origin\/main; rehearsing it anyway/)

        // An inconsistent source, an empty section and no manifest are refused before anything is read.
        const mismatch = commit({ ...at('0.9.0', '0.9.1'), 'packages/vector-ui/package.json': JSON.stringify({ name: '@kinetis/vector-ui', version: '0.9.0' }) }, 'Mismatch')
        sync()
        assert.throws(() => plan(repo, { source: mismatch, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.0']) }), /inconsistent:\nvector-ui: packages\/vector-ui\/package\.json is at 0\.9\.0, the manifest at 0\.9\.1/)
        const unpinned = commit({ ...at('0.9.0', '0.9.1'), 'packages/vector/resources/ui.json': pin('0.9.0') }, 'Pin left behind')
        sync()
        assert.throws(() => plan(repo, { source: unpinned, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.0']) }), /inconsistent:\nvector: packages\/vector\/resources\/ui\.json pins @kinetis\/vector-ui 0\.9\.0, but packages\/vector-ui\/package\.json is at 0\.9\.1/)
        const empty = commit({ ...at('0.9.0', '0.9.1'), 'packages/vector-ui/CHANGELOG.md': '## [Unreleased]\n\n## [0.9.1] - 2026-10-19\n\n## [0.9.0]\n\n- x\n' }, 'Empty notes')
        sync()
        assert.throws(() => plan(repo, { source: empty, ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt(['0.9.0']) }), /packages\/vector-ui\/CHANGELOG\.md's "## \[0\.9\.1\]" section is empty/)
        run(repo, 'rm', '--quiet', 'packages.manifest.json')
        run(repo, 'commit', '--quiet', '-m', 'No manifest')
        sync()
        assert.throws(() => plan(repo, { source: run(repo, 'rev-parse', 'HEAD'), ref: 'refs/heads/main', dryRun: false, mirror, log, ...npmAt([]) }), /carries no packages\.manifest\.json/)
    } finally {
        rmSync(scratch, { recursive: true, force: true })
    }
})
