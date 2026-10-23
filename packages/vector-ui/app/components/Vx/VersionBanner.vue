<script setup lang="ts">
// The UI is not the @kinetis/vector-ui release kinetis/vector pins: said
// once at the top of the shell, with the remedy for how it is deployed.
// Dismissed, it stays away for this browser session.
const { current, expected, visible, remedy, dismiss } = useVectorVersion()
const { t } = useVectorT()
</script>

<template>
    <v-alert
        v-if="visible"
        type="warning"
        variant="tonal"
        density="compact"
        rounded="0"
        icon="mdi-update"
        class="px-6 vx-version-banner"
        closable
        :close-label="t('shell.version.dismiss')"
        @click:close="dismiss"
    >
        {{ t('shell.version.mismatch', { current, expected: expected ?? '' }) }} {{ remedy }}
    </v-alert>
</template>
