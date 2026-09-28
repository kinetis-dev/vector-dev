// The shell area's strings; see common.ts for words every area shares.
export default {
    titleTemplate: '{title} · {brand}',
    brandSubtitle: 'Yönetim',
    account: 'Hesap',
    signOut: 'Oturumu kapat',
    toggleNavigation: 'Gezinmeyi aç/kapat',
    toggleTheme: 'Temayı değiştir',
    language: 'Dil: {name}',
    languageFailed: 'Dil kaydedilemedi: {error}',
    nav: {
        main: 'Ana',
        dashboard: 'Pano',
        jobs: 'İşler',
        settings: 'Ayarlar',
        pages: 'Sayfalar',
        badge: '{count} {label}',
    },
    page: {
        unknown: 'Bilinmeyen sayfa',
        loading: 'Sayfa yükleniyor',
    },
    impersonation: {
        actingAs: 'Şu anda {name} olarak işlem yapıyorsunuz.',
        anotherUser: 'başka bir kullanıcı',
        stop: 'Kimliğine bürünmeyi bitir',
        noAccess: '{name} yönetim paneline erişemiyor, bu yüzden onun gözünden görülecek bir şey yok. Kimliğine bürünme sonlandırıldı.',
        noToken: 'Uygulama belirteç veya yeniden yükleme olmadan yanıt verdi; hiçbir şey değişmedi.',
    },
    palette: {
        placeholder: 'Kayıt ve kaynak ara…',
        records: 'Kayıtlar',
        resources: 'Kaynaklar',
        noMatch: 'Eşleşen kayıt yok',
    },
    inbox: {
        title: 'Bildirimler',
        markAllRead: 'Tümünü okundu işaretle',
        empty: 'Henüz bir şey yok',
    },
    login: {
        pageTitle: 'Oturum aç · {brand}',
        subtitle: 'Yönetim paneline oturum açın',
        email: 'E-posta',
        password: 'Parola',
        submit: 'Oturum aç',
        invalidEmail: 'Geçerli bir e-posta adresi girin',
        welcome: 'Tekrar hoş geldiniz, {name}.',
        signedOutIdle: '{minutes} dakika boyunca işlem yapılmadığı için oturumunuz kapatıldı. Kaldığınız yerden devam etmek için yeniden giriş yapın.',
    },
    home: {
        title: 'Pano',
    },
}
