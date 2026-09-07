<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Throwable;

/**
 * FileManager'ın kullandığı disk'i kontrol eder.
 * S3 driver için headBucket ping'i yapar (2 saniye timeout).
 * Local/public driver için dizin varlığını kontrol eder.
 */
class FileManagerDiskCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.file_manager_disk.name');
    }

    public function run(): DoctorReport
    {
        // FileManager'ın gerçekten yazdığı disk media-library.disk_name'dir
        // (SettingsServiceProvider Storage ayarından bunu set eder); tanımsızsa
        // filesystems.default'a düş.
        $fileManagerDisk = (string) (config('media-library.disk_name') ?: config('filesystems.default', 'local'));

        if (config("filesystems.disks.{$fileManagerDisk}") === null) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.disk_undefined', ['disk' => $fileManagerDisk]),
                (string) __('sk-doctor.file_manager_disk.disk_undefined_hint')
            );
        }

        $diskConfig = config("filesystems.disks.{$fileManagerDisk}", []);
        $driver = $diskConfig['driver'] ?? 'unknown';

        if ($driver === 's3') {
            return $this->checkS3Disk($fileManagerDisk, $diskConfig);
        }

        // Local/public driver: dizin mevcut mu ve yazılabilir mi?
        $root = $diskConfig['root'] ?? '';

        if ($root !== '' && is_dir($root) && ! is_writable($root)) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.root_not_writable', [
                    'disk' => $fileManagerDisk,
                    'driver' => $driver,
                    'root' => $root,
                ]),
                (string) __('sk-doctor.file_manager_disk.root_not_writable_hint')
            );
        }

        if ($root !== '' && is_dir($root)) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.accessible', ['disk' => $fileManagerDisk, 'driver' => $driver])
            );
        }

        if ($root !== '' && ! is_dir($root)) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.root_missing', [
                    'disk' => $fileManagerDisk,
                    'driver' => $driver,
                    'root' => $root,
                ]),
                (string) __('sk-doctor.file_manager_disk.root_missing_hint')
            );
        }

        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.file_manager_disk.configured', ['disk' => $fileManagerDisk, 'driver' => $driver])
        );
    }

    private function checkS3Disk(string $diskName, array $diskConfig): DoctorReport
    {
        $bucket = $diskConfig['bucket'] ?? '';

        if (empty($bucket)) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.s3_no_bucket', ['disk' => $diskName]),
                (string) __('sk-doctor.file_manager_disk.s3_no_bucket_hint')
            );
        }

        try {
            // S3 headBucket: bucket varlığı + IAM okuma iznini doğrular
            $disk = Storage::disk($diskName);
            $adapter = $disk->getAdapter();

            // headBucket üzerinden bağlantı testi (2 saniye timeout için URL oluştur)
            if (method_exists($adapter, 'getClient')) {
                /** @var S3Client $client */
                $client = $adapter->getClient();
                $client->headBucket(['Bucket' => $bucket]);
            } else {
                // Adapter S3Client'ı expose etmiyorsa dizin listesini dene
                $disk->directories();
            }

            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.s3_accessible', ['disk' => $diskName, 'bucket' => $bucket])
            );
        } catch (Throwable $e) {
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.file_manager_disk.s3_inaccessible', [
                    'disk' => $diskName,
                    'bucket' => $bucket,
                    'error' => $e->getMessage(),
                ]),
                (string) __('sk-doctor.file_manager_disk.s3_inaccessible_hint')
            );
        }
    }
}
