#!/usr/bin/env node
// The release logic .github/workflows/release.yml runs: one v* tag
// releases packages/vector to the mirror kinetis-dev/vector (Packagist's
// kinetis/vector) and packages/vector-ui to npm (@kinetis/vector-ui), at
// one shared version.
//
//     node scripts/release.mjs verify --tag=v0.9.0 [--ref=refs/tags/v0.9.0] [--dry-run [--head=<sha>]]
//     node scripts/release.mjs notes --version=0.9.0
//     node scripts/release.mjs split --source=<sha>
//     node scripts/release.mjs release-commit --version=0.9.0 --source=<sha> --split=<sha>
//     node scripts/release.mjs npm-published --version=0.9.0
//     node scripts/release.mjs publish-mirror --tag=v0.9.0 --source=<sha> --split=<sha> --release=<sha> [--mirror=<url>] [--dry-run]
//
// The mirror's history rule. The mirror's main is the pure subtree split
// S of packages/vector: `git subtree split` derives every commit from the
// monorepo commit it comes from, metadata included, so S is a function of
// the source history and each release's S descends from the last one's —
// main only ever fast-forwards, and dev-main carries no hosted bundle.
// The version tag points at R, one release commit whose only parent is S
// and whose tree is S's plus resources/dist (the hosted bundle, which the
// monorepo gitignores). So every tagged release ships the bundle, and no
// bundle ever enters main's history.
//
// What makes a rerun safe, after tools/release-publish.php in kinetis:
//
// - R is deterministic too: it is authored and committed by a fixed,
//   disposable release identity at the source commit's own committer
//   date, so the same source and the same bundle bytes give the same R.
// - The mirror is only written while the source tag still resolves to
//   the commit this run released; a tag moved since is a different
//   release, and the run fails before writing.
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

export const MIRROR = 'https://github.com/kinetis-dev/vector.git'
export const PREFIX = 'packages/vector'
export const DIST = 'resources/dist'
export const MANIFEST = 'vector-bundle.json'

/** Who the disposable release commit is authored by, as in kinetis's release-publish.php. */
export const RELEASE_IDENTITY = { name: 'kinetis-release', email: 'noreply@kinetis.dev' }

const DEPLOY_CREDENTIAL_VARIABLE = 'RELEASE_DEPLOY_TOKEN'
const DEPLOY_CREDENTIAL_USERNAME = 'x-access-token'

const TAG = /^v\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/
const SHA = /^[0-9a-f]{40}$/

const root = fileURLToPath(new URL('..', import.meta.url))

/** The version a release tag names: vX.Y.Z or vX.Y.Z-prerelease, nothing else. */
export function versionFromTag(tag) {
    if (typeof tag !== 'string' || !TAG.test(tag)) {
        throw new Error(`"${tag}" is not a release tag: expected vX.Y.Z or vX.Y.Z-<prerelease>, such as v0.9.0 or v1.0.0-rc.1.`)
    }

    return tag.slice(1)
}

/** The tag's version, which must be packages/vector-ui's: both packages release one shared version. */
export function checkVersion(tag, packageVersion) {
    const version = versionFromTag(tag)

    if (version !== packageVersion) {
        throw new Error(`${tag} releases ${version}, but packages/vector-ui/package.json is at ${packageVersion}; bump the version first, then tag.`)
    }

    return version
}

/** Whether a version is a prerelease (carries a -suffix). */
export function isPrerelease(version) {
    return version.includes('-')
}

/**
 * The npm dist-tag a version is published under: `next` for every 0.x
 * version and every prerelease, `latest` from 1.0.0 on.
 *
 * The registry requires a `latest` tag, so the package's very first
 * publish also sets `latest` to that version, whatever --tag says. Later
 * 0.x releases move only `next`, so the bare package name keeps resolving
 * to the first release until a maintainer moves `latest` by hand
 * (`npm dist-tag add @kinetis/vector-ui@<version> latest`, from a token-
 * or 2FA-authenticated maintainer account: trusted publishing's OIDC
 * credential can only publish), or until 1.0.0 publishes with `latest`.
 */
export function distTag(version) {
    const major = Number(versionFromTag(`v${version}`).split('.')[0])

    return isPrerelease(version) || major === 0 ? 'next' : 'latest'
}

/**
 * The body of a CHANGELOG's `## [version]` section (a Keep a Changelog
 * heading, with or without a date after it), up to the next `## `
 * heading, without the link references a changelog ends with. A missing
 * or empty section fails: a release is never published with empty notes.
 */
export function changelogSection(markdown, version, file = 'CHANGELOG.md') {
    const lines = markdown.split(/\r?\n/)
    const heading = new RegExp(`^## \\[${version.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\](\\s.*)?$`)
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

/** The GitHub Release body: both packages' sections, in order. */
export function releaseNotes(version, { vector, ui }) {
    return [
        '## kinetis/vector',
        '',
        changelogSection(vector, version, 'packages/vector/CHANGELOG.md'),
        '',
        '## @kinetis/vector-ui',
        '',
        changelogSection(ui, version, 'packages/vector-ui/CHANGELOG.md'),
        '',
    ].join('\n')
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

/**
 * Checks a tag before anything is built: its form, the shared version,
 * both CHANGELOG sections, that a real (non-dry) run is running from the
 * tag itself, and that the tagged commit is on origin/main.
 */
export function verify(repo, { tag, ref, dryRun, head, log = console.log }) {
    versionFromTag(tag)

    // A real release runs from the tag, so the npm-release environment's
    // tag rule admits it and npm's provenance names the tag.
    if (!dryRun && ref !== undefined && ref !== `refs/tags/${tag}`) {
        throw new Error(`A release that publishes runs from the tag itself (refs/tags/${tag}), not ${ref}; dispatch it from the tag, or as a dry run.`)
    }

    let source

    try {
        source = git(repo, ['rev-parse', '--verify', '--quiet', `refs/tags/${tag}^{commit}`])
    } catch {
        // A rehearsal comes before the tag, since pushing the tag is the
        // release: it runs at the dispatched commit, as if tagged.
        if (!dryRun || head === undefined) {
            throw new Error(`The tag ${tag} does not exist in this checkout.`)
        }

        source = sha(head, 'the dispatched commit')
        log(`${tag} does not exist yet; rehearsing at ${source} as if it were tagged ${tag}.`)
    }

    // The tagged commit's own files, whatever the checkout is on.
    const show = file => git(repo, ['show', `${source}:${file}`])
    const version = checkVersion(tag, JSON.parse(show('packages/vector-ui/package.json')).version)
    releaseNotes(version, { vector: show('packages/vector/CHANGELOG.md'), ui: show('packages/vector-ui/CHANGELOG.md') })

    if (!isAncestor(repo, source, 'refs/remotes/origin/main')) {
        throw new Error(`${tag} (${source}) is not on origin/main; a release only comes from main.`)
    }

    return { version, source, distTag: distTag(version), prerelease: isPrerelease(version) }
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
        throw new Error(`${PREFIX}/${DIST}/${MANIFEST} is the bundle ${manifest.version} from ${manifest.source}, not ${version} from ${source}; build it with scripts/build-hosted-bundle.mjs --version=${version} at the tagged commit.`)
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
 * Reads the mirror (unauthenticated: it is public), decides with
 * publicationPlan(), re-checks the source tag, and pushes what is left
 * in one atomic push — or, on a dry run, says what it would push.
 */
export function publishMirror(repo, { tag, source, split: splitCommit, release, mirror = MIRROR, dryRun = false, token, log = console.log }) {
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

    // Only while the source tag is still the commit this run released. A
    // rehearsal may run before the tag exists; it says so and writes nothing.
    const current = remoteTagCommit(repo, 'origin', tag)

    if (current !== source && dryRun) {
        log(`origin's ${tag} resolves to ${current ?? 'nothing'}, not ${source}; a real run would refuse to write the mirror until it does.`)
    } else if (current !== source) {
        throw new Error(`origin's ${tag} now resolves to ${current ?? 'nothing'}, not ${source}, the commit this run released; refusing to write the mirror.`)
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

/**
 * Whether @kinetis/vector-ui@version is on npm: `npm view` prints the
 * version when it is, nothing when the package exists without it, and
 * fails with E404 before the first publish. Any other failure is not an
 * answer, and fails.
 */
export function npmPublished(version, view = () => spawnSync('npm', ['view', `@kinetis/vector-ui@${version}`, 'version'], { encoding: 'utf8' })) {
    const result = view()

    if (result.status === 0) {
        return result.stdout.trim().split('\n').map(line => line.trim().replace(/^'|'$/g, '')).includes(version)
    }

    if (/\bE404\b/.test(result.stderr ?? '')) {
        return false
    }

    throw new Error(`Could not ask npm whether @kinetis/vector-ui@${version} exists: ${(result.stderr ?? '').trim() || `npm exited ${result.status}`}`)
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

export function main(argv) {
    // Out of the environment before any child starts; only the push gets it.
    const token = process.env[DEPLOY_CREDENTIAL_VARIABLE]
    delete process.env[DEPLOY_CREDENTIAL_VARIABLE]

    const [command, ...rest] = argv
    const opts = options(rest)
    const dryRun = opts['dry-run'] === true || opts['dry-run'] === 'true'

    switch (command) {
        case 'verify': {
            const result = verify(root, { tag: required(opts, 'tag'), ref: opts.ref, dryRun, head: opts.head })
            output({ version: result.version, source: result.source, dist_tag: result.distTag, prerelease: result.prerelease })
            break
        }
        case 'notes': {
            const version = required(opts, 'version')
            process.stdout.write(releaseNotes(version, {
                vector: readFileSync(path.join(root, 'packages/vector/CHANGELOG.md'), 'utf8'),
                ui: readFileSync(path.join(root, 'packages/vector-ui/CHANGELOG.md'), 'utf8'),
            }))
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
            console.log(published ? `@kinetis/vector-ui@${version} is already on npm.` : `@kinetis/vector-ui@${version} is not on npm yet.`)
            output({ published })
            break
        }
        case 'publish-mirror':
            publishMirror(root, {
                tag: required(opts, 'tag'),
                source: sha(required(opts, 'source'), '--source'),
                split: sha(required(opts, 'split'), '--split'),
                release: sha(required(opts, 'release'), '--release'),
                mirror: opts.mirror ?? MIRROR,
                dryRun,
                token,
            })
            break
        default:
            throw new Error('Usage: release.mjs verify|notes|split|release-commit|npm-published|publish-mirror [--option=value ...]')
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
