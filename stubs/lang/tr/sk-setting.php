<?php

return [
    'title' => 'Ayarlar',
    'subtitle' => 'Uygulama yapılandırmasını yönetin.',

    'tabs' => [
        'general' => 'Genel',
        'auth' => 'Kimlik Doğrulama',
        'mail' => 'E-posta',
        'storage' => 'Depolama',
        'file_manager' => 'Dosya Yöneticisi',
        'turnstile' => 'Güvenlik',
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

    'file_manager' => [
        'title' => 'Dosya Yöneticisi Ayarları',
        'subtitle' => 'Yüklenebilir dosya boyutunu ve türlerini yapılandırın.',
    ],

    'turnstile' => [
        'title' => 'Cloudflare Turnstile',
        'subtitle' => 'Giriş, kayıt ve şifre sıfırlama formlarını CAPTCHA ile koruyun.',
        'enabled_hint' => 'Turnstile doğrulamayı aktif et',
        'site_key_label' => 'Site Anahtarı',
        'secret_key_label' => 'Gizli Anahtar',
    ],
];
