<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // This project uses SQLite. Enforce case-insensitive uniqueness in the DB too.
            $table->string('email', 320)->collation('nocase')->unique();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('email_contact_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_contact_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->timestamps();
            $table->unique(['email_contact_id', 'url']);
        });

        Schema::create('email_scans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('mode');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('new_count');
            $table->unsignedInteger('existing_count');
            $table->unsignedInteger('skipped_count');
            $table->timestamps();
        });

        Schema::create('email_contact_email_scan', function (Blueprint $table) {
            $table->foreignId('email_contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_scan_id')->constrained()->cascadeOnDelete();
            $table->primary(['email_contact_id', 'email_scan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_contact_email_scan');
        Schema::dropIfExists('email_scans');
        Schema::dropIfExists('email_contact_sources');
        Schema::dropIfExists('email_contacts');
    }
};
