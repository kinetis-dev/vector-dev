// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'ניהול',
    account: 'חשבון',
    signOut: 'התנתקות',
    toggleNavigation: 'הצגה או הסתרה של הניווט',
    toggleTheme: 'החלפת ערכת נושא',
    language: 'שפה: {name}',
    languageFailed: 'לא ניתן לשמור את השפה: {error}',
    nav: {
        main: 'ראשי',
        dashboard: 'לוח בקרה',
        jobs: 'משימות',
        settings: 'הגדרות',
        pages: 'דפים',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'דף לא מוכר',
        loading: 'טוען את הדף',
    },
    impersonation: {
        actingAs: 'הפעולות שלך מתבצעות כעת בשם {name}.',
        anotherUser: 'משתמש אחר',
        stop: 'הפסקת ההתחזות',
        noAccess: 'ל־{name} אין גישה לממשק הניהול, כך שאין מה להציג בשם משתמש זה. ההתחזות הופסקה.',
        noToken: 'האפליקציה השיבה בלי אסימון ובלי טעינה מחדש, ולכן דבר לא השתנה.',
    },
    palette: {
        placeholder: 'חיפוש רשומות ומשאבים…',
        records: 'רשומות',
        resources: 'משאבים',
        noMatch: 'אין רשומות תואמות',
    },
    inbox: {
        title: 'התראות',
        markAllRead: 'סימון הכול כנקרא',
        empty: 'אין עדיין התראות',
    },
    login: {
        pageTitle: 'התחברות · {brand}',
        subtitle: 'התחברות לממשק הניהול',
        email: 'דוא״ל',
        password: 'סיסמה',
        submit: 'התחברות',
        invalidEmail: 'יש להזין כתובת דוא״ל תקינה',
        welcome: 'שמחים לראות אותך שוב, {name}.',
    },
    home: {
        title: 'לוח בקרה',
        fieldCount: 'שדה {count} | {count} שדות',
    },
}
