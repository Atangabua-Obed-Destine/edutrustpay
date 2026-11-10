<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes on a school: the visits, calls and meetings the Education Secretariat
 * has with it, dated and signed, kept on the school's page so the next person
 * knows what was already asked. Never deleted: a note is part of the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->date('noted_on');
            $table->text('body');
            $table->timestamps();

            $table->index(['institution_id', 'noted_on']);
        });

        // Adds institution.note, given to the Education Secretary and the bursary head.
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_notes');
    }
};
