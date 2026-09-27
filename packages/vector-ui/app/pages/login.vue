<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'

definePageMeta({ layout: 'default' })

const appConfig = useAppConfig()
const auth = useVectorAuthStore()
const route = useRoute()
const notifications = useVectorNotifications()
const { t } = useVectorT()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

const rules = {
    required: (value: string) => value.trim() !== '' || t('common.required'),
    email: (value: string) => /.+@.+\..+/.test(value) || t('shell.login.invalidEmail'),
}

const runtime = useVectorRuntime()
useHead({ title: () => t('shell.login.pageTitle', { brand: appConfig.vector.brand.title }) })

if (runtime.hosted && runtime.loginUrl && import.meta.client) {
    window.location.assign(runtime.loginUrl) // the application owns sign-in in hosted mode
}

auth.restore()

if (auth.isAuthenticated) {
    await navigateTo(appConfig.vector.basePath || '/', { replace: true })
}

async function submit(event: SubmitEventPromise) {
    const { valid } = await event

    if (!valid) {
        return
    }

    submitting.value = true
    error.value = null

    try {
        await auth.login(email.value, password.value)

        // The credentials can be right for an account the admin refuses (no
        // access, 403): the descriptor is the first thing the admin needs, so
        // it is loaded here, for this identity, and a refusal ends the sign-in
        // with the server's reason instead of a welcome.
        try {
            await useVectorSchema().load(true)
        } catch (refused) {
            await auth.logout()
            throw refused
        }

        notifications.success(t('shell.login.welcome', { name: auth.user?.name ?? email.value }))

        // only a path of this app: `//host`, `/\host` and anything with a scheme are not places to go back to
        const wanted = typeof route.query.redirect === 'string' ? route.query.redirect : ''
        const redirect = /^\/(?![/\\])/.test(wanted) ? wanted : (appConfig.vector.basePath || '/')
        await navigateTo(redirect, { replace: true })
    } catch (caught) {
        error.value = vectorErrorMessage(caught)
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <v-container class="fill-height" fluid>
        <v-row justify="center" align="center">
            <v-col cols="12" sm="8" md="4">
                <v-card class="pa-4">
                    <v-card-title class="text-h5">{{ appConfig.vector.brand.title }}</v-card-title>
                    <v-card-subtitle>{{ t('shell.login.subtitle') }}</v-card-subtitle>
                    <v-card-text>
                        <v-form validate-on="blur lazy" @submit.prevent="submit">
                            <v-text-field
                                v-model="email"
                                :label="t('shell.login.email')"
                                type="email"
                                autocomplete="username"
                                :rules="[rules.required, rules.email]"
                                prepend-inner-icon="mdi-email-outline"
                            />
                            <v-text-field
                                v-model="password"
                                :label="t('shell.login.password')"
                                type="password"
                                autocomplete="current-password"
                                :rules="[rules.required]"
                                prepend-inner-icon="mdi-lock-outline"
                            />
                            <v-alert v-if="error" type="error" variant="tonal" class="mb-4" :text="error" />
                            <v-btn type="submit" color="primary" block :loading="submitting">{{ t('shell.login.submit') }}</v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>
