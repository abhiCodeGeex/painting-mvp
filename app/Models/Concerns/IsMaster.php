<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Shared behaviour for every master (spec §4.1):
 * active flag + sort order, soft deletes, created_by / updated_by, and an audit log entry
 * for every change.
 *
 * @mixin Model
 */
trait IsMaster
{
    use LogsActivity, SoftDeletes;

    public static function bootIsMaster(): void
    {
        static::creating(function ($model): void {
            if (Auth::id() !== null) {
                $model->created_by ??= Auth::id();
                $model->updated_by ??= Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::id() !== null) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function initializeIsMaster(): void
    {
        $this->mergeCasts([
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('sort_order'))->orderBy($this->qualifyColumn('id'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'created_by', 'updated_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
