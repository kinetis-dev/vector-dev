import { fileURLToPath } from 'node:url'

// Paths inside a layer's config resolve against the consuming app, so
// anything that names a file in this package goes through this alias.
const layerDir = fileURLToPath(new URL('./', import.meta.url))

// AI for the Vector admin: @kinetis/vector-ui with this layer on top. An
// application extends this instead of @kinetis/vector-ui (standalone);
// hosted (no-build) delivery comes in a later release. Against
// a backend without kinetis/vector-ai it renders nothing of its own: every
// part of it waits for the descriptor's `capabilities.ai`.
export default defineNuxtConfig({
    extends: ['@kinetis/vector-ui'],

    $meta: {
        name: 'vector-ai',
    },

    alias: {
        '#vector-ai': layerDir,
    },
})
