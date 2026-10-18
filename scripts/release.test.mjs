// node --test scripts/*.test.mjs — the release rules, and the release
// commit and mirror push on throwaway git repositories (skipped where git
// is not installed, as in the demo's node container).
import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import {
    changelogSection, checkVersion, distTag, npmPublished, publicationPlan, publishMirror,
    releaseCommit, releaseNotes, split, verify, versionFromTag,
} from './release.mjs'

test('a tag is vX.Y.Z or vX.Y.Z-prerelease', () => {
    assert.equal(versionFromTag('v0.9.0'), '0.9.0')
    assert.equal(versionFromTag('v1.0.0-rc.1'), '1.0.0-rc.1')

    for (const malformed of ['0.9.0', 'v0.9', 'v0.9.0.1', 'v0.9.0-', 'v0.9.0+build', 'release-0.9.0', 'v0.9.0 ', '']) {
        assert.throws(() => versionFromTag(malformed), /is not a release tag/, malformed)
    }
})

test("the tag's version must be packages/vector-ui's", () => {
    assert.equal(checkVersion('v0.9.0', '0.9.0'), '0.9.0')
    assert.throws(() => checkVersion('v0.9.1', '0.9.0'), /releases 0\.9\.1, but packages\/vector-ui\/package\.json is at 0\.9\.0/)
    assert.throws(() => checkVersion('v1.0.0', '1.0.0-rc.1'), /is at 1\.0\.0-rc\.1/)
    assert.throws(() => checkVersion('0.9.0', '0.9.0'), /is not a release tag/)
})

test('dist-tag: next for 0.x and prereleases, latest from 1.0.0', () => {
    assert.equal(distTag('0.9.0'), 'next')
    assert.equal(distTag('0.10.3'), 'next')
    assert.equal(distTag('0.9.0-rc.1'), 'next')
    assert.equal(distTag('1.0.0-rc.1'), 'next')
    assert.equal(distTag('1.0.0'), 'latest')
    assert.equal(distTag('2.3.4'), 'latest')
    assert.equal(distTag('2.0.0-beta.2'), 'next')
})

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
    // 0.9.0 is not 0.9.0-rc.1, nor 0x9x0
    assert.throws(() => changelogSection(changelog, '0.9.0-rc.1'), /no "## \[0\.9\.0-rc\.1\]" section/)
    assert.throws(() => changelogSection('## [0x9x0]\n\n- x\n', '0.9.0'), /no "## \[0\.9\.0\]" section/)
    assert.throws(() => changelogSection('## [0.9.0]\n\n## [0.1.0]\n- x\n', '0.9.0'), /section is empty/)
})

test('the release notes carry both sections, and fail when either is missing', () => {
    const notes = releaseNotes('0.9.0', { vector: changelog, ui: changelog })
    assert.match(notes, /^## kinetis\/vector\n\n### Added\n\n- Everything\.\n\n## @kinetis\/vector-ui\n\n### Added/)
    assert.throws(() => releaseNotes('0.9.0', { vector: changelog, ui: '## [Unreleased]\n' }), /packages\/vector-ui\/CHANGELOG\.md has no/)
})

test('npm: published, not yet, or no answer', () => {
    assert.equal(npmPublished('0.9.0', () => ({ status: 0, stdout: '0.9.0\n' })), true)
    assert.equal(npmPublished('0.9.0', () => ({ status: 0, stdout: '' })), false)
    assert.equal(npmPublished('0.9.0', () => ({ status: 1, stdout: '', stderr: 'npm error code E404\n' })), false)
    assert.throws(() => npmPublished('0.9.0', () => ({ status: 1, stdout: '', stderr: 'npm error code ETIMEDOUT\n' })), /Could not ask npm/)
})

const S = 'a'.repeat(40)
const R = 'b'.repeat(40)
const OLD = 'c'.repeat(40)
const base = { split: S, release: R, splitDescends: false, mainDescends: false, tagTreeMatches: false }

test('mirror plan: a first release writes main and the tag', () => {
    assert.deepEqual(publicationPlan({ ...base, remote: { main: null, tag: null } }), { main: true, tag: true, notices: [] })
})

test('mirror plan: main only fast-forwards', () => {
    assert.equal(publicationPlan({ ...base, remote: { main: OLD, tag: null }, splitDescends: true }).main, true)
    assert.equal(publicationPlan({ ...base, remote: { main: S, tag: null } }).main, false)
    assert.equal(publicationPlan({ ...base, remote: { main: OLD, tag: null }, mainDescends: true }).main, false)
    assert.throws(() => publicationPlan({ ...base, remote: { main: OLD, tag: null } }), /not an ancestor/)
})

test('mirror plan: an existing tag is done with the same tree, a failure otherwise', () => {
    assert.deepEqual(publicationPlan({ ...base, remote: { main: S, tag: R } }).tag, false)
    assert.deepEqual(publicationPlan({ ...base, remote: { main: S, tag: OLD }, tagTreeMatches: true }).tag, false)
    assert.throws(() => publicationPlan({ ...base, remote: { main: S, tag: OLD } }), /tree differs/)
})

test('mirror plan: the same release from an earlier bundle build is done, and stands', () => {
    const plan = publicationPlan({ ...base, remote: { main: S, tag: OLD }, tagSameRelease: true })
    assert.equal(plan.tag, false)
    assert.match(plan.notices.join('\n'), /earlier build of the hosted bundle/)
})

const hasGit = spawnSync('git', ['--version']).status === 0
const run = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8' }).trim()

test('the release commit and the mirror push, end to end on scratch repositories', { skip: !hasGit && 'git is not installed' }, () => {
    const scratch = mkdtempSync(path.join(tmpdir(), 'vector-release-test-'))

    try {
        const repo = path.join(scratch, 'vector-dev')
        const mirror = path.join(scratch, 'mirror.git')
        mkdirSync(path.join(repo, 'packages/vector/src'), { recursive: true })
        run(scratch, 'init', '--quiet', '--initial-branch=main', repo)
        run(scratch, 'init', '--quiet', '--bare', mirror)
        run(repo, 'config', 'user.name', 'Test')
        run(repo, 'config', 'user.email', 'test@example.com')
        writeFileSync(path.join(repo, '.gitignore'), '/packages/vector/resources/dist/\n')
        writeFileSync(path.join(repo, 'packages/vector/src/A.php'), '<?php\n')
        writeFileSync(path.join(repo, 'README.md'), 'monorepo\n')
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'First')
        run(repo, 'tag', 'v0.9.0')
        // origin, for the tag re-check: the repository itself
        run(repo, 'remote', 'add', 'origin', repo)

        const source = run(repo, 'rev-parse', 'HEAD')
        const dist = path.join(repo, 'packages/vector/resources/dist')
        mkdirSync(dist, { recursive: true })
        writeFileSync(path.join(dist, 'index.html'), '<!doctype html>')
        writeFileSync(path.join(dist, 'vector-bundle.json'), JSON.stringify({ version: '0.9.0', source }))

        const s = split(repo, source)
        assert.equal(split(repo, source), s, 'the split is deterministic')
        const r = releaseCommit(repo, { version: '0.9.0', source, split: s })
        assert.equal(releaseCommit(repo, { version: '0.9.0', source, split: s }), r, 'the release commit is deterministic')
        assert.equal(run(repo, 'rev-parse', `${r}^@`), s, 'R has exactly one parent, S')
        assert.match(run(repo, 'ls-tree', '-r', '--name-only', r), /^resources\/dist\/vector-bundle\.json$/m)
        assert.doesNotMatch(run(repo, 'ls-tree', '-r', '--name-only', s), /resources\/dist/)
        assert.equal(run(repo, 'status', '--porcelain'), '', 'the checkout is untouched')

        assert.throws(() => releaseCommit(repo, { version: '0.9.1', source, split: s }), /is the bundle 0\.9\.0/)

        const log = () => {}
        assert.deepEqual(publishMirror(repo, { tag: 'v0.9.0', source, split: s, release: r, mirror, log }), { main: true, tag: true, notices: [] })
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s)
        assert.equal(run(mirror, 'rev-parse', 'refs/tags/v0.9.0'), r)
        assert.deepEqual(publishMirror(repo, { tag: 'v0.9.0', source, split: s, release: r, mirror, log }).main, false, 'a rerun is a no-op')

        // A rerun whose bundle build came out different: the published tag stands.
        writeFileSync(path.join(dist, 'index.html'), '<!doctype html><!-- another buildId -->')
        const rebuilt = releaseCommit(repo, { version: '0.9.0', source, split: s })
        assert.notEqual(rebuilt, r)
        assert.deepEqual(publishMirror(repo, { tag: 'v0.9.0', source, split: s, release: rebuilt, mirror, log }), { main: false, tag: false, notices: [
            `The mirror's main is already ${s}.`,
            `The mirror's tag (${r}) is this release from an earlier build of the hosted bundle; that build stands.`,
        ] })
        assert.equal(run(mirror, 'rev-parse', 'refs/tags/v0.9.0'), r)

        // Other content under the tag is refused: here, a commit that changes the package itself.
        writeFileSync(path.join(repo, 'packages/vector/src/A.php'), '<?php // changed\n')
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'Changed source')
        run(repo, 'push', '--quiet', '--force', mirror, `${releaseCommit(repo, { version: '0.9.0', source, split: split(repo, run(repo, 'rev-parse', 'HEAD')) })}:refs/tags/v0.9.0`)
        assert.throws(() => publishMirror(repo, { tag: 'v0.9.0', source, split: s, release: r, mirror, log }), /tree differs/)
        run(repo, 'push', '--quiet', '--force', mirror, `${r}:refs/tags/v0.9.0`)
        run(repo, 'reset', '--quiet', '--hard', 'HEAD^')
        writeFileSync(path.join(dist, 'index.html'), '<!doctype html>')

        // A moved source tag stops a write.
        writeFileSync(path.join(repo, 'packages/vector/src/B.php'), '<?php\n')
        run(repo, 'add', '.')
        run(repo, 'commit', '--quiet', '-m', 'Second')
        const later = run(repo, 'rev-parse', 'HEAD')
        run(repo, 'tag', 'v0.9.1')
        const s2 = split(repo, later)
        assert.throws(() => publishMirror(repo, { tag: 'v0.9.1', source, split: s2, release: s2, mirror, log }), /now resolves to/)
        // A rehearsal says so instead, and writes nothing.
        const said = []
        publishMirror(repo, { tag: 'v0.9.1', source, split: s2, release: s2, mirror, dryRun: true, log: line => said.push(line) })
        assert.match(said.join('\n'), /a real run would refuse[\s\S]*Dry run: would run git push --atomic --force-with-lease=refs\/heads\/main:/)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s)

        // A later release fast-forwards main.
        writeFileSync(path.join(dist, 'vector-bundle.json'), JSON.stringify({ version: '0.9.1', source: later }))
        const r2 = releaseCommit(repo, { version: '0.9.1', source: later, split: s2 })
        publishMirror(repo, { tag: 'v0.9.1', source: later, split: s2, release: r2, mirror, log })
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s2)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main^'), s)

        // Rerunning the older release leaves main where it is.
        assert.equal(publishMirror(repo, { tag: 'v0.9.0', source, split: s, release: r, mirror, log }).main, false)
        assert.equal(run(mirror, 'rev-parse', 'refs/heads/main'), s2)
    } finally {
        rmSync(scratch, { recursive: true, force: true })
    }
})

test('verify reads the tagged commit, and a release only comes from main', { skip: !hasGit && 'git is not installed' }, () => {
    const repo = mkdtempSync(path.join(tmpdir(), 'vector-release-verify-'))

    try {
        const commit = (files, message) => {
            for (const [file, contents] of Object.entries(files)) {
                mkdirSync(path.dirname(path.join(repo, file)), { recursive: true })
                writeFileSync(path.join(repo, file), contents)
            }

            run(repo, 'add', '.')
            run(repo, 'commit', '--quiet', '-m', message)
        }

        run(repo, 'init', '--quiet', '--initial-branch=main')
        run(repo, 'config', 'user.name', 'Test')
        run(repo, 'config', 'user.email', 'test@example.com')
        commit({
            'packages/vector-ui/package.json': JSON.stringify({ version: '0.9.0' }),
            'packages/vector/CHANGELOG.md': changelog,
            'packages/vector-ui/CHANGELOG.md': changelog,
        }, 'Release 0.9.0')
        run(repo, 'tag', 'v0.9.0')
        run(repo, 'tag', 'v0.9.1')
        // The checkout's own package.json moves on; the tag's is what counts.
        commit({ 'packages/vector-ui/package.json': JSON.stringify({ version: '1.0.0' }) }, 'Bump')
        run(repo, 'remote', 'add', 'origin', repo)
        run(repo, 'fetch', '--quiet', 'origin')

        const result = verify(repo, { tag: 'v0.9.0', ref: 'refs/tags/v0.9.0', dryRun: false })
        assert.equal(result.version, '0.9.0')
        assert.equal(result.distTag, 'next')
        assert.equal(result.source, run(repo, 'rev-parse', 'v0.9.0^{commit}'))

        assert.throws(() => verify(repo, { tag: 'v0.9.1', ref: 'refs/tags/v0.9.1', dryRun: false }), /releases 0\.9\.1, but packages\/vector-ui\/package\.json is at 0\.9\.0/)
        assert.throws(() => verify(repo, { tag: 'v0.9', ref: 'refs/tags/v0.9', dryRun: false }), /is not a release tag/)
        assert.throws(() => verify(repo, { tag: 'v0.9.0', ref: 'refs/heads/main', dryRun: false }), /runs from the tag itself/)
        assert.equal(verify(repo, { tag: 'v0.9.0', ref: 'refs/heads/main', dryRun: true }).version, '0.9.0')
        assert.throws(() => verify(repo, { tag: 'v0.9.2', dryRun: true }), /does not exist/)
        // A rehearsal before the tag runs at the dispatched commit; a real run never does.
        const head = run(repo, 'rev-parse', 'v0.9.0^{commit}')
        run(repo, 'tag', '-d', 'v0.9.0')
        assert.equal(verify(repo, { tag: 'v0.9.0', dryRun: true, head, log: () => {} }).source, head)
        assert.throws(() => verify(repo, { tag: 'v0.9.0', ref: 'refs/heads/main', dryRun: false, head }), /runs from the tag itself/)
        assert.throws(() => verify(repo, { tag: 'v0.9.0', ref: 'refs/tags/v0.9.0', dryRun: false, head }), /does not exist/)
        run(repo, 'tag', 'v0.9.0', head)

        // A tag on a branch that never reached main.
        run(repo, 'checkout', '--quiet', '-b', 'side', 'v0.9.0')
        commit({ 'packages/vector-ui/package.json': JSON.stringify({ version: '0.9.3' }), 'packages/vector/CHANGELOG.md': changelog.replace('[0.9.0]', '[0.9.3]'), 'packages/vector-ui/CHANGELOG.md': changelog.replace('[0.9.0]', '[0.9.3]') }, 'Side')
        run(repo, 'tag', 'v0.9.3')
        assert.throws(() => verify(repo, { tag: 'v0.9.3', dryRun: true }), /is not on origin\/main/)

        // A missing CHANGELOG section fails verify.
        run(repo, 'checkout', '--quiet', 'main')
        commit({ 'packages/vector-ui/package.json': JSON.stringify({ version: '1.0.0' }), 'packages/vector/CHANGELOG.md': changelog.replace('[0.9.0]', '[1.0.0]') }, 'Only one section')
        run(repo, 'tag', 'v1.0.0')
        run(repo, 'fetch', '--quiet', 'origin')
        assert.throws(() => verify(repo, { tag: 'v1.0.0', dryRun: true }), /packages\/vector-ui\/CHANGELOG\.md has no "## \[1\.0\.0\]" section/)
    } finally {
        rmSync(repo, { recursive: true, force: true })
    }
})
