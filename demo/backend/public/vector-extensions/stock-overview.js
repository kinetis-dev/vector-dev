// A runtime custom page (§4.15 tier 2): plain JavaScript, no build. It
// registers "Stock overview" at /p/stock-overview, in the Catalog menu
// section, and reads the application's own endpoint through the admin's
// API client, so the same credentials and access policy apply.
// Every string a user sees goes through t(), in English and the 16 other
// languages Vector ships; the menu group uses the same word the demo's
// translations give the Catalog resource group.
const messages = {
    en: { title: 'Stock overview', group: 'Catalog', refresh: 'Refresh', category: 'Category', products: 'Active products', units: 'Units in stock', low: 'At or below {threshold}' },
    de: { title: 'Lagerübersicht', group: 'Katalog', refresh: 'Aktualisieren', category: 'Kategorie', products: 'Aktive Produkte', units: 'Einheiten auf Lager', low: 'Höchstens {threshold}' },
    fr: { title: 'Aperçu du stock', group: 'Catalogue', refresh: 'Actualiser', category: 'Catégorie', products: 'Produits actifs', units: 'Unités en stock', low: '{threshold} ou moins' },
    nl: { title: 'Voorraadoverzicht', group: 'Catalogus', refresh: 'Vernieuwen', category: 'Categorie', products: 'Actieve producten', units: 'Eenheden op voorraad', low: 'Maximaal {threshold}' },
    it: { title: 'Panoramica scorte', group: 'Catalogo', refresh: 'Aggiorna', category: 'Categoria', products: 'Prodotti attivi', units: 'Unità in magazzino', low: 'Al massimo {threshold}' },
    es: { title: 'Resumen de existencias', group: 'Catálogo', refresh: 'Actualizar', category: 'Categoría', products: 'Productos activos', units: 'Unidades en existencia', low: '{threshold} o menos' },
    pt: { title: 'Resumo do stock', group: 'Catálogo', refresh: 'Atualizar', category: 'Categoria', products: 'Produtos ativos', units: 'Unidades em stock', low: '{threshold} ou menos' },
    'pt-BR': { title: 'Visão geral do estoque', group: 'Catálogo', refresh: 'Atualizar', category: 'Categoria', products: 'Produtos ativos', units: 'Unidades em estoque', low: '{threshold} ou menos' },
    pl: { title: 'Przegląd stanów magazynowych', group: 'Katalog', refresh: 'Odśwież', category: 'Kategoria', products: 'Aktywne produkty', units: 'Sztuk w magazynie', low: 'Co najwyżej {threshold}' },
    tr: { title: 'Stok özeti', group: 'Katalog', refresh: 'Yenile', category: 'Kategori', products: 'Etkin ürünler', units: 'Stoktaki adet', low: 'En fazla {threshold}' },
    ru: { title: 'Обзор запасов', group: 'Каталог', refresh: 'Обновить', category: 'Категория', products: 'Активные товары', units: 'Единиц на складе', low: 'Не более {threshold}' },
    uk: { title: 'Огляд запасів', group: 'Каталог', refresh: 'Оновити', category: 'Категорія', products: 'Активні товари', units: 'Одиниць на складі', low: 'Не більше {threshold}' },
    he: { title: 'סקירת מלאי', group: 'קטלוג', refresh: 'רענון', category: 'קטגוריה', products: 'מוצרים פעילים', units: 'יחידות במלאי', low: '{threshold} ומטה' },
    ar: { title: 'نظرة عامة على المخزون', group: 'الكتالوج', refresh: 'تحديث', category: 'الفئة', products: 'المنتجات النشطة', units: 'الوحدات في المخزون', low: '{threshold} أو أقل' },
    ko: { title: '재고 현황', group: '카탈로그', refresh: '새로 고침', category: '카테고리', products: '활성 상품', units: '재고 수량', low: '{threshold} 이하' },
    ja: { title: '在庫の概要', group: 'カタログ', refresh: '更新', category: 'カテゴリー', products: '有効な商品', units: '在庫数', low: '{threshold} 以下' },
    'zh-Hans': { title: '库存概览', group: '商品目录', refresh: '刷新', category: '分类', products: '在售商品', units: '库存数量', low: '不超过 {threshold}' },
}

export default function ({ vue, vuetify, definePage, defineMessages, t }) {
    const { h, ref, onMounted } = vue

    for (const [code, catalogue] of Object.entries(messages)) defineMessages(code, { demo: { stockOverview: catalogue } })

    definePage('stock-overview', {
        title: t('demo.stockOverview.title'),
        icon: 'mdi-warehouse',
        group: t('demo.stockOverview.group'),
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
