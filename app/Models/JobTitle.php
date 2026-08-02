<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobTitleCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTitle extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => JobTitleCode::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
