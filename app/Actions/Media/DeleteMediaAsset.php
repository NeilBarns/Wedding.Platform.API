<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class DeleteMediaAsset
{
    public function __construct(private readonly MediaAssetUsageChecker $usage) {}

    public function handle(MediaAsset $asset): void
    {
        if ($this->usage->isUsed($asset)) {
            throw ValidationException::withMessages(['asset' => 'This image is currently used by your Website and cannot be deleted.']);
        }
        $directory = dirname($asset->original_path);
        if (! Storage::disk($asset->storage_disk)->deleteDirectory($directory)) {
            throw new RuntimeException('Unable to remove the stored Media files.');
        }

        DB::transaction(fn () => $asset->delete());
    }
}
