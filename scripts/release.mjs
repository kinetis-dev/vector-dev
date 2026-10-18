#!/usr/bin/env node
// The release logic .github/workflows/release.yml runs on every push to
// main: each product whose version in packages.manifest.json is not yet
// published goes out — packages/vector to the mirror kinetis-dev/vector
// (Packagist's kinetis/vector), packages/vector-ui to npm
// (@kinetis/vector-ui). The two version independently; the vector-dev
// monorepo itself carries no tags.
//
//     node scripts/release.mjs plan --source=<sha> [--ref=refs/heads/main] [--mirror=<url>] [--dry-run]
//     node scripts/release.mjs notes --product=<vector|vector-ui> --version=0.9.0
//     node scripts/release.mjs split --source=<sha>
//     node scripts/release.mjs release-commit --version=0.9.0 --source=<sha> --split=<sha>
//     node scripts/release.mjs npm-published --version=0.9.0
//     node scripts/release.mjs publish-mirror --version=0.9.0 --source=<sha> --split=<sha> --release=<sha> [--mirror=<url>] [--dry-run]
//
// The mirror's history rule. The mirror's main is the pure subtree split
// S of packages/vector: `git subtree split` derives every commit from the
// monorepo commit it comes from, metadata included, so S is a function of
// the source history and each release's S descends from the last one's —
// main only ever fast-forwards, and dev-main carries no hosted bundle.
// The version tag v<version> points at R, one release commit whose only
// parent is S and whose tree is S's plus resources/dist (the hosted
// bundle, which the monorepo gitignores). So every tagged release ships
// the bundle, and no bundle ever enters main's history. That tag, on the
// mirror, is the only tag a release makes.
//
// What makes a rerun safe, after tools/release-publish.php in kinetis:
//
// - R is deterministic too: it is authored and committed by a fixed,
//   disposable release identity at the source commit's own committer
//   date, so the same source and the same bundle bytes give the same R.
// - The mirror is only written while origin's main still contains the
//   source commit, and the manifest at that commit states this version;
//   see sourceProblem().
// - main is written under an explicit lease naming the main this run
//   read (empty: it must not exist yet), and only forward: a main that
//   moved between the read and the push fails the push, and a split that
//   does not descend from the remote main is refused.
// - A tag already on the mirror is done when it carries R's exact tree,
//   or when it is this same release built earlier: its only parent is S
//   and it differs from S only under resources/dist. That second case is
//   what a fresh rerun meets, because the Nuxt build is not byte-for-byte
//   reproducible (each build draws a random buildId), so a rebuilt R
//   differs from the published one in the bundle alone; the published
//   bundle stands. Anything else under the tag is a failure: two contents
//   cannot both be one version, and choosing is a person's decision.
//
// The mirror credential arrives in RELEASE_DEPLOY_TOKEN, leaves this
// process's environment before any child starts, and reaches git only
// through an askpass helper (owner-only, removed afterwards) passed to
// the push alone — never in a URL, an argument or a config value, all of
// which git can echo. The username is GitHub's x-access-token.
import { execFileSync, spawnSync } from 'node:child_process'
import { appendFileSync, chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { MANIFEST_FILE, changelogHeading, checkConsistency, manifestAt } from './validate-versions.mjs'
import { highestVersion, parseVersion, transitionProblem } from './version-policy.mjs'

export const MIRROR = 'https://github.com/kinetis-dev/vector.git'
export const PREFIX = 'packages/vector'
export const DIST = 'resources/dist'
export const MANIFEST = 'vector-bundle.json'
export const NPM_PACKAGE = '@kinetis/vector-ui'

/** The manifest key of each published product. */
export const PRODUCTS = { vector: 'vector', ui: 'vector-ui' }

/** Who the disposable release commit is authored by, as in kinetis's release-publish.php. */
export const RELEASE_IDENTITY = { name: 'kinetis-release', email: 'noreply@kinetis.dev' }

const DEPLOY_CREDENTIAL_VARIABLE = 'RELEASE_DEPLOY_TOKEN'
const DEPLOY_CREDENTIAL_USERNAME = 'x-access-token'

const SHA = /^[0-9a-f]{40}$/

const root = fileURLToPath(new URL('..', import.meta.url))

/**
 * The body of a CHANGELOG's `## [version]` section (a Keep a Changelog
 * heading, with or without a date after it), up to the next `## `
 * heading, without the link references a changelog ends with. A missing
 * or empty section fails: a release is never published with empty notes.
 */
export function changelogSection(markdown, version, file = 'CHANGELOG.md') {
    const lines = markdown.split(/\r?\n/)
    const heading = changelogHeading(version)
    const start = lines.findIndex(line => heading.test(line))

    if (start === -1) {
        throw new Error(`${file} has no "## [${version}]" section; add one before releasing ${version}.`)
    }

    const end = lines.findIndex((line, index) => index > start && line.startsWith('## '))
    const body = lines
        .slice(start + 1, end === -1 ? undefined : end)
        .filter(line => !/^\[[^\]]+\]:\s/.test(line))
        .join('\n')
        .trim()

    if (body === '') {
        throw new Error(`${file}'s "## [${version}]" section is empty.`)
    }

    return body
}

/**
 * Whether one product's manifest version goes out this round, from what
 * is already published. Pure, so every case is tested; the reads that
 * feed it are in plan().
 *
 * A published version is done. An unpublished one must be one legal step
 * above the highest published version — its predecessor is out — or, when
 * nothing is published yet, the product's first release. GitHub keeps at
 * most one pending run of release.yml, so a version merged while an
 * earlier one's run was still waiting can lose its run entirely; the
 * manifest only ever states the current version, so this is where that
 * gap is seen, and the round stops rather than publishing over it.
 *
 * Returns { publish, notice }, or throws.
 */
export function productPlan({ product, version, published, publishedVersions }) {
    if (published) {
        return { publish: false, notice: `${product} ${version} is already published; nothing to do.` }
    }

    const highest = highestVersion(publishedVersions)

    if (highest === null) {
        return { publish: true, notice: `${product} ${version} is its first release.` }
    }

    const problem = transitionProblem(highest, version)

    if (problem !== null) {
        throw new Error(`${product} ${version} does not follow what is published (highest ${highest}): ${problem}. Publish the missing version first — re-run the release for the commit that introduced it — then re-run this one.`)
    }

    return { publish: true, notice: `${product} ${version} follows the published ${highest}.` }
}

/**
 * What the mirror push does, from what the mirror holds now. Pure, so
 * every case is tested; the git reads that feed it are in publishMirror().
 *
 *   remote.main     the mirror's main, or null when it has none
 *   remote.tag      the commit the mirror's version tag peels to, or null
 *   splitDescends   S descends from (or is) remote.main
 *   mainDescends    remote.main descends from S: a later release is out
 *   tagTreeMatches  remote.tag's tree is R's
 *   tagSameRelease  remote.tag's only parent is S and it differs from S
 *                   only under resources/dist: this release, built earlier
 *
 * Returns { main: bool, tag: bool, notices[] }, or throws.
 */
export function publicationPlan({ split, release, remote, splitDescends, mainDescends, tagTreeMatches, tagSameRelease = false }) {
    const notices = []
    let main = false
    let tag = false

    if (remote.main === null) {
        main = true
    } else if (remote.main === split) {
        notices.push(`The mirror's main is already ${split}.`)
    } else if (splitDescends) {
        main = true
    } else if (mainDescends) {
        // A rerun of an older release after a newer one went out: main
        // stays where the newer release put it; the tag is still this
        // release's to finish.
        notices.push(`The mirror's main (${remote.main}) is ahead of this release's split ${split}; main is left as it is.`)
    } else {
        throw new Error(`The mirror's main (${remote.main}) is not an ancestor of this release's split ${split}; refusing to move main anywhere but forward.`)
    }

    if (remote.tag === null) {
        tag = true
    } else if (remote.tag === release || tagTreeMatches) {
        notices.push(`The mirror's tag already carries this release's tree (${remote.tag}).`)
    } else if (tagSameRelease) {
        notices.push(`The mirror's tag (${remote.tag}) is this release from an earlier build of the hosted bundle; that build stands.`)
    } else {
        throw new Error(`The mirror's tag points at ${remote.tag}, whose tree differs from this release's ${release}; one version cannot carry two contents. A person decides which stands.`)
    }

    return { main, tag, notices }
}

function git(cwd, args, { env, input } = {}) {
    return execFileSync('git', args, {
        cwd,
        encoding: 'utf8',
        env: { ...process.env, ...env },
        input,
        maxBuffer: 64 * 1024 * 1024,
        stdio: ['pipe', 'pipe', 'pipe'],
    }).trim()
}

function sha(value, what) {
    if (!SHA.test(value)) {
        throw new Error(`Expected ${what} to be a full commit SHA, got "${value}".`)
    }

    return value
}

/** The commit a tag peels to, read from a remote: an annotated tag's target, or a lightweight tag's commit. */
export function remoteTagCommit(repo, remote, tag) {
    const lines = git(repo, ['ls-remote', remote, `refs/tags/${tag}`, `refs/tags/${tag}^{}`])
        .split('\n')
        .filter(line => line !== '')
        .map(line => line.split('\t'))
    const peeled = lines.find(([, ref]) => ref === `refs/tags/${tag}^{}`) ?? lines.find(([, ref]) => ref === `refs/tags/${tag}`)

    return peeled === undefined ? null : peeled[0]
}

function remoteMain(repo, remote) {
    const line = git(repo, ['ls-remote', remote, 'refs/heads/main'])

    return line === '' ? null : sha(line.split('\t')[0], "the mirror's main")
}

function isAncestor(repo, ancestor, descendant) {
    return spawnSync('git', ['merge-base', '--is-ancestor', ancestor, descendant], { cwd: repo }).status === 0
}

/** Whether commit is a release commit over split: S as its only parent, and only resources/dist added to S. */
function sameRelease(repo, commit, splitCommit) {
    const changed = git(repo, ['diff', '--name-only', splitCommit, commit]).split('\n').filter(Boolean)

    return git(repo, ['rev-parse', `${commit}^@`]) === splitCommit
        && changed.every(file => file.startsWith(`${DIST}/`))
}

/** A file at a commit, or null when that commit does not have it. */
function showAt(repo, commit, file) {
    const result = spawnSync('git', ['show', `${commit}:${file}`], { cwd: repo, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

    return result.status === 0 ? result.stdout : null
}

/**
 * Every version the mirror publishes: its v<X.Y.Z> tags. Read through the
 * deploy credential when one is given, the same askpass helper the push
 * uses, so a mirror that is not public yet still answers; anonymously
 * otherwise, as a rehearsal does.
 */
export function mirrorVersions(repo, mirror, token) {
    const auth = mirror.startsWith('https://') && token !== undefined && token !== '' ? credential(mirror, token) : null

    try {
        const result = spawnSync('git', ['ls-remote', '--tags', '--refs', mirror], { cwd: repo, env: { ...process.env, ...auth?.env }, encoding: 'utf8' })

        if (result.status !== 0) {
            throw new Error(`Could not list the mirror's tags: ${result.stderr.trim()}`)
        }

        return result.stdout
            .split('\n')
            .map(line => /^[0-9a-f]+\trefs\/tags\/v(.+)$/.exec(line)?.[1])
            .filter(version => version !== undefined && parseVersion(version) !== null)
    } finally {
        if (auth !== null) {
            rmSync(auth.dir, { recursive: true, force: true })
        }
    }
}

/**
 * Decides what this round publishes, before anything is built: the
 * manifest at the source commit, the files it describes there, and, for
 * each product, whether its version is published and may be.
 *
 * A real run publishes only from main: run from refs/heads/main, which
 * the npm-release environment admits and npm's provenance names, at a
 * commit origin/main contains. A rehearsal may run from any branch — a
 * pull request's, before it merges — and reports a commit not on
 * origin/main instead of refusing it. Every other refusal throws on a
 * rehearsal too, and any refusal stops the whole round: nothing is
 * published for one product while the other is refused.
 *
 * Returns { source, vector, ui }, each product { version, publish, notes }.
 */
export function plan(repo, { source, ref, dryRun, mirror = MIRROR, token, npmView, npmVersionsView, log = console.log }) {
    sha(source, 'the source commit')

    if (!dryRun && ref !== undefined && ref !== 'refs/heads/main') {
        throw new Error(`A release that publishes runs from main (refs/heads/main), not ${ref}; run it from main, or as a dry run.`)
    }

    if (!isAncestor(repo, source, 'refs/remotes/origin/main')) {
        if (!dryRun) {
            throw new Error(`${source} is not on origin/main; a release only comes from main.`)
        }

        log(`${source} is not on origin/main; rehearsing it anyway. A real run would refuse it.`)
    }

    const manifest = manifestAt(repo, source)

    if (manifest === null) {
        throw new Error(`${source} carries no ${MANIFEST_FILE}; there is nothing to release.`)
    }

    const read = file => showAt(repo, source, file)
    const problems = checkConsistency(manifest, read)

    if (problems.length > 0) {
        throw new Error(`The versions at ${source} are inconsistent:\n${problems.join('\n')}`)
    }

    const decide = (key, published, publishedVersions) => {
        const entry = manifest.packages[key]

        if (entry === undefined) {
            throw new Error(`${MANIFEST_FILE} at ${source} has no product "${key}".`)
        }

        const decision = productPlan({ product: entry.name, version: entry.version, published: published(entry.version), publishedVersions })
        log(decision.notice)

        // The release notes are the product's CHANGELOG section, which must
        // be there and say something before anything goes out.
        const notes = decision.publish ? changelogSection(read(`${entry.path}/CHANGELOG.md`), entry.version, `${entry.path}/CHANGELOG.md`) : null

        return { version: entry.version, publish: decision.publish, notes }
    }

    const tags = mirrorVersions(repo, mirror, token)
    const vector = decide(PRODUCTS.vector, version => tags.includes(version), tags)
    const npmVersions = npmPublishedVersions(npmVersionsView)
    const ui = decide(PRODUCTS.ui, version => npmPublished(version, npmView), npmVersions)

    return { source, vector, ui }
}

/** S: the pure subtree split of packages/vector at the source commit. */
export function split(repo, source) {
    return sha(git(repo, ['subtree', 'split', '--quiet', `--prefix=${PREFIX}`, sha(source, 'the source commit')]), 'the split')
}

/**
 * R: one commit whose only parent is S and whose tree is S's tree plus
 * packages/vector/resources/dist, built from git's plumbing rather than
 * a worktree — a scratch index seeded from S, the gitignored bundle
 * force-added into it, `write-tree` and `commit-tree` — so nothing in the
 * monorepo checkout moves and no ref is created. The bundle must be the
 * release build of this very source: its manifest's version and source
 * are checked first.
 */
export function releaseCommit(repo, { version, source, split: splitCommit }) {
    const packageDir = path.join(repo, PREFIX)
    const manifest = JSON.parse(readFileSync(path.join(packageDir, DIST, MANIFEST), 'utf8'))

    if (manifest.version !== version || manifest.source !== source) {
        throw new Error(`${PREFIX}/${DIST}/${MANIFEST} is the bundle ${manifest.version} from ${manifest.source}, not ${version} from ${source}; build it with scripts/build-hosted-bundle.mjs --version=${version} at the source commit.`)
    }

    const date = git(repo, ['show', '--no-patch', '--format=%cI', source])
    const scratch = mkdtempSync(path.join(tmpdir(), 'vector-release-index-'))
    const index = { GIT_INDEX_FILE: path.join(scratch, 'index') }
    const gitDir = git(repo, ['rev-parse', '--absolute-git-dir'])

    try {
        git(repo, ['read-tree', splitCommit], { env: index })
        // The package directory as the work tree, so the paths land where
        // they live in the split: resources/dist/..., not packages/vector/...
        git(packageDir, [`--git-dir=${gitDir}`, '--work-tree=.', 'add', '--force', '--', DIST], { env: index })
        const tree = git(repo, ['write-tree'], { env: index })

        return sha(git(repo, ['commit-tree', tree, '-p', splitCommit, '-F', '-'], {
            env: {
                GIT_AUTHOR_NAME: RELEASE_IDENTITY.name,
                GIT_AUTHOR_EMAIL: RELEASE_IDENTITY.email,
                GIT_AUTHOR_DATE: date,
                GIT_COMMITTER_NAME: RELEASE_IDENTITY.name,
                GIT_COMMITTER_EMAIL: RELEASE_IDENTITY.email,
                GIT_COMMITTER_DATE: date,
            },
            input: `Release v${version}\n\nkinetis/vector ${version} from kinetis-dev/vector-dev@${source},\nwith the hosted admin bundle in ${DIST}.\n`,
        }), 'the release commit')
    } finally {
        rmSync(scratch, { recursive: true, force: true })
    }
}

/** The askpass helper the push alone reads the credential through, or null without one. */
function credential(mirror, token) {
    if (!mirror.startsWith('https://')) {
        return null
    }

    if (token === undefined || token === '') {
        throw new Error(`${DEPLOY_CREDENTIAL_VARIABLE} is not set; the mirror push needs it.`)
    }

    const dir = mkdtempSync(path.join(tmpdir(), 'vector-release-askpass-'))
    const helper = path.join(dir, 'askpass.sh')
    // Names the variables, holds neither value.
    writeFileSync(helper, '#!/bin/sh\ncase "$1" in\n    Username*) printf \'%s\\n\' "$RELEASE_DEPLOY_USERNAME" ;;\n    Password*) printf \'%s\\n\' "$RELEASE_DEPLOY_TOKEN" ;;\nesac\n', { mode: 0o700 })
    chmodSync(helper, 0o700)

    return {
        dir,
        env: {
            GIT_ASKPASS: helper,
            GIT_TERMINAL_PROMPT: '0',
            RELEASE_DEPLOY_USERNAME: DEPLOY_CREDENTIAL_USERNAME,
            [DEPLOY_CREDENTIAL_VARIABLE]: token,
        },
    }
}

/**
 * Why the mirror must not be written for `version` from `source`, or null.
 *
 * With a tag trigger, the guard was that the source tag still resolved to
 * the commit the run released: a moved tag was a different release. A
 * push to main has no tag to re-read, so the guard is what makes a source
 * commit a release here — main contains it, and its manifest names this
 * version. origin's main is read again, now: a main that no longer
 * contains the source (force-pushed past it) has withdrawn this release.
 * A main that moved on to a newer version has not — the newer version
 * needs this one published as its predecessor — so main's own current
 * manifest is deliberately not compared. The manifest at the source binds
 * the version this run builds to the commit it builds from, as the tag
 * bound the two before.
 */
export function sourceProblem(repo, { source, version }) {
    const line = git(repo, ['ls-remote', 'origin', 'refs/heads/main'])
    const current = line === '' ? null : sha(line.split('\t')[0], "origin's main")

    if (current === null) {
        return 'origin has no main'
    }

    if (spawnSync('git', ['cat-file', '-e', `${current}^{commit}`], { cwd: repo }).status !== 0) {
        git(repo, ['fetch', '--quiet', '--no-tags', 'origin', 'refs/heads/main'])
    }

    if (!isAncestor(repo, source, current)) {
        return `origin's main (${current}) no longer contains ${source}`
    }

    const stated = manifestAt(repo, source)?.packages[PRODUCTS.vector]?.version

    if (stated !== version) {
        return `the manifest at ${source} states kinetis/vector ${stated ?? 'nothing'}, not ${version}`
    }

    return null
}

/**
 * Reads the mirror (unauthenticated: it is public), decides with
 * publicationPlan(), re-checks the source with sourceProblem(), and
 * pushes what is left in one atomic push — or, on a dry run, says what
 * it would push.
 */
export function publishMirror(repo, { version, source, split: splitCommit, release, mirror = MIRROR, dryRun = false, token, log = console.log }) {
    const tag = `v${version}`
    const remote = { main: remoteMain(repo, mirror), tag: remoteTagCommit(repo, mirror, tag) }
    const fetched = [remote.main, remote.tag].filter(commit => commit !== null)

    if (fetched.length > 0) {
        // The remote objects, so ancestry and trees can be compared locally.
        git(repo, ['fetch', '--quiet', '--no-tags', mirror, ...(remote.main === null ? [] : ['refs/heads/main']), ...(remote.tag === null ? [] : [`refs/tags/${tag}`])])
    }

    const plan = publicationPlan({
        split: splitCommit,
        release,
        remote,
        splitDescends: remote.main !== null && isAncestor(repo, remote.main, splitCommit),
        mainDescends: remote.main !== null && isAncestor(repo, splitCommit, remote.main),
        tagTreeMatches: remote.tag !== null && git(repo, ['rev-parse', `${remote.tag}^{tree}`]) === git(repo, ['rev-parse', `${release}^{tree}`]),
        tagSameRelease: remote.tag !== null && sameRelease(repo, remote.tag, splitCommit),
    })
    plan.notices.forEach(notice => log(notice))

    const refspecs = [
        ...(plan.main ? [`${splitCommit}:refs/heads/main`] : []),
        ...(plan.tag ? [`${release}:refs/tags/${tag}`] : []),
    ]

    if (refspecs.length === 0) {
        log(`The mirror already holds ${tag}; nothing to push.`)

        return plan
    }

    // Only while the source is still this release. A rehearsal — from a
    // branch not merged yet, say — says so and writes nothing.
    const problem = sourceProblem(repo, { source, version })

    if (problem !== null && dryRun) {
        log(`${problem}; a real run would refuse to write the mirror.`)
    } else if (problem !== null) {
        throw new Error(`${problem}; refusing to write the mirror.`)
    }

    const push = [
        'push', '--atomic',
        // main moves only under a lease naming the main read above; an
        // empty expectation is git's spelling of "must not exist yet".
        ...(plan.main ? [`--force-with-lease=refs/heads/main:${remote.main ?? ''}`] : []),
        mirror, ...refspecs,
    ]

    if (dryRun) {
        log(`Dry run: would run git ${push.join(' ')}`)

        return plan
    }

    const auth = credential(mirror, token)

    try {
        const result = spawnSync('git', push, { cwd: repo, env: { ...process.env, ...auth?.env }, encoding: 'utf8' })

        if (result.status !== 0) {
            throw new Error(`git push to the mirror failed: ${result.stderr.trim()}`)
        }
    } finally {
        if (auth !== null) {
            rmSync(auth.dir, { recursive: true, force: true })
        }
    }

    log(`Pushed ${refspecs.join(', ')} to ${mirror}.`)

    return plan
}

function npmView(args) {
    return () => spawnSync('npm', ['view', ...args], { encoding: 'utf8' })
}

/**
 * Whether @kinetis/vector-ui@version is on npm: `npm view` prints the
 * version when it is, nothing when the package exists without it, and
 * fails with E404 before the first publish. Any other failure is not an
 * answer, and fails.
 */
export function npmPublished(version, view = npmView([`${NPM_PACKAGE}@${version}`, 'version'])) {
    const result = view()

    if (result.status === 0) {
        return result.stdout.trim().split('\n').map(line => line.trim().replace(/^'|'$/g, '')).includes(version)
    }

    if (/\bE404\b/.test(result.stderr ?? '')) {
        return false
    }

    throw new Error(`Could not ask npm whether ${NPM_PACKAGE}@${version} exists: ${(result.stderr ?? '').trim() || `npm exited ${result.status}`}`)
}

/**
 * Every version of @kinetis/vector-ui on npm: none before the first
 * publish (E404). `npm view --json` prints a bare string for a package
 * with one version, an array otherwise.
 */
export function npmPublishedVersions(view = npmView([NPM_PACKAGE, 'versions', '--json'])) {
    const result = view()

    if (result.status === 0) {
        const versions = result.stdout.trim() === '' ? [] : JSON.parse(result.stdout)

        return Array.isArray(versions) ? versions : [versions]
    }

    if (/\bE404\b/.test(result.stderr ?? '')) {
        return []
    }

    throw new Error(`Could not ask npm which versions of ${NPM_PACKAGE} exist: ${(result.stderr ?? '').trim() || `npm exited ${result.status}`}`)
}

function options(argv) {
    const result = {}

    for (const arg of argv) {
        const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg)

        if (match === null) {
            throw new Error(`Unknown argument ${arg}.`)
        }

        result[match[1]] = match[2] ?? true
    }

    return result
}

function required(opts, name) {
    if (typeof opts[name] !== 'string' || opts[name] === '') {
        throw new Error(`--${name}=<value> is required.`)
    }

    return opts[name]
}

/** Writes key=value lines to $GITHUB_OUTPUT when it is set, and prints them either way. */
function output(values) {
    const lines = Object.entries(values).map(([key, value]) => `${key}=${value}`)
    lines.forEach(line => console.log(line))

    if (process.env.GITHUB_OUTPUT) {
        appendFileSync(process.env.GITHUB_OUTPUT, `${lines.join('\n')}\n`)
    }
}

/** What this round publishes, and each release's notes, on the run's summary page when there is one. */
function summary(result) {
    const lines = ['## This round', '']

    for (const [name, product] of [['kinetis/vector', result.vector], [NPM_PACKAGE, result.ui]]) {
        lines.push(`- **${name} ${product.version}**: ${product.publish ? 'publishes' : 'already published'}`)
    }

    for (const [name, product] of [['kinetis/vector', result.vector], [NPM_PACKAGE, result.ui]]) {
        if (product.publish) {
            lines.push('', `## ${name} ${product.version}`, '', product.notes)
        }
    }

    const text = `${lines.join('\n')}\n`
    console.log(text)

    if (process.env.GITHUB_STEP_SUMMARY) {
        appendFileSync(process.env.GITHUB_STEP_SUMMARY, text)
    }
}

export function main(argv) {
    // Out of the environment before any child starts; only the plan's read of
    // the mirror's tags (mirrorVersions) and the mirror push get it, each
    // through the askpass helper.
    const token = process.env[DEPLOY_CREDENTIAL_VARIABLE]
    delete process.env[DEPLOY_CREDENTIAL_VARIABLE]

    const [command, ...rest] = argv
    const opts = options(rest)
    const dryRun = opts['dry-run'] === true || opts['dry-run'] === 'true'

    switch (command) {
        case 'plan': {
            const result = plan(root, { source: required(opts, 'source'), ref: opts.ref, dryRun, mirror: opts.mirror ?? MIRROR, token })
            summary(result)
            output({
                source: result.source,
                vector_version: result.vector.version,
                vector_publish: result.vector.publish,
                ui_version: result.ui.version,
                ui_publish: result.ui.publish,
            })
            break
        }
        case 'notes': {
            const product = required(opts, 'product')
            const version = required(opts, 'version')
            const entry = JSON.parse(readFileSync(path.join(root, MANIFEST_FILE), 'utf8')).packages[product]

            if (entry === undefined) {
                throw new Error(`--product=${product} is not in ${MANIFEST_FILE}.`)
            }

            process.stdout.write(`${changelogSection(readFileSync(path.join(root, entry.path, 'CHANGELOG.md'), 'utf8'), version, `${entry.path}/CHANGELOG.md`)}\n`)
            break
        }
        case 'split':
            output({ split: split(root, required(opts, 'source')) })
            break
        case 'release-commit': {
            const release = releaseCommit(root, { version: required(opts, 'version'), source: sha(required(opts, 'source'), '--source'), split: sha(required(opts, 'split'), '--split') })
            const bundled = git(root, ['ls-tree', '-r', '--name-only', release, '--', DIST]).split('\n').filter(Boolean).length
            console.log(`R carries ${bundled} files under ${DIST}.`)
            output({ release })
            break
        }
        case 'npm-published': {
            const version = required(opts, 'version')
            const published = npmPublished(version)
            console.log(published ? `${NPM_PACKAGE}@${version} is already on npm.` : `${NPM_PACKAGE}@${version} is not on npm yet.`)
            output({ published })
            break
        }
        case 'publish-mirror':
            publishMirror(root, {
                version: required(opts, 'version'),
                source: sha(required(opts, 'source'), '--source'),
                split: sha(required(opts, 'split'), '--split'),
                release: sha(required(opts, 'release'), '--release'),
                mirror: opts.mirror ?? MIRROR,
                dryRun,
                token,
            })
            break
        default:
            throw new Error('Usage: release.mjs plan|notes|split|release-commit|npm-published|publish-mirror [--option=value ...]')
    }
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        main(process.argv.slice(2))
    } catch (error) {
        console.error(process.env.GITHUB_ACTIONS ? `::error::${error.message}` : error.message)
        process.exitCode = 1
    }
}
