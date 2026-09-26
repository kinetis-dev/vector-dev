<script setup lang="ts">
// The document's language follows the admin's locale, for screen readers and hyphenation.
const { locale } = useVectorT()
useHead({ htmlAttrs: { lang: () => locale.value } })
</script>

<template>
    <NuxtLayout>
        <NuxtPage />
    </NuxtLayout>
</template>
