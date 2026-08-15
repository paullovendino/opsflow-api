<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FileStorageService
{
    public const TEMP_DIRECTORY = 'tmp/uploads';

    /**
     * Stage an uploaded file on the private local disk. Does not create a File row
     * and does not attach to any model.
     *
     * @return array{disk: string, path: string, file_name: string, mime_type: string, extension: string, size: int}
     */
    public function stage(UploadedFile $file, ?string $forcedExtension = null): array
    {
        $extension = $forcedExtension ?? $this->safeExtension($file);
        $originalName = $this->sanitizeOriginalName((string) $file->getClientOriginalName(), $extension);
        $generated = Str::lower(Str::random(16)).'.'.$extension;
        $directory = self::TEMP_DIRECTORY;
        $path = $directory.'/'.$generated;

        $stored = $file->storeAs($directory, $generated, File::DISK_LOCAL);

        if ($stored === false || $stored !== $path) {
            throw new RuntimeException('Unable to stage uploaded file.');
        }

        $size = Storage::disk(File::DISK_LOCAL)->exists($path)
            ? (int) Storage::disk(File::DISK_LOCAL)->size($path)
            : (int) $file->getSize();

        return [
            'disk' => File::DISK_LOCAL,
            'path' => $path,
            'file_name' => $originalName,
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'extension' => $extension,
            'size' => $size,
        ];
    }

    /**
     * Copy a staged upload into permanent storage. Does not create a File row.
     *
     * @param  array{disk: string, path: string, file_name: string, mime_type: string, extension: string, size: int}  $staged
     * @return array{disk: string, path: string, file_name: string, mime_type: string, extension: string, size: int}
     */
    public function writePermanent(
        array $staged,
        string $directory,
        string $disk = File::DISK_PUBLIC,
    ): array {
        $generated = Str::lower(Str::random(16)).'.'.$staged['extension'];
        $permanentPath = trim($directory, '/').'/'.$generated;

        $this->copyAcrossDisks($staged['disk'], $staged['path'], $disk, $permanentPath);

        $size = Storage::disk($disk)->exists($permanentPath)
            ? (int) Storage::disk($disk)->size($permanentPath)
            : $staged['size'];

        return [
            'disk' => $disk,
            'path' => $permanentPath,
            'file_name' => $staged['file_name'],
            'mime_type' => $staged['mime_type'],
            'extension' => $staged['extension'],
            'size' => $size,
        ];
    }

    /**
     * @param  array{disk: string, path: string, file_name: string, mime_type: string, extension: string, size: int}  $stored
     */
    public function createRecord(
        array $stored,
        Model $attachable,
        string $collection,
    ): File {
        return File::query()->create([
            'file_name' => $stored['file_name'],
            'file_path' => $stored['path'],
            'disk' => $stored['disk'],
            'mime_type' => $stored['mime_type'],
            'extension' => $stored['extension'],
            'size' => $stored['size'],
            'collection' => $collection,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
        ]);
    }

    /**
     * @param  array{disk: string, path: string}|null  $staged
     */
    public function discard(?array $staged): void
    {
        if ($staged === null || ($staged['path'] ?? '') === '') {
            return;
        }

        $disk = $staged['disk'] ?? File::DISK_LOCAL;
        Storage::disk($disk)->delete($staged['path']);
    }

    public function deleteStoredPath(string $disk, string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    public function delete(File $file): void
    {
        $disk = $file->disk ?: File::DISK_PUBLIC;
        $path = $file->file_path;

        $file->delete();

        if ($path !== '') {
            Storage::disk($disk)->delete($path);
        }
    }

    public function publicUrl(File $file): string
    {
        return Storage::disk($file->disk)->url($file->file_path);
    }

    private function copyAcrossDisks(string $fromDisk, string $fromPath, string $toDisk, string $toPath): void
    {
        if ($fromDisk === $toDisk) {
            if (! Storage::disk($fromDisk)->move($fromPath, $toPath)) {
                throw new RuntimeException('Unable to move staged file into permanent storage.');
            }

            return;
        }

        $stream = Storage::disk($fromDisk)->readStream($fromPath);

        if ($stream === false) {
            throw new RuntimeException('Unable to read staged file.');
        }

        try {
            $written = Storage::disk($toDisk)->writeStream($toPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true && ! Storage::disk($toDisk)->exists($toPath)) {
            throw new RuntimeException('Unable to write permanent file.');
        }
    }

    private function safeExtension(UploadedFile $file): string
    {
        $guess = strtolower((string) $file->guessExtension());
        $client = strtolower((string) $file->getClientOriginalExtension());
        $extension = $guess !== '' ? $guess : $client;

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        if ($extension === '' || str_contains($extension, '/') || str_contains($extension, '\\')) {
            throw new RuntimeException('Unable to determine a safe file extension.');
        }

        return $extension;
    }

    private function sanitizeOriginalName(string $original, string $extension): string
    {
        $basename = basename(str_replace(["\0", '\\'], ['', '/'], $original));
        $basename = trim($basename);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return 'upload.'.$extension;
        }

        return mb_substr($basename, 0, 255);
    }
}
