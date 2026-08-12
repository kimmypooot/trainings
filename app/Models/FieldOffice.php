<?php

namespace App\Models;

use Database\Factories\FieldOfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'type', 'province', 'jurisdiction', 'address',
    'contact_number', 'email', 'head_name', 'head_position', 'is_active', 'remarks',
])]
class FieldOffice extends Model
{
    /** @use HasFactory<FieldOfficeFactory> */
    use HasFactory;

    public const TYPES = [
        'field_office' => 'Field Office',
        'satellite_office' => 'Satellite Office',
        'regional_office' => 'Regional Office',
        'division' => 'Division',
    ];

    protected function casts(): array
    {
        return [
            'jurisdiction' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Options for a select, newest state of the table rather than a hardcoded
     * list — inactive offices are excluded so they cannot be newly chosen.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function options(): array
    {
        return self::active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (self $office) => ['value' => $office->id, 'label' => $office->name])
            ->all();
    }
}
