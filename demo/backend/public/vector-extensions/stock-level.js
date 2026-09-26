// A runtime UI extension (§4.15 tier 2): plain JavaScript loaded by the
// admin after boot, no Vector rebuild. It receives Vue and a curated set
// of Vuetify components from window.Vector and registers a cell for the
// field component name "stock-level".
export default function ({ vue, vuetify, defineCell }) {
    const { h, computed } = vue

    defineCell('stock-level', {
        props: { value: {}, field: {}, record: {}, resource: {}, context: {} },
        setup(props) {
            const level = computed(() => {
                const n = Number(props.value ?? 0)
                return n <= 0 ? ['error', 'Out'] : n <= 10 ? ['warning', 'Low'] : ['success', 'OK']
            })
            return () => props.value === null || props.value === undefined
                ? h('span', { class: 'text-disabled' }, '—')
                : h(vuetify.VChip, { size: 'small', color: level.value[0], variant: 'tonal', label: true }, () => `${props.value} · ${level.value[1]}`)
        },
    })
}
