// node --test scripts/*.test.mjs — the monorepo checks on fixtures.
import assert from 'node:assert/strict'
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { after, before, test } from 'node:test'
import { appReferences } from './validate-monorepo.mjs'

let dir

before(async () => {
    dir = await mkdtemp(path.join(tmpdir(), 'vector-validate-'))
    await mkdir(path.join(dir, 'vector/src'), { recursive: true })
    await mkdir(path.join(dir, 'vector/resources/migrations'), { recursive: true })
    await mkdir(path.join(dir, 'vector-ui/node_modules/x'), { recursive: true })
    await writeFile(path.join(dir, 'vector/src/Clean.php'), '<?php\nnamespace Kinetis\\Vector;\nuse Kinetis\\App\\Thing;\nclass MyApp\\Clean {}\n')
    await writeFile(path.join(dir, 'vector/src/Leak.php'), '<?php\nnamespace Kinetis\\Vector;\n\nuse App\\Domain\\Product;\n')
    await writeFile(path.join(dir, 'vector/resources/migrations/x.mysql.stub'), "<?php\n$class = 'App\\\\Migration';\n")
    await writeFile(path.join(dir, 'vector/composer.json'), '{"autoload":{"psr-4":{"Kinetis\\\\Vector\\\\":"src/"}}}')
    // installed output is not the package's own
    await writeFile(path.join(dir, 'vector-ui/node_modules/x/a.php'), '<?php use App\\X;\n')
})

after(() => rm(dir, { recursive: true, force: true }))

/** appReferences over one file written to its own directory. */
async function referencesIn(name, contents) {
    const own = await mkdtemp(path.join(tmpdir(), 'vector-validate-one-'))

    try {
        await writeFile(path.join(own, name), contents)

        return await appReferences(own)
    } finally {
        await rm(own, { recursive: true, force: true })
    }
}

test('a fully qualified App\\ is a reference', async () => {
    assert.deepEqual(await referencesIn('Use.php', '<?php\nuse \\App\\X;\n'), ['Use.php:2'])
    assert.deepEqual(await referencesIn('Class.php', '<?php\n$class = \\App\\Foo::class;\n'), ['Class.php:2'])
    assert.deepEqual(await referencesIn('New.php', '<?php\n$product = new \\App\\Domain\\Product();\n'), ['New.php:2'])
    // a fully qualified name inside a double-quoted string, its backslashes escaped
    assert.deepEqual(await referencesIn('String.php', '<?php\n$class = "\\\\App\\\\Foo";\n'), ['String.php:2'])
})

test('an App\\ autoload root in composer.json is a reference', async () => {
    const composer = JSON.stringify({ autoload: { 'psr-4': { 'App\\': 'src/' } } }, null, 4)

    assert.deepEqual(await referencesIn('composer.json', composer), ['composer.json:4'])
})

test('App inside another name is not a reference', async () => {
    assert.deepEqual(await referencesIn('Other.php', '<?php\nuse Kinetis\\App\\Thing;\nuse \\Kinetis\\App\\Thing;\nclass MyApp\\Clean {}\n$s = "Kinetis\\\\App\\\\Thing";\n'), [])
})

test('App\\ references in PHP sources and stubs, not in built output', async () => {
    assert.deepEqual((await appReferences(dir)).sort(), ['vector/resources/migrations/x.mysql.stub:2', 'vector/src/Leak.php:4'])
})
