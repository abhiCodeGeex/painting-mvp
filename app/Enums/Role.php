<?php

namespace App\Enums;

/**
 * Roles. For now there are only two: Admin (staff, full access to everything) and Customer
 * (portal only). More staff roles can be added later from System → Roles.
 */
enum Role: string
{
    // Value kept as "super_admin": Filament Shield gives this role full access.
    case SuperAdmin = 'super_admin';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Admin',
            self::Customer => 'Customer',
        };
    }

    /**
     * Built-in roles cannot be deleted or renamed: login, registration and access checks
     * depend on their names.
     */
    public static function isCore(?string $name): bool
    {
        return $name !== null && self::tryFrom($name) !== null;
    }

    /**
     * @return list<self>
     */
    public static function staffRoles(): array
    {
        return [self::SuperAdmin];
    }
}
