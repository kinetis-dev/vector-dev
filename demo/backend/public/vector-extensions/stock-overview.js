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
                    data.value && h('table', { class: 'v-table', style: 'width: 100%; border-collapse: collapse' }, [
                        h('thead', h('tr', [
                            h('th', { style: 'text-align: start; padding: 8px' }, t('demo.stockOverview.category')),
                            h('th', { style: 'text-align: end; padding: 8px' }, t('demo.stockOverview.products')),
                            h('th', { style: 'text-align: end; padding: 8px' }, t('demo.stockOverview.units')),
                            h('th', { style: 'text-align: end; padding: 8px' }, t('demo.stockOverview.low', { threshold: data.value.threshold })),
                        ])),
                        h('tbody', data.value.categories.map(c => h('tr', { key: c.id }, [
                            h('td', { style: 'padding: 8px' }, h('a', { href: '#', onClick: (e) => { e.preventDefault(); props.vector.navigate(`/r/categories/${c.id}`) } }, c.name)),
                            h('td', { style: 'text-align: end; padding: 8px' }, String(c.products)),
                            h('td', { style: 'text-align: end; padding: 8px' }, String(c.units)),
                            h('td', { style: 'text-align: end; padding: 8px' }, c.low > 0 ? h(vuetify.VChip, { size: 'small', color: 'warning', variant: 'tonal', label: true }, () => String(c.low)) : '0'),
                        ]))),
                    ]),
                ])
            },
        },
    })
}
