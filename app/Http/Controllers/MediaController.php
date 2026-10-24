<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Models\MediaAsset;
use App\Models\SystemSetting;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function store(Request $request)
    {
        $limit = min(51200, max(1024, (int) SystemSetting::getValue('max_upload_mb', 25) * 1024));
        $request->validate([
            'file' => 'required|file|max:'.$limit.'|mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/webm,audio/mp4,video/mp4,video/webm,image/jpeg,image/png,image/webp',
            'duration' => 'nullable|numeric|min:0|max:86400',
        ]);
        $file = $request->file('file');
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $dimensions = @getimagesize($file->getRealPath());
            if (!$dimensions || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 25000000) {
                throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'Images must be valid and contain at most 25 megapixels.']);
            }
        }
        $path = $file->store('media', 'local');
        try {
            $asset = MediaAsset::create([
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 200),
                'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                'kind' => $file->getMimeType() === 'application/ogg' ? 'audio' : explode('/', $file->getMimeType())[0],
                'metadata' => ['duration' => $request->input('duration'), 'uploaded_by' => $request->user()->id],
            ]);
            Audit::record('media.uploaded', $asset);
            return response()->json($asset->toArray() + ['url' => route('media.show', $asset->id)], 201);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function branding(string $kind)
    {
        abort_unless(in_array($kind, ['logo', 'favicon']), 404);
        $id = SystemSetting::getValue($kind.'_media_id');
        $media = $id ? MediaAsset::find($id) : null;
        abort_unless($media && $media->kind === 'image' && in_array($media->mime, ['image/png', 'image/jpeg', 'image/webp']), 404);
        abort_unless(Storage::disk('local')->exists($media->path), 404);
        return response()->file(Storage::disk('local')->path($media->path), ['Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }

    public function show(Request $request, MediaAsset $media)
    {
        $allowed = $request->user()->role === 'superadmin';
        $meta = $media->metadata ?? [];
        if (! $allowed && (int) ($meta['user_id'] ?? 0) === $request->user()->id) $allowed = true;
        if (! $allowed) {
            foreach (ExamAttempt::where('user_id', $request->user()->id)->get(['snapshot']) as $attempt) {
                foreach (($attempt->snapshot['sections'] ?? []) as $section) {
                    foreach (($section['questions'] ?? []) as $q) {
                        $ids = [$q['group']['media_asset_id'] ?? null, $q['metadata']['media_asset_id'] ?? null];
                        if (in_array($media->id, $ids, true)) { $allowed = true; break 3; }
                    }
                }
            }
        }
        abort_unless($allowed, 403);
        abort_unless(Storage::disk('local')->exists($media->path), 404);
        return response()->file(Storage::disk('local')->path($media->path), [
            'Content-Type' => $media->mime, 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
