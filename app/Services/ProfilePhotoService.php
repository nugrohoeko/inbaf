<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProfilePhotoService
{
    public function store(UploadedFile $file, int $userId): array
    {
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['photo' => 'Gunakan foto JPG, PNG, atau WebP yang valid.']);
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || max($width, $height) > config('profile.photo_max_dimension')
            || $width * $height > config('profile.photo_max_pixels')) {
            throw ValidationException::withMessages(['photo' => 'Dimensi foto terlalu besar. Gunakan foto maksimal 4096 piksel dan 12 megapiksel.']);
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'Foto tidak dapat dibaca. Pilih file gambar yang lain.']);
        }
        $side = min($width, $height);
        $size = min((int) config('profile.photo_size'), $side);
        $output = imagecreatetruecolor($size, $size);
        try {
            // Re-encode only decoded pixels; original names, metadata and appended payloads are discarded.
            imagefill($output, 0, 0, imagecolorallocate($output, 255, 255, 255));
            imagecopyresampled($output, $source, 0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2), $size, $size, $side, $side);
            ob_start();
            imagejpeg($output, null, 90);
            $encoded = ob_get_clean();
        } finally {
            imagedestroy($source);
            imagedestroy($output);
        }
        $version = (string) Str::uuid();
        $path = 'profile-photos/'.$userId.'/'.$version.'.jpg';
        if (! $encoded || ! Storage::disk('local')->put($path, $encoded, ['visibility' => 'private'])) {
            throw new RuntimeException('Foto profil tidak dapat disimpan.');
        }

        return ['path' => $path, 'version' => $version];
    }

    public function validPath(?string $path, int $userId): bool
    {
        return is_string($path) && preg_match('/\Aprofile-photos\/'.preg_quote((string) $userId, '/').'\/[a-f0-9-]{36}\.jpg\z/', $path) === 1;
    }

    public function delete(?string $path, int $userId): void
    {
        if ($this->validPath($path, $userId)) {
            Storage::disk('local')->delete($path);
        }
    }
}
