// node --test scripts/*.test.mjs — the one version policy every release tool shares.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { PINNED_MAJOR, allowedNextVersions, highestVersion, nextVersion, parseVersion, transitionProblem, versionProblem } from './version-policy.mjs'

test('a version is canonical X.Y.Z, each component without a leading zero and at most nine digits', () => {
    assert.deepEqual(parseVersion('0.9.0'), { major: 0, minor: 9, patch: 0 })
    assert.deepEqual(parseVersion('0.10.123'), { major: 0, minor: 10, patch: 123 })
    assert.deepEqual(parseVersion('999999999.999999999.999999999'), { major: 999999999, minor: 999999999, patch: 999999999 })

    for (const malformed of ['01.0.0', '0.01.0', '0.0.00', '0.9', '0.9.0.1', 'v0.9.0', '0.9.0-rc.1', '0.9.0+build', ' 0.9.0', '0.9.0 ', '0.9.0\n', '1234567890.0.0', '0.-1.0', '', null, undefined, 9]) {
        assert.equal(parseVersion(malformed), null, JSON.stringify(malformed))
    }
})

test('the major line is pinned to 0', () => {
    assert.equal(PINNED_MAJOR, 0)
    assert.equal(versionProblem('0.9.0'), null)
    assert.match(versionProblem('1.0.0'), /leaves the 0\.x line/)
    assert.match(versionProblem('0.9'), /not a canonical X\.Y\.Z version/)
    assert.match(transitionProblem('0.9.0', '1.0.0'), /leaves the 0\.x line/)
    assert.match(transitionProblem('1.0.0', '1.0.1'), /leaves the 0\.x line/)
    assert.match(transitionProblem('1.0.0', '0.1.0'), /previous version '1\.0\.0' is not on the 0\.x line/)
})

test('a move is exactly one step: the next patch or the next minor', () => {
    assert.equal(nextVersion('0.9.0', 'patch'), '0.9.1')
    assert.equal(nextVersion('0.9.4', 'minor'), '0.10.0')
    assert.deepEqual(allowedNextVersions('0.9.4'), ['0.9.5', '0.10.0'])
    assert.deepEqual(allowedNextVersions('0.9'), [])
    assert.throws(() => nextVersion('0.9.0', 'major'), /Unknown bump component "major"/)
    assert.throws(() => nextVersion('0.9', 'patch'), /Not a canonical X\.Y\.Z version/)

    assert.equal(transitionProblem('0.9.0', '0.9.1'), null)
    assert.equal(transitionProblem('0.9.4', '0.10.0'), null)
})

test('anything else is refused, and says why', () => {
    assert.match(transitionProblem('0.9.0', '0.9.2'), /jumped from 0\.9\.0 to 0\.9\.2; the only steps allowed are 0\.9\.1 or 0\.10\.0/)
    assert.match(transitionProblem('0.9.0', '0.11.0'), /jumped/)
    assert.match(transitionProblem('0.9.4', '0.10.1'), /jumped/)
    assert.match(transitionProblem('0.9.0', '0.9.0'), /unchanged/)
    assert.match(transitionProblem('0.9.1', '0.9.0'), /lower than 0\.9\.1/)
    assert.match(transitionProblem('0.10.0', '0.9.9'), /lower than 0\.10\.0/)
    assert.match(transitionProblem('0.9', '0.9.1'), /previous version '0\.9' is not a canonical/)
    assert.match(transitionProblem('0.9.0', '0.9.01'), /not a canonical/)
})

test('the highest version compares numerically and ignores what does not parse', () => {
    assert.equal(highestVersion(['0.9.5', '0.10.0', '0.2.0']), '0.10.0')
    assert.equal(highestVersion(['0.9.0', 'nightly', '0.9.10', '0.9.9']), '0.9.10')
    assert.equal(highestVersion([]), null)
    assert.equal(highestVersion(['latest']), null)
})
