<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmStage extends Model
{
    /** color => [badge classes, dot / bar class]; full class names so Tailwind keeps them. */
    public const COLORS = [
        'slate' => ['bg-slate-100 text-slate-700', 'bg-slate-400'],
        'sky' => ['bg-sky-100 text-sky-700', 'bg-sky-500'],
        'indigo' => ['bg-indigo-100 text-indigo-700', 'bg-indigo-500'],
        'violet' => ['bg-violet-100 text-violet-700', 'bg-violet-500'],
        'amber' => ['bg-amber-100 text-amber-700', 'bg-amber-500'],
        'orange' => ['bg-orange-100 text-orange-700', 'bg-orange-500'],
        'emerald' => ['bg-emerald-100 text-emerald-700', 'bg-emerald-500'],
        'teal' => ['bg-teal-100 text-teal-700', 'bg-teal-500'],
        'rose' => ['bg-rose-100 text-rose-700', 'bg-rose-500'],
        'pink' => ['bg-pink-100 text-pink-700', 'bg-pink-500'],
    ];

    public function badgeClass(): string
    {
        return (self::COLORS[$this->color] ?? self::COLORS['slate'])[0];
    }

    public function dotClass(): string
    {
        return (self::COLORS[$this->color] ?? self::COLORS['slate'])[1];
    }

    protected $fillable = ['name', 'color', 'sort', 'probability', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return ['is_won' => 'boolean', 'is_lost' => 'boolean', 'probability' => 'integer', 'sort' => 'integer'];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class, 'stage_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->is_won || $this->is_lost;
    }

    /** Where new leads start: the first stage that is neither won nor lost. */
    public static function entry(): ?self
    {
        return static::query()->ordered()->where('is_won', false)->where('is_lost', false)->first();
    }

    public static function won(): ?self
    {
        return static::query()->ordered()->where('is_won', true)->first();
    }

    public static function lost(): ?self
    {
        return static::query()->ordered()->where('is_lost', true)->first();
    }
}
