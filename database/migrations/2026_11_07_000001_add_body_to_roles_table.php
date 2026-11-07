<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A body can define its own roles.
 *
 * NULL is a platform role, available to everyone and editable by nobody here. A
 * body's role belongs to that body, and to the bodies nested inside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('body_id')->nullable()->after('id')->constrained('bodies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('body_id');
        });
    }
};
