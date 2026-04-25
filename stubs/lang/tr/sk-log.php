<?php

return [
    'title' => 'Log Dosyaları',
    'subtitle' => 'Laravel log dosyalarını oku ve yönet',
    'filename' => 'Dosya Adı',
    'channel' => 'Kanal',
    'channel_daily' => 'günlük',
    'channel_single' => 'tekil',
    'channel_other' => 'diğer',
    'size' => 'Boyut',
    'modified' => 'Değiştirildi',
    'active' => 'Aktif',
    'active_yes' => 'Aktif',
    'back_to_list' => 'Listeye dön',

    // Filtreler
    'level' => 'Seviye',
    'from' => 'Başlangıç',
    'to' => 'Bitiş',
    'search_messages' => 'Mesajlarda ara',
    'all_levels' => 'Tüm seviyeler',
    'apply' => 'Uygula',
    'reset' => 'Sıfırla',
    'load_more' => 'Daha fazla yükle',
    'no_entries' => 'Filtrelere uyan kayıt yok',
    'showing_n_entries' => ':count kayıt gösteriliyor',
    'eof' => 'Dosya sonu',

    // Silme
    'delete_selected' => 'Seçiliyi sil',
    'delete_confirm' => '":name" log dosyası silinsin mi? Geri alınamaz.',
    'deleted_count' => ':count log dosyası silindi.',
    'failed_count' => ':count log dosyası silinemedi:',

    // Hata sebepleri (DeleteLogFilesAction reason kodlarıyla eşleşmeli)
    'reason_invalid_filename' => 'geçersiz dosya adı',
    'reason_not_found' => 'bulunamadı',
    'reason_active_file_protected' => 'aktif dosya korumalı',
    'reason_delete_failed' => 'silme başarısız',

    // Sunucu hata anahtarları (PHP exception'larından referans verilir)
    'invalid_filename' => 'Geçersiz log dosya adı.',
    'file_not_found' => 'Log dosyası bulunamadı.',
    'active_file_protected' => 'Aktif log dosyaları silinemez.',
    'read_failed' => 'Log dosyası okunamadı.',
];
