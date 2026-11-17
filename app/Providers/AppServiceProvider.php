<?php

namespace App\Providers;

use App\Enums\Role as RoleEnum;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch N+1 queries and silently dropped attributes early outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });

        // Built-in roles can never be deleted or renamed (the admin UI also hides those actions).
        Role::deleting(fn (Role $role): bool => ! RoleEnum::isCore($role->name));
        Role::updating(fn (Role $role): bool => ! ($role->isDirty('name') && RoleEnum::isCore($role->getOriginal('name'))));
    }
}
