<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Menü
    |--------------------------------------------------------------------------
    */

    'menu' => [
        'dashboard' => 'Kontrol Paneli',
        'user_management' => 'Kullanıcı Yönetimi',
        'users' => 'Kullanıcılar',
        'roles_permissions' => 'Roller ve İzinler',
        'system' => 'Sistem',
        'activity_logs' => 'Etkinlik Kayıtları',
        'settings' => 'Ayarlar',
        'profile' => 'Profil',
        'logout' => 'Çıkış Yap',
        'developer' => 'Geliştirici',
        'api_routes' => 'API Rotaları',
        'api_docs' => 'API Dokümanları',
        'laravel_docs' => 'Laravel Dokümanları',
        'kits_docs' => 'Starter Kit Dokümanları',
    ],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    */

    'layout' => [
        'open_menu' => 'Menüyü aç',
        'expand_menu' => 'Menüyü genişlet',
        'collapse_menu' => 'Menüyü daralt',
        'light_mode' => 'Açık tema',
        'dark_mode' => 'Koyu tema',
        'notifications' => 'Bildirimler',
        'success' => 'Başarılı',
        'error' => 'Hata',
        'warning' => 'Uyarı',
        'info' => 'Bilgi',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kullanıcılar
    |--------------------------------------------------------------------------
    */

    'users' => [
        'title' => 'Kullanıcılar',
        'user' => 'Kullanıcı',
        'subtitle' => 'Kullanıcı Yönetimi',
        'create' => 'Kullanıcı Oluştur',
        'edit' => 'Kullanıcıyı Düzenle',
        'delete' => 'Kullanıcıyı Sil',
        'delete_confirm' => '":name" kullanıcısını silmek istediğinizden emin misiniz?',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roller
    |--------------------------------------------------------------------------
    */

    'roles' => [
        'title' => 'Roller',
        'role' => 'Rol',
        'subtitle' => 'Roller ve İzinler',
        'create' => 'Rol Oluştur',
        'edit' => 'Rolü Düzenle',
        'role_name_placeholder' => 'örn. editor',
        'group' => 'Grup',
        'group_placeholder' => 'Bir grup seçin...',
        'permissions' => 'İzinler',
        'users' => 'Kullanıcılar',
        'resource' => 'Kaynak',
        'no_permissions_available' => 'Henüz tanımlanmış bir izin yok.',
        'delete_confirm' => '":name" rolünü silmek istediğinizden emin misiniz?',
        'sync_permissions' => 'İzinleri Senkronize Et',

        'resources' => [
            'users' => 'Kullanıcılar',
            'roles' => 'Roller',
            'activity-logs' => 'Etkinlik Kayıtları',
            'settings' => 'Ayarlar',
            'api-routes' => 'API Rotaları',
            'api-docs' => 'API Dokümanları',
        ],

        'abilities' => [
            'create' => 'Oluştur',
            'read' => 'Görüntüle',
            'update' => 'Güncelle',
            'delete' => 'Sil',
            'import' => 'İçe Aktar',
            'export' => 'Dışa Aktar',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Etkinlik Kayıtları
    |--------------------------------------------------------------------------
    */

    'activity_logs' => [
        'title' => 'Etkinlik Kayıtları',
        'subtitle' => 'Model değişikliklerini ve kullanıcı işlemlerini görüntüleyin.',
        'detail_title' => 'Etkinlik Kaydı Detayı',
        'event' => 'Olay',
        'description' => 'Açıklama',
        'model' => 'Model',
        'model_id' => 'Model ID',
        'causer' => 'İşlemi Yapan',
        'date' => 'Tarih',
        'log_name' => 'Kayıt Adı',
        'detail' => 'Detay',
        'changes' => 'Değişiklikler',
        'field' => 'Alan',
        'old' => 'Eski',
        'new' => 'Yeni',
        'properties' => 'Özellikler',
        'event_created' => 'Oluşturuldu',
        'event_updated' => 'Güncellendi',
        'event_deleted' => 'Silindi',
        'filter_user' => 'Kullanıcı',
        'filter_role' => 'Rol',
        'filter_permission' => 'İzin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ayarlar
    |--------------------------------------------------------------------------
    */

    'settings' => [
        'title' => 'Ayarlar',
        'subtitle' => 'Uygulama yapılandırmasını yönetin.',

        'tabs' => [
            'general' => 'Genel',
            'auth' => 'Kimlik Doğrulama',
            'mail' => 'E-posta',
            'storage' => 'Depolama',
        ],

        'general' => [
            'title' => 'Genel Ayarlar',
            'subtitle' => 'Temel uygulama ayarlarını yapılandırın.',
            'languages_hint' => 'Uygulamanızın desteklediği dilleri seçin.',
            'logo' => 'Uygulama Logosu',
            'logo_hint' => 'Yan menüde ve giriş sayfasında gösterilecek bir logo yükleyin.',
            'logo_upload' => 'Logo Yükle',
            'logo_remove' => 'Kaldır',
            'logo_remove_confirm' => 'Uygulama logosunu kaldırmak istediğinizden emin misiniz?',
        ],

        'auth' => [
            'title' => 'Kimlik Doğrulama Ayarları',
            'subtitle' => 'Kimlik doğrulama özelliklerini etkinleştirin veya devre dışı bırakın.',
            'registration_hint' => 'Yeni kullanıcıların hesap açmasına izin verin.',
            'email_verification_hint' => 'Kullanıcıların kayıt sonrası e-posta adreslerini doğrulamasını zorunlu kılın.',
            'two_factor_hint' => 'Kullanıcıların ek güvenlik için iki adımlı doğrulamayı etkinleştirmesine izin verin.',
            'password_reset_hint' => 'Kullanıcıların e-posta bağlantısı ile parolalarını sıfırlamasına izin verin.',
        ],

        'mail' => [
            'title' => 'E-posta Ayarları',
            'subtitle' => 'Giden e-posta ayarlarını yapılandırın.',
            'encryption_none' => 'Yok',
            'test_title' => 'Test E-postası',
            'test_subtitle' => 'E-posta yapılandırmanızı doğrulamak için bir test e-postası gönderin.',
            'test_send' => 'Test Gönder',
        ],

        'storage' => [
            'title' => 'Medya Depolama',
            'subtitle' => 'Yüklenen medya dosyalarının nerede saklanacağını seçin.',
            'local' => 'Yerel',
            'spaces' => 'DigitalOcean Spaces',
            's3' => 'Amazon S3',
            'spaces_title' => 'DigitalOcean Spaces',
            'spaces_subtitle' => 'DigitalOcean Spaces kimlik bilgilerini ve ayarlarını yapılandırın.',
            's3_title' => 'Amazon S3',
            's3_subtitle' => 'AWS S3 kimlik bilgilerini ve ayarlarını yapılandırın.',

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profil
    |--------------------------------------------------------------------------
    */

    'profile' => [
        'title' => 'Profil',
        'subtitle' => 'Hesap ayarlarınızı yönetin.',
        'info_title' => 'Profil Bilgileri',
        'info_subtitle' => 'Adınızı ve e-posta adresinizi güncelleyin.',

        'password_title' => 'Parolayı Güncelle',
        'password_subtitle' => 'Hesabınızın güçlü bir parola kullandığından emin olun.',
        'current_password' => 'Mevcut Parola',
        'new_password' => 'Yeni Parola',
        'update_password' => 'Parolayı Güncelle',
        'password_updated' => 'Parola güncellendi.',

        'two_factor_title' => 'İki Adımlı Doğrulama',
        'two_factor_subtitle' => 'İki adımlı doğrulama ile hesabınıza ek güvenlik katmanı ekleyin.',
        'two_factor_enabled' => 'İki adımlı doğrulama etkin.',
        'two_factor_disabled' => 'İki adımlı doğrulama etkin değil. Ek bir güvenlik katmanı için etkinleştirin.',
        'two_factor_finish' => 'İki adımlı doğrulama kurulumunu tamamlayın.',
        'two_factor_scan' => 'Bu QR kodunu doğrulayıcı uygulamanızla (Google Authenticator, Authy vb.) tarayın:',
        'two_factor_manual' => 'Veya bu kurulum anahtarını manuel olarak girin:',
        'two_factor_code_label' => 'Doğrulayıcı uygulamanızdaki 6 haneli kodu girin',
        'two_factor_verify' => 'Doğrula ve Etkinleştir',
        'two_factor_cancel_setup' => 'Kurulumu İptal Et',
        'two_factor_continue' => 'Kuruluma Devam Et',
        'two_factor_expired' => 'Kurulum oturumunuzun süresi doldu. Devam etmek için lütfen parolanızı onaylayın veya kurulumu iptal edin.',
        'two_factor_recovery_info' => 'Bu kurtarma kodlarını güvenli bir yerde saklayın. Doğrulayıcı cihazınızı kaybetmeniz durumunda hesabınıza erişmek için kullanabilirsiniz.',
        'two_factor_regenerate' => 'Kurtarma Kodlarını Yeniden Oluştur',
        'two_factor_enable' => 'Etkinleştir',
        'two_factor_show_codes' => 'Kurtarma Kodlarını Göster',
        'two_factor_disable' => 'Devre Dışı Bırak',
        'confirm_password_title' => 'Parolayı Onayla',
        'confirm_password_message' => 'Devam etmeden önce lütfen parolanızı onaylayın.',

        'sessions_title' => 'Tarayıcı Oturumları',
        'sessions_subtitle' => 'Diğer tarayıcı ve cihazlardaki etkin oturumlarınızı yönetin ve kapatın.',
        'sessions_description' => 'Gerekirse, tüm cihazlarınızdaki diğer tarayıcı oturumlarınızdan çıkış yapabilirsiniz. Bazı son oturumlarınız aşağıda listelenmiştir; ancak bu liste tam olmayabilir. Hesabınızın ele geçirildiğini düşünüyorsanız parolanızı da güncellemelisiniz.',
        'sessions_this_device' => 'Bu cihaz',
        'sessions_last_active' => 'Son etkinlik :time',
        'sessions_logout' => 'Diğer Tarayıcı Oturumlarından Çıkış Yap',
        'sessions_done' => 'Tamamlandı.',
        'sessions_logout_confirm' => 'Tüm cihazlarınızdaki diğer tarayıcı oturumlarınızdan çıkış yapmak istediğinizi onaylamak için lütfen parolanızı girin.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatar
    |--------------------------------------------------------------------------
    */

    'avatar' => [
        'title' => 'Profil Fotoğrafı',
        'subtitle' => 'Hesabınızı kişiselleştirmek için bir profil fotoğrafı yükleyin.',
        'change' => 'Değiştir',
        'remove' => 'Kaldır',
        'hint' => 'JPG, PNG veya GIF. En fazla 2MB.',
        'remove_confirm' => 'Bu görseli kaldırmak istediğinizden emin misiniz?',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rotaları
    |--------------------------------------------------------------------------
    */

    'api_routes' => [
        'title' => 'API ve Servis Rotaları',
        'subtitle' => 'Tanımlı tüm API ve dahili servis uç noktalarına genel bakış.',
        'api_endpoints' => 'API Uç Noktaları',
        'api_endpoints_subtitle' => 'Genel ve kimlik doğrulamalı REST API rotaları.',
        'service_endpoints' => 'Servis Uç Noktaları',
        'service_endpoints_subtitle' => 'Frontend bileşenleri tarafından kullanılan dahili servis rotaları.',
        'method' => 'Metod',
        'uri' => 'URI',
        'name' => 'İsim',
        'action' => 'Aksiyon',
        'middleware' => 'Middleware',
        'no_routes' => 'Rota bulunamadı.',
        'open_api_docs' => 'API Dokümanlarını Aç',
        'regenerate_docs' => 'Dokümanları Yeniden Oluştur',
        'regenerate_docs_success' => 'API dokümantasyonu başarıyla yeniden oluşturuldu.',
    ],
];
