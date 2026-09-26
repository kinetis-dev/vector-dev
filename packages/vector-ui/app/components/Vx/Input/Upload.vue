<script setup lang="ts">
import type { VectorField, VectorUploaded } from '#vector/app/types/vector'

// A file or image field: pick a file, it uploads at once, and the field
// takes the key the server issued. The server detects the format from the
// bytes and enforces the size; accept and the size here only guide the
// picker. Without upload support the key is edited as text.
const props = defineProps<{ field: VectorField, rules: ((v: unknown) => true | string)[], errorMessages: string[], record?: Record<string, unknown>, resource?: string }>()
const model = defineModel<string | null>()

const { t } = useVectorT()
const { schema, resource: find } = useVectorSchema()
const client = useVectorResource(() => props.resource ?? '')
const owner = computed(() => find(props.resource ?? ''))
// The rules read the field's value (the key), not the picked file.
const keyRules = computed(() => props.rules.map(rule => () => rule(model.value)))
const uploads = computed(() => schema.value?.capabilities.uploads === true)
const fieldProps = computed(() => (Array.isArray(props.field.props) ? {} : props.field.props) as { accept?: Record<string, string>, maxBytes?: number })
const accept = computed(() => Object.keys(fieldProps.value.accept ?? {}).join(','))
const formats = computed(() => [...new Set(Object.values(fieldProps.value.accept ?? {}))].join(', '))
const maxMb = computed(() => fieldProps.value.maxBytes ? Math.round(fieldProps.value.maxBytes / 100_000) / 10 : null)
const image = computed(() => props.field.type === 'image')

const uploading = ref(false)
const uploadError = ref<string | null>(null)
const picked = ref<VectorUploaded | null>(null)
const preview = ref<string | null>(null)
const stored = computed(() => typeof props.record?.[props.field.name] === 'string' ? props.record![props.field.name] as string : null)

async function choose(files: File | File[] | null) {
    const file = Array.isArray(files) ? files[0] : files
    if (!file) return
    uploadError.value = null

    if (fieldProps.value.maxBytes && file.size > fieldProps.value.maxBytes) {
        uploadError.value = t('fields.upload.tooLarge', { size: maxMb.value })
        return
    }

    uploading.value = true
    try {
        picked.value = await client.upload(props.field.name, file)
        model.value = picked.value.key
        if (preview.value) URL.revokeObjectURL(preview.value)
        preview.value = image.value ? URL.createObjectURL(file) : null
    } catch (error) {
        const failure = error as { data?: { errors?: { message: string }[] } } | null
        uploadError.value = failure?.data?.errors?.[0]?.message ? t('fields.upload.rejected', { message: failure.data.errors[0].message }) : vectorErrorMessage(error)
    } finally {
        uploading.value = false
    }
}

function clear() {
    model.value = null
    picked.value = null
    if (preview.value) URL.revokeObjectURL(preview.value)
    preview.value = null
}

onBeforeUnmount(() => { if (preview.value) URL.revokeObjectURL(preview.value) })
</script>

<template>
    <v-text-field
        v-if="!uploads"
        v-model="model"
        :label="field.label"
        :rules="rules"
        :error-messages="errorMessages"
        :hint="field.help ?? t('fields.upload.storageKey')"
        persistent-hint
        :clearable="field.nullable === true"
    />
    <div v-else class="d-flex align-center ga-3">
        <v-img v-if="preview" :src="preview" width="56" height="56" cover class="rounded flex-grow-0" :alt="t('fields.upload.preview', { label: field.label })" />
        <VxCell v-else-if="model && model === stored && record && owner" :field="field" :value="model" :record="record" :resource="owner" context="index" />
        <v-file-input
            :label="field.label"
            :accept="accept || undefined"
            :loading="uploading"
            :rules="keyRules"
            :error-messages="uploadError ? [uploadError, ...errorMessages] : errorMessages"
            :hint="picked ? t('fields.upload.uploaded', { name: picked.name ?? t('fields.upload.theFile'), size: Math.max(1, Math.round(picked.size / 1000)) }) : (field.help ?? [formats, maxMb ? t('fields.upload.upTo', { size: maxMb }) : ''].filter(Boolean).join(' · '))"
            persistent-hint
            :prepend-icon="image ? 'mdi-image-outline' : 'mdi-paperclip'"
            :clearable="false"
            @update:model-value="choose"
        />
        <v-btn v-if="model && field.nullable === true" icon="mdi-close" variant="text" size="small" :aria-label="t('fields.upload.remove', { label: field.label })" @click="clear" />
    </div>
</template>
