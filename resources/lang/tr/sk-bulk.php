<?php

return [
    // FormRequest doğrulama mesajları
    'ids_required' => 'En az bir kayıt seçilmelidir.',
    'ids_min' => 'En az bir kayıt seçilmelidir.',
    'ids_max' => 'Tek işlemde en fazla 500 kayıt işlenebilir.',
    'action_required' => 'Toplu işlem türü belirtilmelidir.',

    // Dispatcher mesajları
    'unsupported_action' => 'Desteklenmeyen toplu işlem: :action.',
    'no_authorized_items' => 'Seçili kayıtların hiçbirinde bu işlemi gerçekleştirme yetkiniz yok.',

    // Sonuç mesajı
    'result' => ':processed kayıt işlendi, :skipped atlandı, :failed başarısız oldu.',

    // Cross-page "tümünü seç" üst sınır uyarısı
    'cap_reached' => 'Seçim üst sınıra ulaştı; yalnızca ilk :max kayıt işlendi.',

    // Cross-page "tümünü seç" fail-closed snapshot koruması
    'unknown_filters' => 'Bu toplu işlem uygulanamaz: :keys filtresi tümünü seç için desteklenmiyor. Filtreyi kaldırın ya da kayıtları tek tek seçin.',
];
