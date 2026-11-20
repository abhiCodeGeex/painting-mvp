<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer response to a quote (spec §9): approve / request changes / reject, with an audit record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('responded_at')->nullable()->after('viewed_at');
            $table->foreignId('responded_by')->nullable()->after('responded_at')->constrained('users')->nullOnDelete();
            $table->foreignId('rejection_reason_id')->nullable()->after('responded_by')->constrained()->nullOnDelete();
            $table->jsonb('response')->nullable()->after('rejection_reason_id')
                ->comment('name, role, email, ip, user_agent, po_number, comment, selected optional lines, final total');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejection_reason_id');
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn(['responded_at', 'response']);
        });
    }
};
