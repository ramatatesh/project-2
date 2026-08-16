<?php

namespace App\Services;

use App\Models\LeaveRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveAttachmentService
{
    public const DISK = 'public';

    public const DIRECTORY = 'leave_attachments';

    /**
     * Store an uploaded proof file and return the relative storage path.
     */
    public function store(UploadedFile $file): string
    {
        return $file->store(self::DIRECTORY, self::DISK);
    }

    /**
     * Persist relative path in DB (not a full URL).
     * Legacy rows may still contain /storage/... or absolute URLs.
     */
    public function normalizeStoredValue(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        if (str_contains($stored, '/storage/')) {
            return ltrim(Str::after($stored, '/storage/'), '/');
        }

        return ltrim($stored, '/');
    }

    public function publicUrl(?string $stored): ?string
    {
        $path = $this->normalizeStoredValue($stored);

        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return asset('storage/'.$path);
    }

    public function exists(?string $stored): bool
    {
        $path = $this->normalizeStoredValue($stored);

        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }

    public function isOwnedPath(string $path): bool
    {
        $normalized = $this->normalizeStoredValue($path);

        return $normalized !== null
            && str_starts_with($normalized, self::DIRECTORY.'/')
            && Storage::disk(self::DISK)->exists($normalized);
    }

    public function download(LeaveRequest $leaveRequest): StreamedResponse
    {
        $path = $this->normalizeStoredValue($leaveRequest->attachment_url);

        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            abort(404, 'Attachment not found.');
        }

        $filename = basename($path);

        return Storage::disk(self::DISK)->download($path, $filename);
    }
}
