<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 검표원(게이트) 계정 + 누가·어느 게이트에서 입장시켰는지.
 *  - users.role: enum(super_admin, vendor) → 문자열 (gate 추가)
 *  - users.gate_name: 기본 게이트 이름 (예: 1번 게이트)
 *  - tickets.checked_in_by / checked_in_gate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('vendor')->change();
            $t->string('gate_name', 40)->nullable()->after('vendor_id');
        });
        Schema::table('tickets', function (Blueprint $t) {
            $t->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $t->string('checked_in_gate', 40)->nullable()->after('checked_in_by');
            $t->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropIndex(['event_id', 'status']);
            $t->dropConstrainedForeignId('checked_in_by');
            $t->dropColumn('checked_in_gate');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('gate_name'));
    }
};
