// The one version-transition policy every release tool here shares:
// scripts/bump.mjs writes a bump, scripts/validate-versions.mjs holds the
// manifest to it, and scripts/release.mjs reads a product's published line
// through it — so a bump the tool writes is exactly a bump the checks
// accept. After tools/version-policy.php in kinetis.
//
// Vector stays on the 0.x line for now. Within it a product moves one step
// at a time: 0.m.p -> 0.m.(p+1) for a fix, or 0.m.p -> 0.(m+1).0 for a new
// capability or a breaking change. One step at a time is what keeps every
// version reachable: the release publishes whatever version lands on main,
// so jumping 0.9.1 -> 0.9.3 would leave 0.9.2 permanently unpublished
// while its content ships inside 0.9.3.

/**
 * Every product stays on this major line. Moving to 1.0.0 is a deliberate
 * change of this one constant, made in the pull request that bumps to it.
 */
export const PINNED_MAJOR = 0

/** The bump sizes the policy offers, smallest first. */
export const BUMP_COMPONENTS = ['patch', 'minor']

const COMPONENT = '(0|[1-9][0-9]{0,8})'
const VERSION = new RegExp(`^${COMPONENT}\\.${COMPONENT}\\.${COMPONENT}$`)

/**
 * The one parser: canonical X.Y.Z, no prerelease, no build metadata. A
 * component is decimal without a leading zero and at most nine digits, so
 * `01` or `1.0.00` is rejected rather than meaning what its canonical
 * spelling means — versions are compared as strings and published
 * verbatim — and no arithmetic here can overflow.
 *
 * @returns {{ major: number, minor: number, patch: number } | null}
 */
export function parseVersion(version) {
    const match = typeof version === 'string' ? VERSION.exec(version) : null

    return match === null ? null : { major: Number(match[1]), minor: Number(match[2]), patch: Number(match[3]) }
}

/** The single step `component` takes from `current`. */
export function nextVersion(current, component) {
    const v = parseVersion(current)

    if (v === null) {
        throw new Error(`Not a canonical X.Y.Z version: ${current}`)
    }

    switch (component) {
        case 'patch':
            return `${v.major}.${v.minor}.${v.patch + 1}`
        case 'minor':
            return `${v.major}.${v.minor + 1}.0`
        default:
            throw new Error(`Unknown bump component "${component}": expected ${BUMP_COMPONENTS.join(' or ')}.`)
    }
}

/** Every version `current` may move to, in bump-size order; empty when it does not parse. */
export function allowedNextVersions(current) {
    return parseVersion(current) === null ? [] : BUMP_COMPONENTS.map(component => nextVersion(current, component))
}

function compare(a, b) {
    return a.major - b.major || a.minor - b.minor || a.patch - b.patch
}

/** The highest canonical version among `versions`, ignoring any that do not parse; null when none does. */
export function highestVersion(versions) {
    let highest = null

    for (const version of versions) {
        const parts = parseVersion(version)

        if (parts !== null && (highest === null || compare(parts, parseVersion(highest)) > 0)) {
            highest = version
        }
    }

    return highest
}

/** Why `version` is not a version this project can carry at all, or null when it is. */
export function versionProblem(version) {
    const parts = parseVersion(version)

    if (parts === null) {
        return `version '${version}' is not a canonical X.Y.Z version`
    }

    if (parts.major !== PINNED_MAJOR) {
        return `version '${version}' leaves the ${PINNED_MAJOR}.x line; a breaking change ships as a minor bump until PINNED_MAJOR moves`
    }

    return null
}

/**
 * The policy itself: why moving from `old` to `new` is rejected, or null
 * when it is one legal step.
 */
export function transitionProblem(old, version) {
    const problem = versionProblem(version)

    if (problem !== null) {
        return problem
    }

    const oldParts = parseVersion(old)

    if (oldParts === null) {
        return `previous version '${old}' is not a canonical X.Y.Z version`
    }

    if (oldParts.major !== PINNED_MAJOR) {
        return `previous version '${old}' is not on the ${PINNED_MAJOR}.x line`
    }

    const allowed = allowedNextVersions(old)

    if (allowed.includes(version)) {
        return null
    }

    if (version === old) {
        return `version '${version}' is unchanged; a release needs a new version`
    }

    if (compare(parseVersion(version), oldParts) < 0) {
        return `version ${version} is lower than ${old}`
    }

    return `version jumped from ${old} to ${version}; the only steps allowed are ${allowed.join(' or ')}, so every version in between stays reachable`
}

/**
 * The devDependencies of a product's package.json whose changes alone need
 * no bump: test and type-check tooling, which no published artifact and no
 * hosted bundle contains. scripts/validate-versions.mjs exempts a
 * package.json change only when its sole difference is in these
 * devDependencies entries. Anything that shapes the bundle kinetis/vector
 * ships (nuxt, vuetify, vuetify-nuxt-module, sass-embedded, typescript) is
 * deliberately absent. The test-tooling group in .github/dependabot.yml
 * lists the same packages; keep the two identical.
 */
export const TEST_ONLY_DEV_DEPENDENCIES = Object.freeze([
    // The DOM vitest runs the UI's tests in; never loaded by a build.
    'happy-dom',
    // The test runner; `nuxt build` never imports it.
    'vitest',
    // Mounting helpers imported only from test files.
    '@vue/test-utils',
    // Nuxt's test environment, imported only from test files and the vitest config.
    '@nuxt/test-utils',
    // Type declarations: erased at build time, nothing reaches the output.
    '@types/node',
    // The type checker behind `typecheck`; it emits nothing (--noEmit).
    'vue-tsc',
])
