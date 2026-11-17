<?php

namespace App\Domain\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free yearly document numbers, e.g. ENQ-2026-0001, Q-2026-0001.
 * Uses a row lock so two requests can never get the same number.
 */
class DocumentNumber
{
    public static function next(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $key = strtolower($prefix).':'.$year;

        $value = DB::transaction(function () use ($key): int {
            DB::table('document_sequences')->insertOrIgnore([
                'key' => $key,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('document_sequences')->where('key', $key)->lockForUpdate()->value('last_value');
            $next = $current + 1;

            DB::table('document_sequences')->where('key', $key)->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%d-%04d', strtoupper($prefix), $year, $value);
    }
}
