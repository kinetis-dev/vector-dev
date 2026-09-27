// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'الإدارة',
    account: 'الحساب',
    signOut: 'تسجيل الخروج',
    toggleNavigation: 'إظهار التنقل أو إخفاؤه',
    toggleTheme: 'تبديل السمة',
    language: 'اللغة: {name}',
    languageFailed: 'تعذّر حفظ اللغة: {error}',
    nav: {
        main: 'الرئيسية',
        dashboard: 'لوحة المعلومات',
        jobs: 'المهام',
        settings: 'الإعدادات',
        pages: 'الصفحات',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'صفحة غير معروفة',
        loading: 'جارٍ تحميل الصفحة',
    },
    impersonation: {
        actingAs: 'أنت تتصرف باسم {name}.',
        anotherUser: 'مستخدم آخر',
        stop: 'إيقاف انتحال الهوية',
        noAccess: 'ليس لدى {name} صلاحية الوصول إلى لوحة الإدارة، لذا لا يوجد ما يمكن رؤيته باسمه. تم إيقاف انتحال الهوية.',
        noToken: 'استجاب التطبيق دون رمز مميز أو إعادة تحميل، لذا لم يتغير شيء.',
    },
    palette: {
        placeholder: 'البحث في السجلات والموارد…',
        records: 'السجلات',
        resources: 'الموارد',
        noMatch: 'لا توجد سجلات مطابقة',
    },
    inbox: {
        title: 'الإشعارات',
        markAllRead: 'تعليم الكل كمقروء',
        empty: 'لا شيء بعد',
    },
    login: {
        pageTitle: 'تسجيل الدخول · {brand}',
        subtitle: 'تسجيل الدخول إلى لوحة الإدارة',
        email: 'البريد الإلكتروني',
        password: 'كلمة المرور',
        submit: 'تسجيل الدخول',
        invalidEmail: 'يُرجى إدخال عنوان بريد إلكتروني صالح',
        welcome: 'مرحبًا بعودتك، {name}.',
    },
    home: {
        title: 'لوحة المعلومات',
    },
}
