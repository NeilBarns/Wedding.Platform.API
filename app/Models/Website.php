<?php

namespace App\Models;

use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    public const DEFAULT_NAME = 'Website';

    public const MAX_NAME_LENGTH = 100;

    /** @use HasFactory<WebsiteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'template_key',
        'design_settings',
    ];

    protected function casts(): array
    {
        return ['design_settings' => 'array'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(WebsiteSection::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
