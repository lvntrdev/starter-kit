<?php

// src/Console/Doctor/Checks/* için mesaj karşılıkları. Key ağacı EN dosyasıyla
// birebir aynı olmalı — komutlar, dosya yolları, config anahtarları ve sınıf
// adları olduğu gibi bırakılır, geri kalan metin Türkçeye çevrilir.

return [

    // ActivityLogSecretsCheck
    'activity_log_secrets' => [
        'name' => 'Aktivite Günlüğü Sırları',
        'probe_failed' => 'Aktivite günlüğünde saklanan kimlik bilgileri kontrol edilemedi: :error',
        'probe_failed_hint' => 'Veritabanı bağlantısını kontrol edin, ardından `php artisan sk:redact-activity-secrets --dry-run --all` komutunu elle çalıştırın.',
        'no_table' => 'Bu bağlantıda aktivite günlüğü tablosu yok — kimlik bilgisi barındırabilecek hiçbir şey bulunmuyor.',
        'no_json_column' => '[:table] tablosunda JSON payload sütunu yok — kimlik bilgisinin saklanabileceği bir yer bulunmuyor.',
        'dirty_exhaustive' => '[:table] tablosunda :count satır hâlâ kimlik bilgisi (parola özeti, token veya sır) içeriyor ve aktivite günlüğü ekranından okunabiliyor.',
        'dirty_exhaustive_hint' => 'Veritabanını yedekleyin, ardından `php artisan migrate` (veya `php artisan sk:redact-activity-secrets`) komutunu çalıştırın. Kaldırma işlemi geri alınamaz.',
        'dirty_bounded' => '[:table] tablosunda en az :count satır hâlâ kimlik bilgisi (parola özeti, token veya sır) içeriyor ve aktivite günlüğü ekranından okunabiliyor. Kontrol :scanned satırdan sonra durduğu için bu bir alt sınırdır, toplam değildir.',
        'dirty_bounded_hint' => 'Veritabanını yedekleyin, ardından `php artisan migrate` (veya `php artisan sk:redact-activity-secrets`) komutunu çalıştırın. Kaldırma işlemi geri alınamaz.',
        'invalid_json' => '[:table] tablosundaki :count JSON payload çözülemedi, bu yüzden kimlik bilgisi içerip içermedikleri kontrol edilemedi.',
        'invalid_json_hint' => 'Bu satırları elle inceleyin — `php artisan sk:redact-activity-secrets --dry-run --all` komutu tüm tablo üzerindeki sayıyı raporlar.',
        'clean_exhaustive' => '[:table] tablosunda kimlik bilgisi içeren satır bulunamadı (:scanned satırın tamamı incelendi).',
        'clean_bounded' => '[:table] tablosunun ilk :scanned satırında kimlik bilgisi içeren satır bulunamadı — bu sınırlı bir kontrol, tam denetim değildir. Tam sayı için `php artisan sk:redact-activity-secrets --dry-run --all` komutunu çalıştırın.',
    ],

    // ConfigCacheCheck
    'config_cache' => [
        'name' => 'Config Önbelleği',
        'production_missing' => 'Production ortamında config önbelleği bulunamadı.',
        'production_missing_hint' => '`php artisan config:cache` komutunu çalıştırın (performans için önerilir).',
        'production_ready' => 'Config önbelleği mevcut ve production için hazır.',
        'stale_local' => 'Config önbelleği mevcut ancak ortam ":env" — config değişiklikleri yansımayabilir.',
        'stale_local_hint' => '`php artisan config:clear` komutuyla önbelleği temizleyin.',
        'not_required' => 'Ortam ":env" — config önbelleği gerekli değil.',
    ],

    // DataEncryptionKeyCheck
    'data_encryption_key' => [
        'name' => 'Veri Şifreleme Anahtarı',
        'chain_unresolved' => 'Veri şifreleme anahtar zinciri çözümlenemedi: :error',
        'chain_unresolved_hint' => '.env dosyasındaki anahtar yapılandırmasını düzeltin. Düzeltirken :previous_key değerini SİLMEYİN — zincirden kaldırılan bir anahtar geri getirilemez.',
        'no_dedicated_key' => ':primary_key tanımlı değil, bu yüzden hassas ayarlar ve 2FA sırları :app_key ile şifreleniyor; bir sunucu taşımasında çalıştırılacak `php artisan key:generate` bu değerleri okunamaz hale getirir ve bu hata sessizce gerçekleşir.',
        'no_dedicated_key_hint' => '`php artisan encryption:key` ile özel bir anahtar edinin (bu, :app_key değerini okuma zincirinde tutar, böylece hiçbir şey bozulmaz), ardından :docs_link adresini takip edin.',
        'rotation_unfinished' => 'Özel bir :primary_key kullanılıyor ancak :previous_key hâlâ en az bir anahtar içeriyor, yani rotasyon tamamlanmamış ve eski anahtarın bu uygulamayla birlikte taşınması gerekiyor.',
        'rotation_unfinished_hint' => '`php artisan encryption:rekey` komutunu, ardından `php artisan encryption:health` komutunu çalıştırın; :previous_key değerini yalnızca health kontrolü güvenli olduğunu bildirdikten sonra temizleyin.',
        'dedicated_key_active' => 'Bekleyen önceki anahtar olmadan özel bir :primary_key kullanılıyor, bu yüzden her YENİ şifrelenen değer bununla yazılıyor. Herhangi bir satırın hâlâ :app_key üzerinde olmadığını doğrulamak için, bunu döndürmeden önce `php artisan encryption:health` komutunu çalıştırın.',
    ],

    // DatabaseConnectionCheck
    'database_connection' => [
        'name' => 'Veritabanı Bağlantısı',
        'connected' => 'Bağlantı başarılı (:driver: :database).',
        'connection_failed' => 'Veritabanına bağlanılamadı: :error',
        'connection_failed_hint' => '.env dosyanızda DB_HOST, DB_DATABASE, DB_USERNAME ve DB_PASSWORD değerlerini kontrol edin.',
    ],

    // FileManagerDiskCheck
    'file_manager_disk' => [
        'name' => 'FileManager Diski',
        'disk_undefined' => 'FileManager diski ":disk", filesystems.disks içinde tanımlı değil.',
        'disk_undefined_hint' => '.env dosyanızda FILESYSTEM_DISK değerini ayarlayın veya Ayarlar → Depolama altından geçerli bir disk seçin.',
        'root_not_writable' => 'FileManager diski ":disk" (:driver) kök dizini yazılabilir değil: :root.',
        'root_not_writable_hint' => 'Web sunucusu kullanıcısının yazabilmesi için dizin izinlerini (chmod/chown) düzeltin.',
        'accessible' => 'FileManager diski ":disk" (:driver) erişilebilir.',
        'root_missing' => 'FileManager diski ":disk" (:driver) kök dizini bulunamadı: :root.',
        'root_missing_hint' => '`php artisan storage:link` komutunu çalıştırın.',
        'configured' => 'FileManager diski ":disk" (:driver) yapılandırılmış.',
        's3_no_bucket' => 'S3 diski ":disk" için bucket yapılandırılmamış.',
        's3_no_bucket_hint' => '.env dosyanızda AWS_BUCKET değerini ayarlayın.',
        's3_accessible' => 'S3 diski ":disk" (bucket: :bucket) erişilebilir.',
        's3_inaccessible' => 'S3 diski ":disk" (bucket: :bucket) erişilebilir değil: :error',
        's3_inaccessible_hint' => 'AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET ve AWS_DEFAULT_REGION değerlerini kontrol edin. IAM politikası s3:GetBucketLocation ve s3:HeadBucket izinlerini içermelidir.',
    ],

    // LogChannelCheck
    'log_channel' => [
        'name' => 'Log Kanalı',
        'single_unbounded' => 'LOG_CHANNEL=single — tüm loglar tek bir dosyaya yazılıyor ve sınırsız büyüyecek.',
        'single_unbounded_hint' => '.env dosyasında LOG_CHANNEL=daily veya LOG_CHANNEL=stack olarak ayarlayın.',
        'configured' => 'LOG_CHANNEL=":channel".',
    ],

    // LogStackCheck
    'log_stack' => [
        'name' => 'Log Stack',
        'unrotated' => 'Aktif log kanalı ":channel", rotasyonu olmayan bir "single" sürücü (:channels) üzerinden yazıyor — loglar sınırsız büyüyor.',
        'unrotated_hint_stack' => 'Otomatik log rotasyonunu etkinleştirmek için .env dosyasında LOG_STACK=daily ayarlayın.',
        'unrotated_hint_named_stack' => 'logging.channels.:channel.channels içindeki "single" üyesini "daily" ile değiştirin (LOG_STACK yalnızca framework\'ün kendi "stack" kanalını yapılandırır).',
        'unrotated_hint_default' => 'Otomatik log rotasyonunu etkinleştirmek için .env dosyasında LOG_CHANNEL=daily ayarlayın.',
        'no_unrotated' => 'Aktif log kanalı ":channel" (:channels) — kullanımda rotasyonu olmayan "single" sürücü yok.',
    ],

    // MailDriverCheck
    'mail_driver' => [
        'name' => 'Mail Sürücüsü',
        'log_array_production' => 'Mail sürücüsü production ortamında ":transport" — e-postalar gönderilemez.',
        'log_array_production_hint' => 'MAIL_MAILER=smtp ayarlayın veya Mailgun/SES gibi bir sürücü kullanın.',
        'log_array_non_production' => 'Mail sürücüsü ":transport" — production için uygun değil.',
        'log_array_non_production_hint' => 'MAIL_MAILER=smtp ayarlayın veya Mailgun/SES gibi bir sürücü kullanın.',
        'smtp_host_missing' => 'SMTP host yapılandırılmamış.',
        'smtp_host_missing_hint' => '.env dosyanızda MAIL_HOST değerini ayarlayın.',
        'smtp_unreachable' => 'SMTP sunucusuna bağlanılamadı (:host::port): :error.',
        'smtp_unreachable_hint' => 'MAIL_HOST, MAIL_PORT, MAIL_USERNAME ve MAIL_PASSWORD değerlerini kontrol edin.',
        'smtp_connected' => 'SMTP bağlantısı başarılı (:host::port).',
        'configured' => 'Mail sürücüsü ":transport" yapılandırılmış.',
    ],

    // MissingKitDependenciesCheck
    'missing_kit_dependencies' => [
        'name' => 'Kit Bağımlılıkları',
        'all_installed' => 'Kit tarafından gereken tüm paketler kurulu.',
        'missing' => 'Eksik kit bağımlılıkları: :packages.',
        'missing_hint' => '`composer update lvntr/laravel-starter-kit -W` komutunu çalıştırın.',
    ],

    // NodeVersionCheck
    'node_version' => [
        'name' => 'Node Sürümü',
        'exec_failed' => '"node -v" çalıştırılamadı: :error',
        'exec_failed_hint' => 'Frontend varlıklarını derlemek için Node.js :min_label sürümünü kurun.',
        'not_installed' => 'Node.js kurulu değil veya PATH üzerinde bulunamıyor.',
        'not_installed_hint' => 'Node.js :min_label sürümünü kurun — frontend build (vite/npm) bunu gerektirir.',
        'parse_failed' => 'Node.js sürümü çıktıdan ayrıştırılamadı: ":raw".',
        'parse_failed_hint' => '`node -v` ile Node.js kurulumunuzu doğrulayın.',
        'below_floor' => 'Node.js :version, frontend toolchain\'inin alt sınırının (Vite 7, Node :min_label gerektirir) altında.',
        'below_floor_hint' => 'Varlıkları derlemeden önce Node.js sürümünü :min_label sürümüne yükseltin (ör. nvm ile).',
        'meets_floor' => 'Node.js :version, frontend toolchain\'inin minimum gereksinimini (Node :min_label) karşılıyor.',
    ],

    // NpmBuildArtifactsCheck
    'npm_build_artifacts' => [
        'name' => 'NPM Build Çıktıları',
        'manifest_missing_production' => 'public/build/manifest.json bulunamadı — frontend build eksik.',
        'manifest_missing_production_hint' => '`npm run build` komutunu çalıştırın.',
        'manifest_missing' => 'public/build/manifest.json bulunamadı.',
        'manifest_missing_hint' => '`npm run build` veya `npm run dev` komutunu çalıştırın.',
        'manifest_invalid' => 'public/build/manifest.json geçersiz veya boş.',
        'manifest_invalid_hint' => '`npm run build` komutunu tekrar çalıştırın.',
        'present' => 'NPM build çıktıları mevcut (:count varlık).',
    ],

    // PassportKeysCheck
    'passport_keys' => [
        'name' => 'Passport Anahtarları',
        'not_installed' => 'Laravel Passport kurulu değil — anahtar kontrolü atlandı.',
        'not_installed_hint' => 'Passport kullanmıyorsanız bu uyarıyı yok sayabilirsiniz.',
        'missing' => 'Eksik Passport anahtar dosyası/dosyaları: :files.',
        'missing_hint' => '`php artisan passport:keys` komutunu çalıştırın.',
        'unreadable' => 'Okunamayan Passport anahtar dosyası/dosyaları: :files.',
        'unreadable_hint' => '`chmod 600 storage/oauth-*.key` ile izinleri düzeltin.',
        'readable' => 'Passport anahtar dosyaları mevcut ve okunabilir.',
    ],

    // PermissionResourcesDriftCheck
    'permission_resources_drift' => [
        'name' => 'İzin Matrisi',
        'package_matrix_unreadable' => 'Paketin config/permission-resources.php kopyası okunamadı.',
        'package_matrix_unreadable_hint' => 'Paket dosyalarını yeniden kurun (`composer update lvntr/laravel-starter-kit`), ardından `php artisan sk:doctor --only=permission-matrix` komutunu tekrar çalıştırın.',
        'app_matrix_missing' => 'config/permission-resources.php eksik veya boş — izin üretilemez.',
        'app_matrix_missing_hint' => '`php artisan sk:publish --tag=config` komutunu, ardından `php artisan sk:seed-permissions` komutunu çalıştırın.',
        'covered' => 'config/permission-resources.php, paketin sunduğu her kaynağı ve yeteneği kapsıyor.',
        'missing' => 'Paketin sunduğu :count izin matrisinizde eksik: :items:suffix',
        'missing_hint' => 'sk:update, config/permission-resources.php dosyasına asla dokunmaz — bu dosya size aittir. Yukarıdaki girdileri elle ekleyin, ardından `php artisan sk:seed-permissions` komutunu çalıştırın.',
    ],

    // PhpExtensionsCheck
    'php_extensions' => [
        'name' => 'PHP Eklentileri',
        'all_loaded' => 'Gerekli tüm PHP eklentileri yüklü (:extensions).',
        'missing' => 'Eksik eklentiler: :extensions.',
        'missing_hint' => 'Eklentileri php.ini dosyanızda etkinleştirin veya paket yöneticinizle kurun.',
    ],

    // QueueDriverCheck
    'queue_driver' => [
        'name' => 'Kuyruk Sürücüsü',
        'sync_production' => 'Kuyruk sürücüsü production ortamında "sync" — işler HTTP isteği içinde çalışıyor.',
        'sync_production_hint' => 'QUEUE_CONNECTION=redis veya database ayarlayın ve queue:work işlemini başlatın.',
        'sync_non_production' => 'Kuyruk sürücüsü ":driver" — production için uygun değil.',
        'sync_non_production_hint' => 'QUEUE_CONNECTION=redis veya database ayarlayın.',
        'database_active' => 'Kuyruk sürücüsü ":driver" aktif.',
        'database_error' => 'Kuyruk sürücüsü ":driver" ama veritabanı erişimi başarısız oldu: :error',
        'database_error_hint' => '`php artisan queue:table && php artisan migrate` komutunu çalıştırın.',
        'redis_active' => 'Kuyruk sürücüsü ":driver" aktif ve bağlantı başarılı.',
        'redis_error' => 'Kuyruk sürücüsü ":driver" ama Redis bağlantısı başarısız oldu: :error',
        'redis_error_hint' => 'Redis sunucusunun çalıştığından emin olun.',
        'configured' => 'Kuyruk sürücüsü ":driver" yapılandırılmış.',
    ],

    // QueueWorkerCheck
    'queue_worker' => [
        'name' => 'Kuyruk Worker\'ı',
        'sync' => 'Kuyruk sürücüsü "sync" — işler satır içinde (inline) çalışıyor; worker sürecine gerek yok.',
        'async_unverifiable' => 'Kuyruk sürücüsü ":driver" asenkron — worker\'ın canlılığı otomatik olarak doğrulanamaz.',
        'async_unverifiable_hint' => 'Bir worker\'ın çalıştığından emin olun: php artisan queue:work (production\'da Supervisor veya Horizon).',
        'horizon_unreadable' => 'Horizon kurulu ama durumu okunamadı: :error',
        'horizon_unreadable_hint' => 'Horizon\'ın kullandığı Redis bağlantısını doğrulayın, ardından php artisan horizon:status komutunu çalıştırın.',
        'horizon_no_master' => 'Horizon kurulu ama çalışan bir master supervisor yok — kuyruğa alınan işler işlenmiyor.',
        'horizon_no_master_hint' => 'Horizon\'ı başlatın: php artisan horizon (production\'da Supervisor veya bir systemd birimi).',
        'horizon_paused' => 'Horizon çalışıyor ama her master supervisor duraklatılmış — hiçbir iş işlenmiyor.',
        'horizon_paused_hint' => 'İşlemeyi devam ettirin: php artisan horizon:continue.',
        'horizon_running' => 'Horizon çalışıyor (:count master supervisor) — kuyruk işleniyor.',
        'database_table_missing' => 'Kuyruk tablosu ":table" mevcut değil — işler kalıcı hale getirilemez.',
        'database_table_missing_hint' => '`php artisan queue:table && php artisan migrate` komutunu çalıştırın.',
        'database_empty' => 'Bekleyen iş yok — worker sağlıklı görünüyor (veya kuyruk boş).',
        'database_stale' => ':count iş bekliyor; en eskisi :waited süredir bekliyor — worker çalışmıyor olabilir.',
        'database_stale_hint' => 'Kuyruk worker\'ını başlatın veya yeniden başlatın: php artisan queue:work (veya Supervisor/Horizon).',
        'database_healthy' => 'Bekleyen işler beklenen işleme penceresi içinde (en eskisi :waited süredir bekliyor).',
        'database_error' => 'Kuyruk backlog\'u kontrol edilemedi: :error',
        'database_error_hint' => 'Kuyruk veritabanı bağlantısını ve jobs tablosunu doğrulayın.',
    ],

    // RedisConnectionCheck
    'redis_connection' => [
        'name' => 'Redis Bağlantısı',
        'not_used' => 'Cache veya session Redis kullanmıyor (cache=:cache, session=:session).',
        'not_used_hint' => 'Production\'da Redis önerilir: CACHE_STORE=redis, SESSION_DRIVER=redis.',
        'connected' => 'Redis bağlantısı başarılı (:host::port).',
        'connection_failed' => 'Redis\'e bağlanılamadı: :error',
        'connection_failed_hint' => '.env dosyanızda REDIS_HOST, REDIS_PORT ve REDIS_PASSWORD değerlerini kontrol edin.',
    ],

    // ScheduleConfiguredCheck
    'schedule_configured' => [
        'name' => 'Zamanlanmış Görevler',
        'no_tasks' => 'Tanımlı zamanlanmış görev yok.',
        'no_tasks_hint' => 'Görevleri routes/console.php veya App\Console\Kernel içinde tanımlayın.',
        'never_run' => ':count zamanlanmış görev tanımlı, ancak schedule:run hiç kaydedilmemiş.',
        'never_run_hint' => 'Sistem cron girdisi eksik olabilir. Ekleyin: * * * * * php artisan schedule:run >> /dev/null 2>&1',
        'stale' => ':count zamanlanmış görev tanımlı, ancak son schedule:run :diff önceydi.',
        'stale_hint' => 'Cron durmuş olabilir. Crontab girdisinin schedule:run\'ı her dakika çalıştırdığını doğrulayın.',
        'healthy' => ':count zamanlanmış görev tanımlı. Son çalışma: :diff.',
        'error' => 'Zamanlama durumu kontrol edilemedi: :error',
        'error_hint' => 'Schedule container binding\'i doğru mu? php artisan schedule:list komutunu deneyin.',
    ],

    // StorageSymlinkCheck
    'storage_symlink' => [
        'name' => 'Storage Sembolik Linki',
        'missing' => 'public/storage sembolik linki bulunamadı.',
        'missing_hint' => '`php artisan storage:link` komutunu çalıştırın.',
        'broken' => 'public/storage kırık bir sembolik link (hedef bulunamadı).',
        'broken_hint' => '`php artisan storage:link --force` ile yeniden oluşturun.',
        'valid' => 'public/storage sembolik linki geçerli.',
    ],

    // ThemeManifestCheck
    'theme_manifest' => [
        'name' => 'Tema Manifestosu',
        'not_in_use' => 'Tema resolver kullanılmıyor (_active.css import\'u yok) — kontrol atlandı.',
        'manifest_missing' => 'resources/css/theme/_active.css eksik (tema resolver çıktısı).',
        'manifest_missing_hint' => '`npm run theme:build` veya `npm run build` komutunu çalıştırın.',
        'traversal' => '_active.css, tema dizini dışına çıkan bir @import içeriyor (../).',
        'traversal_hint' => '`npm run build` ile yeniden üretin.',
        'present' => 'Tema manifestosu mevcut (resources/css/theme/_active.css).',
    ],

    // TimezoneStorageCheck
    'timezone_storage' => [
        'name' => 'Saat Dilimi Depolama',
        'non_utc' => 'Uygulama saat dilimi :timezone; zaman damgaları UTC dışında saklanıyor ve mevcut satırlar zaten belirsiz.',
        'non_utc_hint' => 'APP_TIMEZONE=UTC ayarlayın. Görüntüleme saat dilimleri için APP_DISPLAY_TIMEZONE veya Genel/kullanıcı ayarlarını kullanın.',
        'driver_not_applicable' => 'Uygulama saat dilimi UTC; veritabanı oturum saat dilimi kontrolü :driver sürücüsü için geçerli değil.',
        'session_unverifiable' => 'Uygulama saat dilimi UTC olmasına rağmen veritabanı oturum saat dilimi doğrulanamadı: sorgu herhangi bir değer döndürmedi.',
        'session_unverifiable_hint' => 'Veritabanı bağlantısını ve config/database.php içindeki varsayılan bağlantının timezone anahtarını kontrol edin.',
        'session_error' => 'Uygulama saat dilimi UTC olmasına rağmen veritabanı oturum saat dilimi doğrulanamadı: :error',
        'session_error_hint' => 'Veritabanı bağlantısını ve config/database.php içindeki varsayılan bağlantının timezone anahtarını kontrol edin.',
        'session_mismatch' => 'Uygulama saat dilimi UTC, ancak :driver oturum saat dilimi :session_timezone. TIMESTAMP sütunları UTC olmayan bir oturum dönüşümüyle yazılıyor, bu yüzden uygulama onları tutarlı biçimde okusa da disk üzerindeki satırlar kaydırılmış durumda.',
        'session_mismatch_hint' => 'config/database.php içindeki varsayılan :driver bağlantısında \'timezone\' => \'+00:00\' ayarlayın.',
        'healthy' => 'Uygulama saat dilimi UTC ve :driver oturum saat dilimi :session_timezone; TIMESTAMP depolama oturum dönüşüm kaymaları olmadan UTC kullanıyor.',
    ],

    // UnresolvedRouteCheck
    'unresolved_route' => [
        'name' => 'Çözümlenemeyen Route\'lar',
        'all_resolved' => 'check.permission / check.resource.permission ile korunan her route bir izne çözümleniyor.',
        'found' => ':count route şu anda isimlerinden izin türetilemediği için bir uyarıyla geçiyor: :routes:suffix',
        'found_hint' => 'starter-kit.permissions.allow_unresolved varsayılanı false olduğunda bunlar REDDEDİLECEK. Her route\'a eşlenmiş bir eylemle "<kaynak>.<eylem>" adı verin, açık bir izin argümanıyla koruyun veya starter-kit.permissions.unrestricted_routes altında tanımlayın.',
    ],

    // WritableDirectoriesCheck
    'writable_directories' => [
        'name' => 'Yazılabilir Dizinler',
        'not_writable' => 'Yazılabilir olmayan dizinler: :directories.',
        'not_writable_hint' => '`chmod -R 775 storage bootstrap/cache` komutunu çalıştırın.',
        'all_writable' => 'Tüm kritik dizinler yazılabilir.',
    ],
];
