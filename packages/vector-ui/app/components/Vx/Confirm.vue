<script setup lang="ts">
const { t } = useVectorT()
const { pending, typed, canConfirm, settle } = useConfirm()
const open = computed({ get: () => pending.value !== null, set: (value) => { if (!value) settle(false) } })
</script>

<template>
    <v-dialog v-model="open" max-width="440" persistent>
        <v-card v-if="pending">
            <v-card-title>{{ pending.title }}</v-card-title>
            <v-card-text v-if="pending.text || pending.requireText !== undefined">
                <p v-if="pending.text">{{ pending.text }}</p>
                <!-- a typed guard: what the user types is what the caller sends; Enter confirms only once it matches -->
                <v-text-field
                    v-if="pending.requireText !== undefined"
                    v-model="typed"
                    :label="pending.requireLabel"
                    autocomplete="off"
                    spellcheck="false"
                    autofocus
                    density="compact"
                    hide-details
                    :class="{ 'mt-3': pending.text }"
                    data-field="confirm-text"
                    @keydown.enter.prevent="settle(true)"
                />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="settle(false)">{{ pending.cancelText ?? t('common.cancel') }}</v-btn>
                <v-btn :color="pending.destructive ? 'error' : 'primary'" :disabled="!canConfirm" data-action="confirm" @click="settle(true)">{{ pending.confirmText ?? t('common.confirm') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
