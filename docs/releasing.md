# Releasing

Vector ships two products, each at its own version:

- `packages/vector`, published to the mirror
  [`kinetis-dev/vector`](https://github.com/kinetis-dev/vector), which
  Packagist serves as `kinetis/vector`;
- `packages/vector-ui`, published to npm as `@kinetis/vector-ui`.

`packages.manifest.json` at the repository root holds each product's
version and is the one place it is decided. A product is bumped in the
pull request that changes it, and merging that pull request to `main`
releases it: `.github/workflows/release.yml` publishes every version the
manifest names that is not out yet. The `vector-dev` repository itself is
never tagged; the only tag a release makes is `vX.Y.Z` on the mirror.
Every rule is in `scripts/version-policy.mjs`,
`scripts/validate-versions.mjs`, `scripts/bump.mjs` and
`scripts/release.mjs`, tested by `pnpm test:scripts`.

## Versions

A version is `X.Y.Z` with no prerelease or build suffix, and no
component has a leading zero. Both products stay on the `0.x` line: the
major version is pinned to 0 by `PINNED_MAJOR` in
`scripts/version-policy.mjs`, and moving to 1.0.0 is a deliberate change
of that constant. Within the line a product moves exactly one step at a
time:

| Bump | From | To | For |
|---|---|---|---|
| `patch` | `0.m.p` | `0.m.(p+1)` | a fix |
| `minor` | `0.m.p` | `0.(m+1).0` | a new capability, or a breaking change |

One step at a time keeps every version reachable: since every version
that lands on `main` is published, a jump from `0.9.1` to `0.9.3` would
leave `0.9.2` unpublished while its changes ship inside `0.9.3`.

A product's version covers its own path, plus any directory its manifest
entry lists under `includes`. `kinetis/vector` includes
`packages/vector-ui`: the hosted admin bundle built from it ships inside
the PHP package, in the mirror release's `resources/dist`. A change to
the UI therefore changes what `kinetis/vector` releases, as well as
`@kinetis/vector-ui`.

## Bumping in the pull request

A pull request that changes anything under `packages/vector` bumps
`vector` once. A pull request that changes anything under
`packages/vector-ui` bumps both products once, `vector-ui` for the npm
package and `vector` for the bundle it ships:

```console
node scripts/bump.mjs vector-ui minor
node scripts/bump.mjs vector patch
```

Each bump is its own choice of `patch` or `minor`, with its own
CHANGELOG entry. `bump.mjs vector-ui` prints the reminder to bump
`vector` too, but does not bump it for you.

One change needs no bump: a `package.json` under a product's path whose
only difference is in `devDependencies` entries for the test and
type-check tooling `TEST_ONLY_DEV_DEPENDENCIES` in
`scripts/version-policy.mjs` lists — `happy-dom`, `vitest`,
`@vue/test-utils`, `@nuxt/test-utils`, `@types/node` and `vue-tsc` —
added, removed or moved to another range. None of them reaches the npm
package or the hosted bundle, so a Dependabot `test-tooling` update
(that group in `.github/dependabot.yml` lists the same packages) merges
without releasing either product. Anything else in that file counts as
usual: another field, any other devDependency (`nuxt`, `vuetify`,
`vuetify-nuxt-module`, `sass-embedded` and `typescript` shape the
bundle), one of these packages moving into `dependencies` or
`peerDependencies`, or a reordered field; a whitespace-only change is
exempt. So does any other file changed in the same pull request. A
product changed only that way may still be bumped, if you choose to
release it.

`bump.mjs` moves the product's version in `packages.manifest.json` one
step. For `vector-ui` it also writes the version into
`packages/vector-ui/package.json`. `packages/vector/composer.json` has
no version, and must not get one: Packagist reads it from the mirror's
tag. It turns the product's `## [Unreleased]` CHANGELOG section into
`## [X.Y.Z] - <today>` under a fresh, empty `## [Unreleased]`. It
refuses an empty `Unreleased` section, since that section becomes the
release's notes. Write the CHANGELOG entry first, then bump.

A product needs one bump per pull request, whatever the number of
commits: the versions check measures the branch against its merge base
as one change. If another pull request bumped the same product first,
rebase onto `main` and bump again from its version.

### The versions check

`.github/workflows/versions.yml` runs
`node scripts/validate-versions.mjs` on every pull request and on every
push to `main`. Run it locally the same way. `--base=<ref>` names the
commit to compare against. Without it, the base is `GITHUB_EVENT_BEFORE`
when that is set, and `HEAD^` otherwise.

- **Consistency**, on the tree: every manifest version parses and is on
  the `0.x` line; `packages/vector-ui/package.json` carries the
  manifest's name and version; `packages/vector/composer.json` carries
  its name and no version; each product's CHANGELOG has a
  `## [<version>]` section; and each directory an `includes` lists
  exists.
- **History**, against the base (the pull request's merge base with its
  target, or what `main` pointed to before a push):
  - a product with files changed under its path, or under a directory
    its `includes` lists, has its version bumped (the failure names the
    included directory, so a UI-only change says why `vector` needs a
    bump), except a `package.json` changed only in test-tooling
    devDependencies, as above;
  - a version that moved, moved exactly one step;
  - a product with nothing changed keeps its version (an exempt
    `package.json` change counts as a change here, so it allows a bump).

  The base and the head are compared end to end, so a version skipped
  across several commits fails even when each commit moved one step.
  With no base to compare against — the repository's first commit, a
  branch's first push, or a base that has no manifest yet — the history
  check is skipped, and the output says so.

## Releasing on merge

Each push to `main` runs the release:

| Job | Does |
|---|---|
| Decide what this round publishes | Reads the manifest, `package.json` and both CHANGELOGs at the pushed commit and checks them again. Asks the mirror whether tag `v<version>` exists for `kinetis/vector`, and npm whether `@kinetis/vector-ui@<version>` exists. Puts each release's notes, its CHANGELOG section, on the run's summary page. |
| Split, bundle and push the mirror | Only when `kinetis/vector`'s version is not on the mirror yet. Splits `packages/vector`, builds the hosted bundle with `scripts/build-hosted-bundle.mjs --version=X.Y.Z` (the bundle carries `kinetis/vector`'s version, which `vector:install` checks it against), makes the release commit, and pushes the mirror's `main` and its `vX.Y.Z` tag. |
| Publish @kinetis/vector-ui | Only when `@kinetis/vector-ui`'s version is not on npm yet. Runs `npm publish --provenance --tag latest` from `packages/vector-ui`, in the `npm-release` environment. |

A push that changes no version publishes nothing and succeeds. Once the
plan has passed, the two publish jobs are independent: one product can
be published without the other, and a failure in one publish job does
not stop the other.

The plan itself is all or nothing. Any refusal fails the whole round
before anything is published, even when the other product would have
been releasable on its own: a refusal is an anomaly for a person to
look at. The plan refuses to publish:

- from a commit `origin/main` does not contain, or from any ref but
  `main`;
- a version whose CHANGELOG section is missing or empty, or whose
  `package.json` disagrees with the manifest;
- a version whose predecessor is not published. The version must be one
  step above the highest version already out. The exception is a
  product's very first release, when nothing is out yet.

Every npm release is `latest`. Versions have no prerelease form, so
nothing needs keeping off it, and no dist-tag needs moving by hand. No
GitHub Release is created: `vector-dev` has no tags to attach one to.
The notes live in the CHANGELOGs, which ship with both packages, and on
the run's summary page.

### Rehearsing

Under *Actions → Release → Run workflow*, run the workflow with **dry
run** on, which is the default. It can run from any branch, so a pull
request can be rehearsed before it merges. The rehearsal:

- makes the same plan, reading the mirror anonymously;
- splits the package, builds the bundle and makes the release commit;
- reads the mirror and says what it would push, and runs `npm pack
  --dry-run` and `npm publish --dry-run`.

It writes nothing. Two refusals of a real run are reported instead of
failing the rehearsal, so a branch can be rehearsed before it merges:

- the commit is not on `origin/main`;
- the mirror push's source check fails (`origin`'s `main` does not
  contain the commit, or the manifest there states another version).

Every other refusal fails a rehearsal as it fails a real run: a missing
predecessor, a `package.json` that disagrees with the manifest, a
missing or empty CHANGELOG section, or no manifest at the commit. A
dispatch with dry run off publishes like a push, but only when run from
`main`.

### The mirror's history

The mirror carries two kinds of commit, and keeps them apart:

- **`main` is the pure split, S.** It is `git subtree split
  --prefix=packages/vector` of the released commit. A split is derived
  from the monorepo's own commits, metadata included, so the same source
  always gives the same S. Each release's S descends from the last
  one's, so `main` only ever fast-forwards. `dev-main` carries no hosted
  bundle, so `vector:install` from it publishes the migrations and says
  there is no bundle, as a source install does.
- **The tag points at the release commit, R.** R's only parent is S, and
  its tree is S's plus `resources/dist/`: the hosted bundle and its
  manifest, which the monorepo itself ignores. Every tagged release on
  Packagist ships the bundle; no bundle ever enters `main`'s history.

R is authored and committed by `kinetis-release <noreply@kinetis.dev>`,
a disposable identity, at the source commit's own date.

## One-time setup

**GitHub (`kinetis-dev/vector-dev`).**

- The repository secret `RELEASE_DEPLOY_TOKEN` is a fine-grained
  personal access token with *Contents: read and write* on
  `kinetis-dev/vector` only. The workflow hands it, through a credential
  helper that is removed afterwards, as the password for the username
  `x-access-token`. Only two operations get it: the plan's read of the
  mirror's tags, and the mirror push.
- An environment named `npm-release`, under *Settings → Environments*,
  with *Deployment branches and tags* limited to the branch `main`.

**npm (`@kinetis/vector-ui`).** Trusted publishing, where npm exchanges
the workflow's OIDC token for a one-time credential, can only be set up
on a package that exists. The first publish is the exception:

1. Create a granular access token that can publish to the `@kinetis`
   scope, with a short expiry, and put it on the `npm-release`
   environment as the secret `NPM_TOKEN`.
2. Merge the first release to `main`. The publish step uses the token
   when `NPM_TOKEN` is set, and OIDC otherwise.
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

**Packagist (`kinetis/vector`).** Do this after the first mirror push,
so the repository has a `composer.json` to read:

1. Submit `https://github.com/kinetis-dev/vector` on packagist.org.
2. On the mirror, add the GitHub webhook that Packagist's *Your profile
   → Show API Token* page describes, so each push updates the package at
   once:
   - payload URL `https://packagist.org/api/github?username=<your username>`;
   - content type `application/json`;
   - the API token as the secret.

## Recovery

Re-running is safe; every step either finishes what is left or stops
before writing.

- **The plan** asks again what is published, so a re-run skips whatever
  an earlier attempt already got out.
- **The mirror** is written in one atomic push of `main` and the tag. A
  rerun reads it first: a `main` already at S and a tag already holding
  this release are left alone. `main` moves only forward, under a lease
  naming the `main` it read. The push is refused if `origin`'s `main` no
  longer contains the released commit (it was force-pushed past it), or
  if the manifest at that commit does not state the version being
  pushed. A `main` that has moved on to a newer version does not stop
  the push: the newer version needs this one published first.
- **The hosted bundle** is not reproducible byte for byte: each Nuxt
  build draws a random build id. A rerun that rebuilds it therefore
  makes a different R. When the mirror's tag is already this release
  (its parent is S and it differs from S only under `resources/dist/`),
  the published one stands and the run carries on. A tag holding
  anything else fails the run: two contents cannot both be one version,
  and which one stands is for a person to decide.
- **npm** is skipped when the version is already published.
- **A release shown as cancelled while pending** was replaced by a newer
  queued run before it started. Nothing is lost while the manifest
  still states that version, because the next run publishes it. If a
  later merge bumped the product again first, the next run refuses its
  version for a missing predecessor. Re-run the cancelled run, which
  publishes its own commit's versions, then re-run the refused one. Do
  not dispatch a rehearsal while a release is running or queued: it can
  displace the pending release.

Prefer *Re-run failed jobs*: it keeps the jobs that succeeded, and their
outputs, as they were. Once the mirror or npm has a version, publish a
new version rather than changing it: neither Packagist nor npm lets one
version change.

## See also

- {doc}`contributing`: the tests and checks a release commit passes.
- {doc}`deployment`: what the hosted bundle and its manifest guarantee.
