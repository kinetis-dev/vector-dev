<script setup lang="ts">
const { t } = useVectorT()
const { pending, settle } = useConfirm()
const open = computed({ get: () => pending.value !== null, set: (value) => { if (!value) settle(false) } })
</script>

<template>
    <v-dialog v-model="open" max-width="440" persistent>
        <v-card v-if="pending">
            <v-card-title>{{ pending.title }}</v-card-title>
            <v-card-text v-if="pending.text">{{ pending.text }}</v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="settle(false)">{{ pending.cancelText ?? t('common.cancel') }}</v-btn>
                <v-btn :color="pending.destructive ? 'error' : 'primary'" @click="settle(true)">{{ pending.confirmText ?? t('common.confirm') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
