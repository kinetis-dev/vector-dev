<script setup lang="ts">
import type { SubmitEventPromise } from 'vuetify'
import type { VectorAction, VectorResource, VectorSelection } from '#vector/app/types/vector'

// Runs one action: the confirmation, the dialog's fields, the request,
// and what the response asks the client to do.
const props = defineProps<{
    resource: VectorResource
    action: VectorAction
    selection: VectorSelection | null
    count: number
}>()

const emit = defineEmits<{ done: [affected: number], cancel: [] }>()

const { t, noun } = useVectorT()
const appConfig = useAppConfig()
const notifications = useVectorNotifications()
const client = useVectorResource(() => props.resource.slug)

const values = reactive<Record<string, unknown>>({})
const serverErrors = reactive<Record<string, string[]>>({})
const formError = ref<string | null>(null)
const running = ref(false)

for (const field of props.action.fields) {
    values[field.name] = initialValue(field)
}

const subject = computed(() => props.action.standalone
    ? ''
    : props.selection?.all
        ? t('actions.subjectAll', { label: noun(props.resource.labelSingular), count: props.count })
        : t('actions.subjectCount', { count: props.count, label: noun(props.count === 1 ? props.resource.labelSingular : props.resource.label) }))

async function run(event: SubmitEventPromise) {
    const { valid } = await event
    if (!valid) return

    running.value = true
    formError.value = null
    for (const key of Object.keys(serverErrors)) delete serverErrors[key]

    try {
        const response = await client.runAction(props.action.name, props.action.standalone ? null : props.selection, { ...values })

        switch (response.type) {
            case 'message':
                notifications.success(response.message ?? t('actions.done', { label: props.action.label }))
                break
            case 'danger':
                formError.value = response.message ?? t('actions.declined')
                return
            case 'redirect':
                if (response.message) notifications.success(response.message)
                emit('done', response.affected)
                await navigateTo(`${appConfig.vector.basePath}${response.url ?? ''}`)
                return
            case 'url':
                if (response.message) notifications.success(response.message)
                if (response.url && import.meta.client) window.open(response.url, '_blank', 'noopener')
                break
            case 'download':
                notifications.success(t('actions.downloadStarted'))
                break
            case 'job':
                notifications.success(response.message ?? t('actions.queued', { label: props.action.label }))
                emit('done', 0)
                await navigateTo(`${appConfig.vector.basePath}/jobs/${response.jobId}`)
                return
        }

        emit('done', response.affected)
    } catch (error) {
        formError.value = applyViolations(error, values, serverErrors)
    } finally {
        running.value = false
    }
}
</script>

<template>
    <v-dialog :model-value="true" max-width="560" persistent @update:model-value="emit('cancel')">
        <v-form validate-on="blur lazy" @submit.prevent="run">
            <v-card>
                <v-card-title class="d-flex align-center ga-2">
                    <v-icon v-if="action.icon" :icon="action.icon" />
                    {{ action.label }}
                </v-card-title>
                <v-card-text>
                    <p v-if="action.confirmation" class="mb-4">{{ action.confirmation }}</p>
                    <i18n-t v-else-if="!action.standalone" keypath="actions.runOn" tag="p" class="mb-4" scope="global">
                        <template #action><strong>{{ action.label }}</strong></template>
                        <template #subject>{{ subject }}</template>
                    </i18n-t>
                    <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" :text="formError" />
                    <VxFieldsForm v-if="action.fields.length" :fields="action.fields" :values="values" :server-errors="serverErrors" :resource="resource.slug" @clear="(name: string) => delete serverErrors[name]" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="running" @click="emit('cancel')">{{ t('common.cancel') }}</v-btn>
                    <v-btn type="submit" :color="action.destructive ? 'error' : 'primary'" :loading="running">{{ action.label }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-form>
    </v-dialog>
</template>
