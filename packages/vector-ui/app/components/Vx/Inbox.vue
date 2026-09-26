<script setup lang="ts">
// The bell: unread count polled every 30 seconds, the latest messages in a menu.
const appConfig = useAppConfig()
const inbox = useVectorNotificationsInbox()
const open = ref(false)
const { t } = useVectorT()

onMounted(() => inbox.refresh().catch(() => undefined))
const { pause } = useIntervalFn(() => inbox.refresh().catch(() => undefined), 30000)
onUnmounted(pause)
watch(open, o => { if (o) inbox.refresh().catch(() => undefined) })

async function go(id: number, link: string | null) {
    await inbox.markRead(id)
    open.value = false
    if (link) navigateTo(`${appConfig.vector.basePath}${link}`)
}
</script>

<template>
    <v-menu v-model="open" :close-on-content-click="false" location="bottom end" width="380">
        <template #activator="{ props: activator }">
            <v-btn v-bind="activator" icon variant="text" :aria-label="t('shell.inbox.title')">
                <v-badge :content="inbox.unread.value" :model-value="inbox.unread.value > 0" color="error">
                    <v-icon icon="mdi-bell-outline" />
                </v-badge>
            </v-btn>
        </template>
        <v-card>
            <v-card-title class="d-flex align-center text-subtitle-1">
                {{ t('shell.inbox.title') }}
                <v-spacer />
                <v-btn v-if="inbox.unread.value" size="small" variant="text" @click="inbox.markAllRead()">{{ t('shell.inbox.markAllRead') }}</v-btn>
            </v-card-title>
            <v-divider />
            <v-list density="compact" max-height="420" style="overflow-y: auto">
                <v-list-item v-for="n in inbox.items.value" :key="n.id" :title="n.title" :subtitle="n.body ?? undefined" :class="{ 'bg-surface-light': !n.readAt }" @click="go(n.id, n.link)">
                    <template #prepend>
                        <v-icon :icon="{ info: 'mdi-information-outline', success: 'mdi-check-circle-outline', warning: 'mdi-alert-outline', error: 'mdi-alert-circle-outline' }[n.kind]" :color="n.kind === 'info' ? undefined : n.kind" />
                    </template>
                    <template #append>
                        <span class="text-caption text-medium-emphasis">{{ formatDateTime(n.createdAt) }}</span>
                    </template>
                </v-list-item>
                <v-list-item v-if="inbox.items.value.length === 0" :title="t('shell.inbox.empty')" disabled />
            </v-list>
        </v-card>
    </v-menu>
</template>
