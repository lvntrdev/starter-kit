<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kimlik Doğrulama Dil Satırları
    |--------------------------------------------------------------------------
    |
    | Aşağıdaki dil satırları, kullanıcıya gösterilmesi gereken çeşitli
    | kimlik doğrulama mesajları için kullanılır. Uygulamanızın
    | gereksinimlerine göre özgürce değiştirebilirsiniz.
    |
    */

    'failed' => 'Bu bilgiler kayıtlarımızla eşleşmiyor.',
    'password' => 'Girilen parola hatalı.',
    'throttle' => 'Çok fazla giriş denemesi yapıldı. Lütfen :seconds saniye sonra tekrar deneyin.',

    'login' => [
        'title' => 'Giriş Yap',
        'heading' => 'Tekrar Hoş Geldiniz',
        'subtitle' => 'Devam etmek için lütfen bilgilerinizi girin',
        'email_label' => 'E-posta Adresi',
        'email_placeholder' => 'ornek@firma.com',
        'password_label' => 'Parola',
        'forgot_password_link' => 'Parolanızı mı unuttunuz?',
        'remember' => 'Beni 30 gün boyunca hatırla',
        'submit' => 'Giriş Yap',
        'no_account' => 'Hesabınız yok mu?',
        'create_account' => 'Hesap oluştur',
    ],

    'register' => [
        'title' => 'Kayıt Ol',
        'heading' => 'Kayıt Ol',
        'subtitle' => 'Yeni bir hesap oluşturun',
        'first_name_label' => 'Ad',
        'first_name_placeholder' => 'Ad',
        'last_name_label' => 'Soyad',
        'last_name_placeholder' => 'Soyad',
        'email_label' => 'E-posta',
        'email_placeholder' => 'ornek@email.com',
        'password_label' => 'Parola',
        'password_confirmation_label' => 'Parolayı Onayla',
        'submit' => 'Kayıt Ol',
        'has_account' => 'Zaten bir hesabınız var mı?',
        'sign_in' => 'Giriş yap',
    ],

    'forgot_password' => [
        'title' => 'Parolamı Unuttum',
        'heading' => 'Parolamı Unuttum',
        'subtitle' => 'E-posta adresinizi girin, size bir parola sıfırlama bağlantısı gönderelim.',
        'email_label' => 'E-posta',
        'email_placeholder' => 'ornek@email.com',
        'submit' => 'Parola Sıfırlama Bağlantısı Gönder',
        'back_to_login' => 'Girişe geri dön',
    ],

    'reset_password' => [
        'title' => 'Parolayı Sıfırla',
        'heading' => 'Parolayı Sıfırla',
        'subtitle' => 'Hesabınız için yeni bir parola belirleyin.',
        'email_label' => 'E-posta',
        'password_label' => 'Yeni Parola',
        'password_confirmation_label' => 'Yeni Parolayı Onayla',
        'submit' => 'Parolayı Sıfırla',
    ],

];
