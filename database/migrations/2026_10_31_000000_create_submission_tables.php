<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five tables from spec 0002: submissions, scans, scan events,
 * scan artifacts (raw Copyleaks files) and reports (finished PDFs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author', 255);
            $table->string('title', 255);
            $table->string('assignment', 255);
            $table->string('original_name', 255);
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->char('file_sha256', 64)->index();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedInteger('character_count')->nullable();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('copyleaks_scan_id', 36)->unique();
            $table->string('copyleaks_export_id', 36)->nullable();
            $table->string('status', 20)->index();
            $table->text('failure_reason')->nullable();
            $table->char('webhook_token_hash', 64);
            $table->boolean('ai_requested')->default(false);
            $table->decimal('similarity_percent', 5, 2)->nullable();
            $table->decimal('ai_percent', 5, 2)->nullable();
            $table->boolean('is_sandbox');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('remote_deleted_at')->nullable();
            $table->timestamps();

            // Finds the latest scan of a submission. Also serves the foreign key.
            $table->index(['submission_id', 'id']);
        });

        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 30);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('handled_at')->nullable();

            $table->unique(['scan_id', 'status', 'kind']);
        });

        Schema::create('scan_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            // '' (not NULL) for summary and text, so the unique rule really works.
            $table->string('source_id', 64)->default('');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('created_at')->nullable();

            $table->unique(['scan_id', 'kind', 'source_id']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('page_count');
            $table->string('renderer_version', 20);
            $table->string('manuscript_mode', 20)->nullable();
            $table->string('manuscript_fallback_reason', 40)->nullable();
            $table->timestamp('built_at');

            $table->unique(['scan_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('scan_artifacts');
        Schema::dropIfExists('scan_events');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('submissions');
    }
};
