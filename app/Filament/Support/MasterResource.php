<?php

namespace App\Filament\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Base for master resources: archived (soft-deleted) rows can be listed with the
 * "Archived" filter and restored.
 */
abstract class MasterResource extends Resource
{
    /**
     * Dragging rows to change sort order is allowed for anyone who may edit the master.
     */
    public static function canReorder(): bool
    {
        return (bool) auth()->user()?->can('Update:'.class_basename(static::getModel()));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
