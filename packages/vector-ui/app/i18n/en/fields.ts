// The fields area's strings; see common.ts for words every area shares.
export default {
    cell: {
        loading: 'Loading {label}',
    },
    keyValue: {
        key: 'Key',
        value: 'Value',
        entry: 'Entry {n}',
        add: 'Add entry',
        remove: 'Remove entry {n}',
        more: '+{count} more',
        repeated: 'Used more than once: {keys}. The last one is kept.',
    },
    repeater: {
        add: 'Add {label}',
        remove: 'Remove {label} {n}',
        row: '{label} {n}',
        count: '{count} of at most {max}',
        unavailable: 'You may not see these rows.',
    },
    relation: {
        placeholder: 'Type to search',
        dependsFirst: 'Choose {label} first',
    },
    upload: {
        storageKey: 'The storage key of the file.',
        tooLarge: 'The file is larger than {size} MB.',
        rejected: 'The file {message}',
        uploaded: 'Uploaded {name} ({size} KB)',
        theFile: 'the file',
        upTo: 'up to {size} MB',
        preview: '{label} preview',
        remove: 'Remove {label}',
    },
    rules: {
        maxLength: 'At most {max} characters',
        minLength: 'At least {min} characters',
        email: 'Enter a valid e-mail address',
        url: 'Enter a valid URL',
        greaterThan: 'Must be greater than {limit}',
        greaterThanOrEqual: 'Must be at least {limit}',
        lessThan: 'Must be less than {limit}',
        lessThanOrEqual: 'Must be at most {limit}',
        invalidFormat: 'Invalid format',
        notAllowed: 'Not an allowed value',
    },
}
