<?php

namespace App\Models;

use Database\Factories\WebsiteSectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSection extends Model
{
    /** @use HasFactory<WebsiteSectionFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'content' => '[]',
    ];

    protected $fillable = [
        'type',
        'sort_order',
        'is_enabled',
        'content',
        'appearance',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
            'content' => 'array',
            'appearance' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
