<?php

namespace App\Http\Controllers;

use App\Models\ContractorDocument;
use App\Models\Document;
use App\Models\JobImage;
use App\Models\ProfileClaim;
use App\Models\PropertyImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileController extends Controller
{
    private const IMAGE_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function jobImage(JobImage $image): StreamedResponse
    {
        $user = Auth::guard('api')->user();
        $job = $image->job;

        if (!$job) {
            abort(404);
        }

        if (
            $user->role !== 'admin'
            && (int) $job->customer_id !== (int) $user->id
            && (int) $job->handyman_id !== (int) $user->id
        ) {
            abort(403, 'You do not have access to this job image.');
        }

        return $this->serve($image->image_path, null, true, self::IMAGE_MIME_TYPES);
    }

    public function propertyImage(PropertyImage $image): StreamedResponse
    {
        $user = Auth::guard('api')->user();
        $property = $image->property;

        if (!$property) {
            abort(404);
        }

        $hasSharedAccess = $property->users()
            ->where('users.id', $user->id)
            ->exists();

        if (
            $user->role !== 'admin'
            && (int) $property->owner_user_id !== (int) $user->id
            && !$hasSharedAccess
        ) {
            abort(403, 'You do not have access to this property image.');
        }

        return $this->serve($image->image_path, null, true, self::IMAGE_MIME_TYPES);
    }

    public function contractorDocument(ContractorDocument $document): StreamedResponse
    {
        $user = Auth::guard('api')->user();

        if ($user->role !== 'admin' && (int) $document->user_id !== (int) $user->id) {
            abort(403, 'You do not have access to this contractor document.');
        }

        return $this->serve(
            $document->stored_filename,
            $document->original_filename,
            false
        );
    }

    public function handymanDocument(Document $document): StreamedResponse
    {
        $user = Auth::guard('api')->user();

        if ($user->role !== 'admin' && (int) $document->handyman_id !== (int) $user->id) {
            abort(403, 'You do not have access to this document.');
        }

        return $this->serve(
            $document->file_path,
            basename((string) $document->file_path),
            false
        );
    }

    public function profileClaimDocument(ProfileClaim $claim): StreamedResponse
    {
        $user = Auth::guard('api')->user();

        if ($user->role !== 'admin' && (int) $claim->claimant_user_id !== (int) $user->id) {
            abort(403, 'You do not have access to this profile claim document.');
        }

        return $this->serve(
            $claim->proof_document_path,
            basename((string) $claim->proof_document_path),
            false
        );
    }

    private function serve(
        ?string $path,
        ?string $downloadName,
        bool $inline,
        ?array $allowedMimeTypes = null
    ): StreamedResponse {
        $path = $this->safeRelativePath($path);
        if ($path === null) {
            abort(404);
        }

        $disk = $this->readableDisk($path);
        if ($disk === null) {
            abort(404);
        }

        $mimeType = $disk->mimeType($path);
        if (!is_string($mimeType) || $mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        if ($allowedMimeTypes !== null && !in_array($mimeType, $allowedMimeTypes, true)) {
            abort(404);
        }

        $name = $this->safeDownloadName($downloadName ?: basename($path));

        return $disk->response(
            $path,
            $name,
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'Content-Type' => $mimeType,
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inline ? 'inline' : 'attachment'
        );
    }

    private function readableDisk(string $path): ?FilesystemAdapter
    {
        foreach (['private', 'public'] as $name) {
            $disk = Storage::disk($name);
            if ($disk->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    private function safeRelativePath(?string $path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));

        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)
        ) {
            return null;
        }

        return $path;
    }

    private function safeDownloadName(string $name): string
    {
        $name = basename(str_replace(["\r", "\n", "\0"], '', $name));

        return $name !== '' ? $name : 'trustfix-file';
    }
}
