/**
 * The admin's translation function for a component or composable:
 * `const { t } = useVectorT()`, then `t('list.selected', { count }, count)`.
 * `noun(label)` writes a resource label into a sentence in the current
 * language's case. The same functions are `vt()` and `vnoun()` anywhere else.
 */
export function useVectorT() {
    return { t: vt, noun: vnoun, locale: useNuxtApp().$vectorI18n.global.locale }
}
