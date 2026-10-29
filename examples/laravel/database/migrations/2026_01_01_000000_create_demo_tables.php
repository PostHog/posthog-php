<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('demo_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('demo_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('amount');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'demo_orders', 'demo_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
