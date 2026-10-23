<script setup lang="ts">
import { useTheme } from 'vuetify'
import { languageName } from '#vector/app/i18n'

const appConfig = useAppConfig()
const auth = useVectorAuthStore()
const theme = useTheme()
const drawer = ref<boolean | null>(null)
const { schema, menu, load, clear } = useVectorSchema()
const { branding, apply } = useVectorBranding()
const palette = ref<{ open: boolean } | null>(null)
const { t, noun } = useVectorT()

apply() // hosted: branding is known before the descriptor loads
await load()
apply()
watch(branding, apply)

// the user's language: the descriptor names it and the ones on offer; the app bar switches it.
// Followed on every reload of the descriptor: saving the Languages settings changes what is on offer,
// and switching off the language in use moves the user to another.
const language = useVectorLocale()
watch(() => schema.value?.i18n, i18n => language.adopt(i18n), { immediate: true })
const currentLanguage = computed(() => language.locales.value.find(l => l.code === language.current.value) ?? null)
async function chooseLanguage(code: string) {
    try {
        await language.choose(code)
    } catch (caught) {
        useVectorNotifications().error(t('shell.languageFailed', { error: vectorErrorMessage(caught) }))
        // refused — say, switched off since this menu was loaded: the descriptor says what is on offer now
        await load(true).catch(() => undefined)
    }
}

useHead({ titleTemplate: title => title ? t('shell.titleTemplate', { title, brand: branding.value.title }) : branding.value.title })

// Menu badges: counted after the shell renders, again every minute while
// the tab is visible, and after each navigation (an edit may change them).
const api = useVectorApi()
const route = useRoute()
const badges = ref<Record<string, { count: number, color: string, label: string | null }>>({})
async function loadBadges() {
    if (!import.meta.client || document.hidden) return
    try {
        badges.value = (await api<{ data: typeof badges.value }>('/vector/badges')).data
    } catch {
        // a badge is a hint; the menu works without one
    }
}
if (import.meta.client) {
    onMounted(loadBadges)
    useIntervalFn(loadBadges, 60_000)
    watch(() => route.path, loadBadges)
}
const badgeOf = (slug: string) => (badges.value[slug]?.count ?? 0) > 0 ? badges.value[slug] : undefined

function toggleTheme() {
    theme.toggle()
}

const runtime = useVectorRuntime()
auth.restore()

const impersonation = useVectorImpersonation()
// the sentence around the impersonated user's name, split so the name can stay bold
onMounted(() => impersonation.refresh().catch(() => undefined))

async function stopImpersonating() {
    try {
        await impersonation.stop()
        impersonation.impersonator.value = null
        await navigateTo(appConfig.vector.basePath || '/')
    } catch (caught) {
        useVectorNotifications().error(vectorErrorMessage(caught))
    }
}

// nobody at the keyboard for vector.idleMinutes: the session ends, and the login page says why
useVectorIdle(() => signOut('idle'))

function logout() {
    return signOut()
}

/** Ends the session: the application's, by its own logout form, or the bearer one, then the login page. */
async function signOut(reason?: 'idle') {
    if (runtime.hosted && runtime.logoutUrl) {
        // the application ends its own session: a form post carrying its CSRF token
        const form = document.createElement('form')
        form.method = 'post'
        form.action = runtime.logoutUrl
        const token = document.createElement('input')
        token.type = 'hidden'
        token.name = '_token'
        token.value = runtime.csrfToken ?? ''
        form.appendChild(token)
        document.body.appendChild(form)
        form.submit()

        return
    }

    await auth.logout() // revokes the refresh token where the application issued one
    clear()
    useVectorPreferences().clear()
    await navigateTo({
        path: `${appConfig.vector.basePath || ''}/login`,
        // back to this page after signing in again, and the reason the session ended
        query: reason === 'idle' ? { reason, redirect: useRoute().fullPath } : {},
    })
}

// Custom pages, grouped by their menu section ("Pages" when they name none).
const pages = useVectorPages()
const pageGroups = computed(() => {
    const groups = new Map<string, { name: string, title: string, icon?: string }[]>()
    for (const [name, page] of Object.entries(pages.value)) {
        const label = page.group ?? t('shell.nav.pages')
        groups.set(label, [...(groups.get(label) ?? []), { name, title: page.title, icon: page.icon }])
    }
    return [...groups.entries()].map(([label, items]) => ({ label, items }))
})
</script>

<template>
    <v-app>
        <v-navigation-drawer v-model="drawer">
            <v-list-item :title="branding.title" :subtitle="t('shell.brandSubtitle')" class="py-3">
                <template v-if="branding.logoUrl" #prepend>
                    <v-avatar rounded="0" size="36"><v-img :src="branding.logoUrl" :alt="branding.title" /></v-avatar>
                </template>
            </v-list-item>
            <v-divider />
            <v-list nav density="compact" role="navigation" :aria-label="t('shell.nav.main')">
                <v-list-item :to="appConfig.vector.basePath || '/'" prepend-icon="mdi-view-dashboard-outline" :title="t('shell.nav.dashboard')" exact />
                <v-list-item v-for="dashboard in (schema?.dashboards ?? []).filter(d => d.name !== 'main')" :key="dashboard.name" :to="`${appConfig.vector.basePath}/d/${dashboard.name}`" :prepend-icon="dashboard.icon ?? 'mdi-view-dashboard-outline'" :title="dashboard.label" />
                <template v-for="group in menu" :key="group.label">
                    <v-list-subheader>{{ group.label }}</v-list-subheader>
                    <v-list-item
                        v-for="resource in group.items"
                        :key="resource.slug"
                        :to="`${appConfig.vector.basePath}/r/${resource.slug}`"
                        :prepend-icon="resource.icon ?? 'mdi-table'"
                        :title="resource.label"
                    >
                        <template v-if="badgeOf(resource.slug)" #append>
                            <v-chip
                                size="x-small"
                                variant="flat"
                                label
                                :color="badgeOf(resource.slug)!.color"
                                :aria-label="t('shell.nav.badge', { count: badgeOf(resource.slug)!.count, label: badgeOf(resource.slug)!.label ?? noun(resource.label) })"
                            >{{ formatNumber(badgeOf(resource.slug)!.count) }}</v-chip>
                        </template>
                    </v-list-item>
                </template>
                <template v-for="group in pageGroups" :key="`pages-${group.label}`">
                    <v-list-subheader>{{ group.label }}</v-list-subheader>
                    <v-list-item
                        v-for="page in group.items"
                        :key="page.name"
                        :to="`${appConfig.vector.basePath}/p/${page.name}`"
                        :prepend-icon="page.icon ?? 'mdi-file-document-outline'"
                        :title="page.title"
                    />
                </template>
                <v-divider class="my-2" />
                <v-list-item v-if="schema?.capabilities.queue" :to="`${appConfig.vector.basePath}/jobs`" prepend-icon="mdi-progress-clock" :title="t('shell.nav.jobs')" />
                <v-list-item v-if="schema?.settings.length" :to="`${appConfig.vector.basePath}/settings`" prepend-icon="mdi-cog-outline" :title="t('shell.nav.settings')" />
            </v-list>
        </v-navigation-drawer>

        <v-app-bar flat border>
            <v-app-bar-nav-icon :aria-label="t('shell.toggleNavigation')" @click="drawer = !drawer" />
            <v-app-bar-title>{{ branding.title }}</v-app-bar-title>
            <v-spacer />
            <v-btn v-if="appConfig.vector.commandPalette" variant="text" prepend-icon="mdi-magnify" class="d-none d-sm-flex" @click="palette && (palette.open = true)">
                {{ t('common.search') }} <kbd class="ms-2 text-caption text-medium-emphasis">⌘K</kbd>
            </v-btn>
            <VxInbox />
            <v-menu v-if="language.locales.value.length > 1">
                <template #activator="{ props }">
                    <v-btn v-bind="props" icon="mdi-translate" variant="text" :aria-label="t('shell.language', { name: currentLanguage?.name ?? language.current.value })" />
                </template>
                <v-list density="compact" :aria-label="t('shell.language', { name: currentLanguage?.name ?? language.current.value })">
                    <!-- each in its own name (and script direction), with its name in the current language beneath -->
                    <v-list-item
                        v-for="locale in language.locales.value"
                        :key="locale.code"
                        :subtitle="languageName(locale.code, language.current.value, locale.name) ?? undefined"
                        :active="locale.code === language.current.value"
                        @click="chooseLanguage(locale.code)"
                    >
                        <template #title><span :lang="locale.code" :dir="locale.dir">{{ locale.name }}</span></template>
                    </v-list-item>
                </v-list>
            </v-menu>
            <v-btn icon="mdi-theme-light-dark" variant="text" :aria-label="t('shell.toggleTheme')" @click="toggleTheme" />
            <v-menu>
                <template #activator="{ props }">
                    <v-btn v-bind="props" variant="text" prepend-icon="mdi-account-circle-outline">
                        {{ auth.user?.name ?? t('shell.account') }}
                    </v-btn>
                </template>
                <v-list density="compact">
                    <v-list-item :title="t('shell.signOut')" prepend-icon="mdi-logout" @click="logout" />
                </v-list>
            </v-menu>
        </v-app-bar>

        <v-main>
            <VxVersionBanner />
            <v-alert v-if="impersonation.impersonator.value" type="warning" variant="tonal" density="compact" rounded="0" icon="mdi-account-switch-outline" class="px-6">
                <i18n-t keypath="shell.impersonation.actingAs" tag="span" scope="global">
                    <template #name><strong>{{ auth.user?.name ?? t('shell.impersonation.anotherUser') }}</strong></template>
                </i18n-t>
                <template #append>
                    <v-btn size="small" variant="flat" color="warning" @click="stopImpersonating">{{ t('shell.impersonation.stop') }}</v-btn>
                </template>
            </v-alert>
            <v-container fluid class="pa-6">
                <slot />
            </v-container>
        </v-main>

        <v-footer v-if="branding.footerText" app border class="text-caption text-medium-emphasis justify-center">{{ branding.footerText }}</v-footer>

        <VxNotifications />
        <VxConfirm />
        <VxCommandPalette v-if="appConfig.vector.commandPalette" ref="palette" />
    </v-app>
</template>

<style>
/* The current breadcrumb is not a link; Vuetify dims it as disabled, which fails AA contrast. */
.v-breadcrumbs-item--disabled {
    opacity: var(--v-medium-emphasis-opacity);
}
</style>
