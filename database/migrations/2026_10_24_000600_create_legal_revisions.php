<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable archive of every published revision ("Fassung") of the privacy policy and terms of use:
 * the rendered plain text (placeholders replaced) with its "Stand". Rows are never changed or deleted.
 * Users keep the ids of the revisions they accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('version', 32);
            $table->longText('body');
            $table->char('hash', 64);
            $table->string('reason', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['kind', 'hash']);
            $table->index(['kind', 'id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('privacy_revision_id')->nullable()->constrained('legal_revisions')->nullOnDelete();
            $table->foreignId('terms_revision_id')->nullable()->constrained('legal_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('privacy_revision_id');
            $table->dropConstrainedForeignId('terms_revision_id');
        });
        Schema::dropIfExists('legal_revisions');
    }
};
