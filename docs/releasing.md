# Releasing

One tag releases both products at one shared version: a `vX.Y.Z` tag on
`kinetis-dev/vector-dev` publishes `packages/vector` to the mirror
[`kinetis-dev/vector`](https://github.com/kinetis-dev/vector), which
Packagist serves as `kinetis/vector`, and `packages/vector-ui` to npm as
`@kinetis/vector-ui`. The workflow is `.github/workflows/release.yml`;
every rule it applies is in `scripts/release.mjs`, tested by
`pnpm test:scripts`.

## Cutting a release

1. **Bump the version.** Set `packages/vector-ui/package.json`'s
   `version` to the release, such as `0.9.0`. It is the one place the
   shared version is written: `packages/vector/composer.json` has none,
   since Packagist reads it from the tag.
2. **Write both CHANGELOG sections.** Move what `## [Unreleased]` holds
   into a `## [0.9.0] - <date>` section in both
   `packages/vector/CHANGELOG.md` and `packages/vector-ui/CHANGELOG.md`.
   The two sections are the GitHub Release's notes; a release with
   either one missing or empty is refused before anything is built.
3. **Merge to main.** A release only comes from a commit on `main`.
4. **Rehearse.** Under *Actions → Release → Run workflow*, run it from
   `main` with the tag you are about to push, such as `v0.9.0`, and
   **dry run** on (the default). The tag does not exist yet — pushing it
   is the release — so the rehearsal runs at `main`'s head as if it were
   tagged: it checks the version and both sections, splits the package,
   builds the hosted bundle, makes the release commit, reads the mirror
   and says what it would push, and lists what npm would receive. It
   writes nothing.
5. **Tag** the commit you rehearsed:
   `git tag v0.9.0 <commit> && git push origin v0.9.0`. The push runs
   the release.

A dispatch with dry run off publishes too, but only when run from the
tag itself (*Use workflow from: v0.9.0*), so the npm environment's tag
rule and npm's provenance both see the tag.

### What the tag must be

- `vX.Y.Z` or `vX.Y.Z-<prerelease>`, such as `v0.9.0` or `v1.0.0-rc.1`;
- the tag minus its `v` equals `packages/vector-ui`'s version at the
  tagged commit;
- the tagged commit is on `origin/main`;
- both CHANGELOGs carry a non-empty `## [X.Y.Z]` section.

## What the workflow does

| Job | Does |
|---|---|
| Verify the tag | Applies the rules above, and decides the npm dist-tag. |
| Split, bundle and push the mirror | Splits `packages/vector`, builds the hosted bundle with `scripts/build-hosted-bundle.mjs --version=X.Y.Z`, makes the release commit, and pushes the mirror's `main` and tag. |
| Publish @kinetis/vector-ui | After the mirror push: `npm publish --provenance` from `packages/vector-ui`, in the `npm-release` environment. |
| Create the GitHub Release | After npm: the tag's release on `vector-dev`, its notes the two CHANGELOG sections. |

A dry run replaces the last two with a rehearsal job (`npm pack
--dry-run` and `npm publish --dry-run`), and the mirror push with a line
saying what it would push. Releases run one at a time, and a running
release is never cancelled midway. A pending one can be replaced:
GitHub keeps at most one pending run of this workflow and a newer
queued run cancels the one already waiting, so two tags pushed while a
release runs leave only the later one queued, and a rehearsal can
displace a pending real release (see [Recovery](#recovery)).

### The mirror's history

The mirror carries two kinds of commit, and keeps them apart:

- **`main` is the pure split, S.** `git subtree split
  --prefix=packages/vector` of the tagged commit. A split is derived
  from the monorepo's own commits, metadata included, so the same source
  always gives the same S, and each release's S descends from the last
  one's: `main` only ever fast-forwards. `dev-main` carries no hosted
  bundle, so `vector:install` from it publishes the migrations and says
  there is no bundle, as a source install does.
- **The tag points at the release commit, R.** One commit whose only
  parent is S and whose tree is S's plus `resources/dist/` — the hosted
  bundle and its manifest, which the monorepo itself ignores. Every
  tagged release on Packagist ships the bundle; no bundle ever enters
  `main`'s history.

R is authored and committed by `kinetis-release <noreply@kinetis.dev>`,
a disposable identity, at the source commit's own date.

### npm dist-tags

| Version | dist-tag |
|---|---|
| `0.x.y` | `next` |
| any prerelease (`-rc.1`, `-beta.2`) | `next` |
| `1.0.0` and later | `latest` |

The npm registry requires a `latest` dist-tag, so the package's very
first publish also sets `latest`, even under `--tag next`. Later 0.x
releases move only `next`, so a bare `pnpm add @kinetis/vector-ui` keeps
resolving to that first release until 1.0.0, while
`@kinetis/vector-ui@next` gets the newest. After each 0.x release,
either move `latest` by hand:

```console
npm dist-tag add @kinetis/vector-ui@0.9.1 latest
```

from a maintainer account authenticated with a token or two-factor
authentication — the workflow cannot, since trusted publishing's OIDC
credential can only publish, not change dist-tags — or accept the stale
`latest`. From 1.0.0 the workflow publishes with `latest`, and this goes
away. A prerelease is also marked as one on GitHub.

## One-time setup

**GitHub (`kinetis-dev/vector-dev`).**

- The repository secret `RELEASE_DEPLOY_TOKEN`: a fine-grained personal
  access token with *Contents: read and write* on `kinetis-dev/vector`
  only. The workflow hands it to the mirror push alone, as the password
  for the username `x-access-token`, through a credential helper that is
  removed afterwards.
- An environment named `npm-release`, under *Settings → Environments*,
  with *Deployment branches and tags* limited to the tag pattern `v*`.

**npm (`@kinetis/vector-ui`).** Trusted publishing — npm exchanging the
workflow's OIDC token for a one-time credential — can only be set up on
a package that exists, so the first publish is the exception:

1. Create a granular access token that can publish to the `@kinetis`
   scope, with a short expiry, and put it on the `npm-release`
   environment as the secret `NPM_TOKEN`.
2. Push the first tag. The publish step uses the token when `NPM_TOKEN`
   is set, and OIDC otherwise.
3. On npmjs.com, under the package's *Settings → Trusted publishing*,
   add a GitHub Actions publisher: organization `kinetis-dev`,
   repository `vector-dev`, workflow `release.yml`, environment
   `npm-release`.
4. Delete `NPM_TOKEN` from the environment, and revoke the token on npm.
5. Under the package's *Settings → Publishing access*, choose **Require
   two-factor authentication and disallow tokens**. Trusted publishing
   still works; a token no longer can.

Afterwards the token branch of the publish step can be deleted; it is
marked in the workflow.

**Packagist (`kinetis/vector`).** After the first mirror push, so the
repository has a `composer.json` to read:

1. Submit `https://github.com/kinetis-dev/vector` on packagist.org.
2. On the mirror, add the GitHub webhook Packagist's *Your profile →
   Show API Token* page describes (payload URL
   `https://packagist.org/api/github?username=<your username>`, content
   type `application/json`, the API token as the secret), so each push
   updates the package at once.

## Recovery

Re-running is safe; every step either finishes what is left or stops
before writing.

- **The mirror** is written in one atomic push of `main` and the tag.
  A rerun reads it first: a `main` already at S and a tag already
  holding this release are left alone. `main` moves only forward, under
  a lease naming the `main` it read, and the push is refused if the
  source tag has moved since the run started.
- **The hosted bundle** is not reproducible byte for byte: each Nuxt
  build draws a random build id. A rerun that rebuilds it therefore
  makes a different R; when the mirror's tag is already this release —
  its parent is S and it differs from S only under `resources/dist/` —
  the published one stands and the run carries on. A tag holding
  anything else fails the run: two contents cannot both be one version,
  and which one stands is for a person to decide.
- **npm** is skipped when the version is already published.
- **The GitHub Release** is skipped when it already exists.
- **A release shown as cancelled while pending** was replaced by a newer
  queued run before it started. Re-run it from its tag: dispatch the
  workflow with that tag and dry run off, from the tag itself (*Use
  workflow from: vX.Y.Z*), or re-run the cancelled run. Do not dispatch
  a rehearsal while a release is running or queued: it can displace the
  pending release.

Prefer *Re-run failed jobs*: it keeps the jobs that succeeded, and their
outputs, as they were. A tag pushed by mistake, before anything was
published, is fixed by deleting it on `vector-dev` and tagging again.
Once the mirror or npm has the version, publish a new version instead:
neither Packagist nor npm lets one version change.

## See also

- {doc}`contributing` — the tests and checks a release commit passes.
- {doc}`deployment` — what the hosted bundle and its manifest guarantee.
