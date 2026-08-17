<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DeleteMediaAsset
{
    public function handle(MediaAsset $asset): void
    {
        $directory = dirname($asset->original_path);
        if (! Storage::disk($asset->storage_disk)->deleteDirectory($directory)) {
            throw new RuntimeException('Unable to remove the stored Media files.');
        }

        DB::transaction(fn () => $asset->delete());
    }
}
