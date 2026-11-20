// The layer's own messages, under `ai`, merged into the admin's translator
// (English; other languages fall back to it).
export default {
    ai: {
        credentials: {
            title: 'Provider keys',
            intro: 'Keys entered here are sealed with VECTOR_AI_KEY and never shown again.',
            none: 'No AI provider is configured. The operator lists them in VECTOR_AI_PROVIDERS.',
            set: 'Key set',
            unset: 'No key',
            environment: 'Managed by the environment',
            noKey: 'Takes no key',
            updated: 'Set by user {by} on {at}',
            sealedWithAnotherKey: 'Sealed with another key; re-enter it.',
            cannotStore: 'Set VECTOR_AI_KEY on the server to store provider keys.',
            enter: 'New key for {provider}',
            save: 'Save key',
            remove: 'Remove key',
            saved: 'The key of {provider} was saved.',
            removed: 'The key of {provider} was removed.',
        },
        filters: {
            label: 'Filter in plain language',
            placeholder: 'Describe the {label} you want',
            noExplanation: 'The filter below was suggested.',
            search: 'Search: {q}',
            which: '{field} “{text}”: which one?',
            apply: 'Apply',
            discard: 'Discard',
            budget: 'Today\'s AI budget is spent; try again after {time}.',
            budgetSpent: 'Today\'s AI budget is spent.',
        },
    },
}
