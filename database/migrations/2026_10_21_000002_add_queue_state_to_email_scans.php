<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_scans', function (Blueprint $table) {
            $table->string('status')->default('completed')->index();
            $table->text('request')->nullable();
            $table->json('result')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_scans', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'request', 'result', 'failure_message', 'started_at', 'finished_at']);
        });
    }
};
