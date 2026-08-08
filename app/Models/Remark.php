<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RemarkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Remark extends Model
{
    /** @use HasFactory<RemarkFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'remarkable_type',
        'remarkable_id',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function remarkable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(RemarkMention::class);
    }
}
