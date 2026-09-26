<script setup lang="ts">
definePageMeta({ layout: 'vector', middleware: 'vector-auth', key: route => route.fullPath })

const route = useRoute()
const { load } = useVectorSchema()
await load()
const name = computed(() => String(route.params.name))
</script>

<template>
    <VxDashboard :name="name" />
</template>
