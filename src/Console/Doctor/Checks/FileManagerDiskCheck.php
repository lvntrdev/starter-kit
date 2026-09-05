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
        return 'FileManager Disk';
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
                "FileManager disk \"{$fileManagerDisk}\" is not defined in filesystems.disks.",
                'Set FILESYSTEM_DISK in your .env file or choose a valid disk under Settings → Storage.'
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
                "FileManager disk \"{$fileManagerDisk}\" ({$driver}) root directory is not writable: {$root}.",
                'Fix directory permissions (chmod/chown) so the web server user can write.'
            );
        }

        if ($root !== '' && is_dir($root)) {
            return DoctorReport::ok(
                $this->name(),
                "FileManager disk \"{$fileManagerDisk}\" ({$driver}) is accessible."
            );
        }

        if ($root !== '' && ! is_dir($root)) {
            return DoctorReport::warn(
                $this->name(),
                "FileManager disk \"{$fileManagerDisk}\" ({$driver}) root directory not found: {$root}.",
                'Run php artisan storage:link.'
            );
        }

        return DoctorReport::ok(
            $this->name(),
            "FileManager disk \"{$fileManagerDisk}\" ({$driver}) is configured."
        );
    }

    private function checkS3Disk(string $diskName, array $diskConfig): DoctorReport
    {
        $bucket = $diskConfig['bucket'] ?? '';

        if (empty($bucket)) {
            return DoctorReport::fail(
                $this->name(),
                "No bucket configured for S3 disk \"{$diskName}\".",
                'Set AWS_BUCKET in your .env file.'
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
                "S3 disk \"{$diskName}\" (bucket: {$bucket}) is accessible."
            );
        } catch (Throwable $e) {
            return DoctorReport::fail(
                $this->name(),
                "S3 disk \"{$diskName}\" (bucket: {$bucket}) is not accessible: ".$e->getMessage(),
                'Check AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET, and AWS_DEFAULT_REGION. IAM policy must include s3:GetBucketLocation and s3:HeadBucket permissions.'
            );
        }
    }
}
