<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A newly inserted report does not stand until something says it does.
 *
 * `is_current` defaulted to true, which meant every insert claimed the period
 * the moment it landed — before resolveSupersession() had decided whether it
 * actually wins. With the one-current-per-period index in place that collides
 * outright; without the index it was a race that merely never got caught.
 *
 * False is also the more honest default in its own right. Whether a report
 * stands is a CONCLUSION about the whole set of versions for that period —
 * highest sequence wins, and an out-of-order retry of an old month must not
 * displace a newer one. An insert cannot know that about itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->boolean('is_current')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->boolean('is_current')->default(true)->change();
        });
    }
};
