/**
 * The admin's translation function for a component or composable:
 * `const { t } = useVectorT()`, then `t('list.selected', { count }, count)`.
 * The same function is `vt()` anywhere else.
 */
export function useVectorT() {
    return { t: vt, locale: useNuxtApp().$vectorI18n.global.locale }
}
