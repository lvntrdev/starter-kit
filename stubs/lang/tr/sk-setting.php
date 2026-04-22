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
        'api_clients' => 'API İstemcileri',
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
        'two_factor_disable_title' => 'İki adımlı doğrulama kapatılsın mı?',
        'two_factor_disable_warning' => 'Bu ayar kapatıldığında tüm kullanıcılar için ek giriş kontrolü kaldırılır. Mevcut 2FA sırları temizlenir; özelliği tekrar açarsan ilgili kullanıcıların yeniden kurulum yapması gerekir.',
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
        'mime_categories' => [
            'images' => 'Görseller',
            'documents' => 'Dokümanlar',
            'archive' => 'Arşiv',
        ],
        'media_cards' => [
            'video' => [
                'label' => 'Video yükleme',
                'description' => 'MP4, WebM, MOV, MKV, AVI ve OGG video formatlarına izin ver.',
            ],
            'audio' => [
                'label' => 'Ses yükleme',
                'description' => 'MP3, WAV, OGG ve WebM ses formatlarına izin ver.',
            ],
        ],
    ],

    'turnstile' => [
        'title' => 'Cloudflare Turnstile',
        'subtitle' => 'Giriş, kayıt ve şifre sıfırlama formlarını CAPTCHA ile koruyun.',
        'enabled_hint' => 'Turnstile doğrulamayı aktif et',
        'site_key_label' => 'Site Anahtarı',
        'secret_key_label' => 'Gizli Anahtar',
    ],

    'postman' => [
        'title' => 'Postman Entegrasyonu',
        'subtitle' => "API Rotaları sayfasından OpenAPI belgesini Postman'e gönderebilmek için yapılandırın.",
        'api_key_label' => 'API Anahtarı',
        'api_key_hint' => "postman.co → Settings → API Keys'ten üretilen kişisel anahtar (PMAK- ile başlar).",
        'workspace_id_label' => 'Workspace ID',
        'workspace_id_hint' => "Workspace URL'indeki UUID kısmı.",
        'collection_id_label' => 'Mevcut Postman Koleksiyon UID',
        'collection_id_hint' => "Her Postman senkronizasyonundan sonra otomatik güncellenir. Manuel düzenlemeye gerek yok.",
    ],

    'apidog' => [
        'title' => 'Apidog Entegrasyonu',
        'subtitle' => 'OpenAPI belgesini mevcut bir Apidog projesine gönderir; eşleşen endpointler üzerine yazılır.',
        'access_token_label' => 'Access Token',
        'access_token_hint' => 'apidog.com → Account Settings → API Access Token üzerinden üretilen kişisel token.',
        'project_id_label' => 'Proje ID',
        'project_id_hint' => 'Apidog proje URL\'inde yer alan sayısal proje ID (…/project/<id>).',
    ],
];
