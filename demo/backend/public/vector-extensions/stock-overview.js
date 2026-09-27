// A runtime custom page (§4.15 tier 2): plain JavaScript, no build. It
// registers "Stock overview" at /p/stock-overview, in the Catalog menu
// section, and reads the application's own endpoint through the admin's
// API client, so the same credentials and access policy apply.
export default function ({ vue, vuetify, definePage, defineMessages, t }) {
    const { h, ref, onMounted } = vue

    defineMessages('en', { demo: { stockOverview: { refresh: 'Refresh', category: 'Category', products: 'Active products', units: 'Units in stock', low: 'At or below {threshold}' } } })

    definePage('stock-overview', {
        title: 'Stock overview',
        icon: 'mdi-warehouse',
        group: 'Catalog',
        component: {
            props: { vector: { type: Object, required: true } },
            setup(props) {
                const data = ref(null)
                const loading = ref(false)

                async function load() {
                    loading.value = true
                    try {
                        data.value = await props.vector.api('/vector-demo/stock-overview')
                    } catch (error) {
                        props.vector.notify.error(String(error?.data?.error ?? error))
                    } finally {
                        loading.value = false
                    }
                }

                onMounted(load)

                return () => h('div', [
                    h(vuetify.VBtn, { prependIcon: 'mdi-refresh', variant: 'tonal', loading: loading.value, class: 'mb-4', onClick: load }, () => t('demo.stockOverview.refresh')),
                    // VTable renders Vuetify's wrapper and a real <table> inside it: its
                    // `.v-table` class belongs on that wrapper, never on the table itself
                    data.value && h(vuetify.VTable, { hover: true }, () => [
                        h('thead', h('tr', [
                            h('th', { class: 'text-start' }, t('demo.stockOverview.category')),
                            h('th', { class: 'text-end' }, t('demo.stockOverview.products')),
                            h('th', { class: 'text-end' }, t('demo.stockOverview.units')),
                            h('th', { class: 'text-end' }, t('demo.stockOverview.low', { threshold: data.value.threshold })),
                        ])),
                        h('tbody', data.value.categories.map(c => h('tr', { key: c.id }, [
                            h('td', h('a', { href: '#', onClick: (e) => { e.preventDefault(); props.vector.navigate(`/r/categories/${c.id}`) } }, c.name)),
                            // numbers right-aligned in figures of one width, so the columns line up
                            h('td', { class: 'text-end', style: 'font-variant-numeric: tabular-nums' }, String(c.products)),
                            h('td', { class: 'text-end', style: 'font-variant-numeric: tabular-nums' }, String(c.units)),
                            h('td', { class: 'text-end' }, c.low > 0 ? h(vuetify.VChip, { size: 'small', color: 'warning', variant: 'tonal', label: true }, () => String(c.low)) : '0'),
                        ]))),
                    ]),
                ])
            },
        },
    })
}
