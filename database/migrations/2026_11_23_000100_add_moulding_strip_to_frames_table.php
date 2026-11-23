<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moulding strip built from an admin's frame photo: what kind of photo it is, a manual
 * inside/outside flip, and the last build error (if any).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frames', function (Blueprint $table) {
            $table->string('strip_photo_type', 20)->default('auto')->after('profile');
            $table->boolean('strip_flip')->default(false)->after('strip_photo_type');
            $table->string('strip_error')->nullable()->after('strip_flip');
        });
    }

    public function down(): void
    {
        Schema::table('frames', fn (Blueprint $table) => $table->dropColumn(['strip_photo_type', 'strip_flip', 'strip_error']));
    }
};
