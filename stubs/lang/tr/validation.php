<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Doğrulama Dil Satırları
    |--------------------------------------------------------------------------
    |
    | Aşağıdaki dil satırları, validator sınıfı tarafından kullanılan
    | varsayılan hata mesajlarını içerir. Bu kuralların bazılarının size
    | gibi birden fazla sürümü vardır. Bu mesajları burada düzenleyebilirsiniz.
    |
    */

    'accepted' => ':attribute alanı kabul edilmelidir.',
    'accepted_if' => ':other alanı :value olduğunda :attribute alanı kabul edilmelidir.',
    'active_url' => ':attribute alanı geçerli bir URL olmalıdır.',
    'after' => ':attribute alanı :date tarihinden sonraki bir tarih olmalıdır.',
    'after_or_equal' => ':attribute alanı :date tarihine eşit veya daha sonraki bir tarih olmalıdır.',
    'alpha' => ':attribute alanı yalnızca harf içermelidir.',
    'alpha_dash' => ':attribute alanı yalnızca harf, rakam, tire ve alt çizgi içermelidir.',
    'alpha_num' => ':attribute alanı yalnızca harf ve rakam içermelidir.',
    'any_of' => ':attribute alanı geçersiz.',
    'array' => ':attribute alanı bir dizi olmalıdır.',
    'ascii' => ':attribute alanı yalnızca tek baytlık alfanümerik karakter ve sembol içermelidir.',
    'before' => ':attribute alanı :date tarihinden önceki bir tarih olmalıdır.',
    'before_or_equal' => ':attribute alanı :date tarihine eşit veya daha önceki bir tarih olmalıdır.',
    'between' => [
        'array' => ':attribute alanı :min ile :max arasında öğe içermelidir.',
        'file' => ':attribute alanı :min ile :max kilobayt arasında olmalıdır.',
        'numeric' => ':attribute alanı :min ile :max arasında olmalıdır.',
        'string' => ':attribute alanı :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':attribute alanı doğru veya yanlış olmalıdır.',
    'can' => ':attribute alanı yetkisiz bir değer içeriyor.',
    'confirmed' => ':attribute alanı doğrulaması eşleşmiyor.',
    'contains' => ':attribute alanında gerekli bir değer eksik.',
    'current_password' => 'Parola hatalı.',
    'date' => ':attribute alanı geçerli bir tarih olmalıdır.',
    'date_equals' => ':attribute alanı :date tarihine eşit bir tarih olmalıdır.',
    'date_format' => ':attribute alanı :format formatına uygun olmalıdır.',
    'decimal' => ':attribute alanı :decimal ondalık basamak içermelidir.',
    'declined' => ':attribute alanı reddedilmelidir.',
    'declined_if' => ':other alanı :value olduğunda :attribute alanı reddedilmelidir.',
    'different' => ':attribute alanı ile :other alanı farklı olmalıdır.',
    'digits' => ':attribute alanı :digits haneli olmalıdır.',
    'digits_between' => ':attribute alanı :min ile :max hane arasında olmalıdır.',
    'dimensions' => ':attribute alanının görsel boyutları geçersiz.',
    'distinct' => ':attribute alanı tekrar eden bir değere sahip.',
    'doesnt_contain' => ':attribute alanı şunlardan hiçbirini içermemelidir: :values.',
    'doesnt_end_with' => ':attribute alanı şunlardan biriyle bitmemelidir: :values.',
    'doesnt_start_with' => ':attribute alanı şunlardan biriyle başlamamalıdır: :values.',
    'email' => ':attribute alanı geçerli bir e-posta adresi olmalıdır.',
    'encoding' => ':attribute alanı :encoding ile kodlanmış olmalıdır.',
    'ends_with' => ':attribute alanı şunlardan biriyle bitmelidir: :values.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'extensions' => ':attribute alanı şu uzantılardan birine sahip olmalıdır: :values.',
    'file' => ':attribute alanı bir dosya olmalıdır.',
    'filled' => ':attribute alanı bir değere sahip olmalıdır.',
    'gt' => [
        'array' => ':attribute alanı :value öğeden fazla içermelidir.',
        'file' => ':attribute alanı :value kilobayttan büyük olmalıdır.',
        'numeric' => ':attribute alanı :value değerinden büyük olmalıdır.',
        'string' => ':attribute alanı :value karakterden uzun olmalıdır.',
    ],
    'gte' => [
        'array' => ':attribute alanı :value veya daha fazla öğe içermelidir.',
        'file' => ':attribute alanı :value kilobayta eşit veya daha büyük olmalıdır.',
        'numeric' => ':attribute alanı :value değerine eşit veya daha büyük olmalıdır.',
        'string' => ':attribute alanı :value karaktere eşit veya daha uzun olmalıdır.',
    ],
    'hex_color' => ':attribute alanı geçerli bir onaltılık renk olmalıdır.',
    'image' => ':attribute alanı bir görsel olmalıdır.',
    'in' => 'Seçilen :attribute geçersiz.',
    'in_array' => ':attribute alanı :other içinde bulunmalıdır.',
    'in_array_keys' => ':attribute alanı şu anahtarlardan en az birini içermelidir: :values.',
    'integer' => ':attribute alanı bir tam sayı olmalıdır.',
    'ip' => ':attribute alanı geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':attribute alanı geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':attribute alanı geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':attribute alanı geçerli bir JSON dizgisi olmalıdır.',
    'list' => ':attribute alanı bir liste olmalıdır.',
    'lowercase' => ':attribute alanı küçük harf olmalıdır.',
    'lt' => [
        'array' => ':attribute alanı :value öğeden az içermelidir.',
        'file' => ':attribute alanı :value kilobayttan küçük olmalıdır.',
        'numeric' => ':attribute alanı :value değerinden küçük olmalıdır.',
        'string' => ':attribute alanı :value karakterden kısa olmalıdır.',
    ],
    'lte' => [
        'array' => ':attribute alanı :value öğeden fazla içermemelidir.',
        'file' => ':attribute alanı :value kilobayta eşit veya daha küçük olmalıdır.',
        'numeric' => ':attribute alanı :value değerine eşit veya daha küçük olmalıdır.',
        'string' => ':attribute alanı :value karaktere eşit veya daha kısa olmalıdır.',
    ],
    'mac_address' => ':attribute alanı geçerli bir MAC adresi olmalıdır.',
    'max' => [
        'array' => ':attribute alanı :max öğeden fazla içermemelidir.',
        'file' => ':attribute alanı :max kilobayttan büyük olmamalıdır.',
        'numeric' => ':attribute alanı :max değerinden büyük olmamalıdır.',
        'string' => ':attribute alanı :max karakterden uzun olmamalıdır.',
    ],
    'max_digits' => ':attribute alanı :max haneden fazla içermemelidir.',
    'mimes' => ':attribute alanı şu türlerden bir dosya olmalıdır: :values.',
    'mimetypes' => ':attribute alanı şu türlerden bir dosya olmalıdır: :values.',
    'min' => [
        'array' => ':attribute alanı en az :min öğe içermelidir.',
        'file' => ':attribute alanı en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute alanı en az :min olmalıdır.',
        'string' => ':attribute alanı en az :min karakter olmalıdır.',
    ],
    'min_digits' => ':attribute alanı en az :min haneli olmalıdır.',
    'missing' => ':attribute alanı bulunmamalıdır.',
    'missing_if' => ':other alanı :value olduğunda :attribute alanı bulunmamalıdır.',
    'missing_unless' => ':other alanı :value olmadıkça :attribute alanı bulunmamalıdır.',
    'missing_with' => ':values mevcut olduğunda :attribute alanı bulunmamalıdır.',
    'missing_with_all' => ':values mevcut olduğunda :attribute alanı bulunmamalıdır.',
    'multiple_of' => ':attribute alanı :value değerinin katı olmalıdır.',
    'not_in' => 'Seçilen :attribute geçersiz.',
    'not_regex' => ':attribute alanının formatı geçersiz.',
    'numeric' => ':attribute alanı bir sayı olmalıdır.',
    'password' => [
        'letters' => ':attribute alanı en az bir harf içermelidir.',
        'mixed' => ':attribute alanı en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute alanı en az bir rakam içermelidir.',
        'symbols' => ':attribute alanı en az bir sembol içermelidir.',
        'uncompromised' => 'Girilen :attribute bir veri sızıntısında geçmiş. Lütfen farklı bir :attribute seçin.',
    ],
    'present' => ':attribute alanı mevcut olmalıdır.',
    'present_if' => ':other alanı :value olduğunda :attribute alanı mevcut olmalıdır.',
    'present_unless' => ':other alanı :value olmadıkça :attribute alanı mevcut olmalıdır.',
    'present_with' => ':values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'present_with_all' => ':values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'prohibited' => ':attribute alanı yasaklanmıştır.',
    'prohibited_if' => ':other alanı :value olduğunda :attribute alanı yasaklanmıştır.',
    'prohibited_if_accepted' => ':other alanı kabul edildiğinde :attribute alanı yasaklanmıştır.',
    'prohibited_if_declined' => ':other alanı reddedildiğinde :attribute alanı yasaklanmıştır.',
    'prohibited_unless' => ':other alanı :values içinde olmadıkça :attribute alanı yasaklanmıştır.',
    'prohibits' => ':attribute alanı :other alanının bulunmasını yasaklar.',
    'regex' => ':attribute alanının formatı geçersiz.',
    'required' => ':attribute alanı zorunludur.',
    'required_array_keys' => ':attribute alanı şunlar için girdiler içermelidir: :values.',
    'required_if' => ':other alanı :value olduğunda :attribute alanı zorunludur.',
    'required_if_accepted' => ':other alanı kabul edildiğinde :attribute alanı zorunludur.',
    'required_if_declined' => ':other alanı reddedildiğinde :attribute alanı zorunludur.',
    'required_unless' => ':other alanı :values içinde olmadıkça :attribute alanı zorunludur.',
    'required_with' => ':values mevcut olduğunda :attribute alanı zorunludur.',
    'required_with_all' => ':values mevcut olduğunda :attribute alanı zorunludur.',
    'required_without' => ':values mevcut olmadığında :attribute alanı zorunludur.',
    'required_without_all' => ':values değerlerinin hiçbiri mevcut olmadığında :attribute alanı zorunludur.',
    'same' => ':attribute alanı :other ile eşleşmelidir.',
    'size' => [
        'array' => ':attribute alanı :size öğe içermelidir.',
        'file' => ':attribute alanı :size kilobayt olmalıdır.',
        'numeric' => ':attribute alanı :size olmalıdır.',
        'string' => ':attribute alanı :size karakter olmalıdır.',
    ],
    'starts_with' => ':attribute alanı şunlardan biriyle başlamalıdır: :values.',
    'string' => ':attribute alanı bir metin olmalıdır.',
    'timezone' => ':attribute alanı geçerli bir zaman dilimi olmalıdır.',
    'unique' => ':attribute zaten alınmış.',
    'uploaded' => ':attribute yüklenemedi.',
    'uppercase' => ':attribute alanı büyük harf olmalıdır.',
    'url' => ':attribute alanı geçerli bir URL olmalıdır.',
    'ulid' => ':attribute alanı geçerli bir ULID olmalıdır.',
    'uuid' => ':attribute alanı geçerli bir UUID olmalıdır.',

    /*
    |--------------------------------------------------------------------------
    | Özel Doğrulama Dil Satırları
    |--------------------------------------------------------------------------
    |
    | Burada "attribute.rule" yapısını kullanarak öznitelikler için özel
    | doğrulama mesajları belirleyebilirsiniz. Bu, belirli bir öznitelik
    | kuralı için özel bir dil satırı belirtmeyi kolaylaştırır.
    |
    */

    'custom' => [
        'name' => [
            'regex' => ':attribute yalnızca harf, rakam ve alt çizgi içerebilir.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Özel Doğrulama Öznitelikleri
    |--------------------------------------------------------------------------
    |
    | Aşağıdaki dil satırları, "email" yerine "E-posta Adresi" gibi daha
    | okunabilir bir karşılık ile öznitelik yer tutucularımızı değiştirmek
    | için kullanılır. Bu, mesajlarımızı daha anlaşılır kılmamıza yardımcı olur.
    |
    */

    'attributes' => [
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'email' => 'E-posta',
        'password' => 'Parola',
        'password_confirmation' => 'Parola Onayı',
        'status' => 'Durum',
        'role' => 'Rol',
        'name' => 'İsim',
        'permissions' => 'İzinler',
        'permissions.*' => 'İzin',
        'gender' => 'Cinsiyet',
        'theme_color' => 'Tema Rengi',
        'identity_document' => 'Kimlik Belgesi',
        'display_name' => 'Görünen Ad',
        'avatar' => 'Avatar',
        'test_email' => 'Test E-postası',
        'role_name' => 'Rol Adı',
        'host' => 'Sunucu',
        'port' => 'Port',
        'username' => 'Kullanıcı Adı',
        'encryption' => 'Şifreleme',
        'from_address' => 'Gönderen Adresi',
        'from_name' => 'Gönderen Adı',
        'mailer' => 'Mail Sürücüsü',
        'test_recipient' => 'Alıcı E-postası',
        'app_name' => 'Uygulama Adı',
        'app_url' => 'Uygulama URL',
        'timezone' => 'Zaman Dilimi',
        'languages' => 'Diller',
        'debug' => 'Hata Ayıklama Modu',
        'registration' => 'Kayıt',
        'email_verification' => 'E-posta Doğrulama',
        'two_factor' => 'İki Adımlı Doğrulama',
        'password_reset' => 'Parola Sıfırlama',
        'access_key' => 'Erişim Anahtarı ID',
        'secret_key' => 'Gizli Erişim Anahtarı',
        'endpoint_optional' => 'Uç Nokta (Opsiyonel)',
        'url_optional' => 'URL (Opsiyonel)',
        'spaces_key' => 'Spaces Anahtarı',
        'spaces_secret' => 'Spaces Gizli Anahtarı',
        'region' => 'Bölge',
        'bucket' => 'Bucket Adı',
        'endpoint' => 'Uç Nokta',
        'cdn_url' => 'CDN URL',
        'media_disk' => 'Medya Diski',
        'spaces_url' => 'Spaces URL',
        'aws_url' => 'AWS URL',
        'spaces_endpoint' => 'Spaces Uç Noktası',
        'aws_endpoint' => 'AWS Uç Noktası',
        'aws_key' => 'AWS Anahtarı',
        'aws_secret' => 'AWS Gizli Anahtarı',
        'aws_region' => 'AWS Bölgesi',
        'spaces_region' => 'Spaces Bölgesi',
        'aws_bucket' => 'AWS Bucket',
        'spaces_bucket' => 'Spaces Bucket',
    ],

];
