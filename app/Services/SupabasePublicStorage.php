<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Store publicly readable lesson assets in Supabase when configured. */
class SupabasePublicStorage
{
    public function enabled(): bool
    {
        return (bool) (config('services.supabase.storage.url')
            && config('services.supabase.storage.bucket')
            && config('services.supabase.storage.key'));
    }

    public function upload(UploadedFile $file, string $directory): string
    {
        if (! $this->enabled()) {
            return $file->store($directory, 'public');
        }

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$file->extension();
        $response = $this->request()
            ->withHeaders([
                'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
                'x-upsert' => 'false',
            ])
            ->withBody($file->getContent(), $file->getMimeType() ?: 'application/octet-stream')
            ->post($this->objectUrl($path));

        if (! $response->successful()) {
            throw new RuntimeException('Supabase Storage upload failed: '.($response->json('message') ?? $response->body()));
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        $path = $this->storedPath($path);
        if (! $path) {
            return;
        }

        if (! $this->enabled()) {
            Storage::disk('public')->delete($path);
            return;
        }

        $response = $this->request()->delete($this->objectUrl($path));
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Supabase Storage delete failed: '.($response->json('message') ?? $response->body()));
        }
    }

    public function url(?string $path): string
    {
        if (! $path) {
            return '';
        }

        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }

        if (! $this->enabled()) {
            return Storage::disk('public')->url($path);
        }

        return rtrim(config('services.supabase.storage.url'), '/')
            .'/storage/v1/object/public/'.rawurlencode(config('services.supabase.storage.bucket'))
            .'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function request(): PendingRequest
    {
        $key = config('services.supabase.storage.key');

        return Http::acceptJson()
            ->withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$key])
            ->timeout(60);
    }

    private function objectUrl(string $path): string
    {
        return rtrim(config('services.supabase.storage.url'), '/')
            .'/storage/v1/object/'.rawurlencode(config('services.supabase.storage.bucket'))
            .'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function storedPath(string $value): ?string
    {
        if (! preg_match('~^https?://~i', $value)) {
            return $value;
        }

        $base = rtrim(config('services.supabase.storage.url', ''), '/');
        $prefix = $base.'/storage/v1/object/public/'.rawurlencode(config('services.supabase.storage.bucket', '')) .'/';
        if ($base === '' || ! str_starts_with($value, $prefix)) {
            return null;
        }

        return rawurldecode(substr($value, strlen($prefix)));
    }
}
