<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class File extends Model
{
    public const COLLECTION_AVATAR = 'avatar';

    public const DISK_PUBLIC = 'public';

    public const DISK_LOCAL = 'local';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_name',
        'file_path',
        'disk',
        'mime_type',
        'extension',
        'size',
        'collection',
        'attachable_type',
        'attachable_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeAvatars(Builder $query): Builder
    {
        return $query->collection(self::COLLECTION_AVATAR);
    }
}
