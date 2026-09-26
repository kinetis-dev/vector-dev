<script setup lang="ts">
// Transient toasts. Anything in the layer pushes through
// useVectorNotifications(); Vuetify's queue shows them one at a time.
const { queue } = useVectorNotifications()
</script>

<template>
    <v-snackbar-queue v-model="queue" :timeout="4000" closable location="bottom right" />
</template>
