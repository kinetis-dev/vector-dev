import type { Ref } from 'vue'
import type { VectorField } from '#vector/app/types/vector'
import { loadCountryNames } from '#vector/app/utils/countries'

// The static names never change, so one load per locale serves every component.
const loaded = new Map<string, Record<string, string>>()

/**
 * The country names of the UI's language, keyed by ISO 3166-1 alpha-2
 * code, empty until they load; follows a change of language.
 */
export function useVectorCountryNames(): Ref<Record<string, string>> {
    const { locale } = useVectorT()
    const names = ref<Record<string, string>>(loaded.get(locale.value) ?? {})

    watch(locale, async (code) => {
        const found = loaded.get(code) ?? await loadCountryNames(code)
        loaded.set(code, found)
        if (locale.value === code) names.value = found
    }, { immediate: true })

    return names
}

/** A country field with each option named in the UI's language, or by the server's English name until the names load. */
export function withCountryNames(field: VectorField, names: Record<string, string>): VectorField {
    return { ...field, options: (field.options ?? []).map(option => ({ ...option, label: names[String(option.value)] ?? option.label })) }
}
