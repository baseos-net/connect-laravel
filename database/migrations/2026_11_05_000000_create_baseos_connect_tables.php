<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('baseos-connect.connections.db_connection') ?: parent::getConnection();
    }

    public function up(): void
    {
        $connections = config('baseos-connect.connections.table', 'baseos_connect_connections');
        $idempotency = config('baseos-connect.idempotency.table', 'baseos_connect_idempotency');

        if (! Schema::hasTable($connections)) {
            Schema::create($connections, function (Blueprint $table) {
                $table->id();
                $table->string('connection_id', 64)->unique();
                $table->text('secret');
                $table->string('tenant', 191)->nullable()->index();
                $table->string('site_url', 500)->nullable();
                $table->string('receiver_url', 500)->nullable();
                $table->string('protocol', 16)->default('2.1');
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($idempotency)) {
            Schema::create($idempotency, function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->json('response');
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('baseos-connect.idempotency.table', 'baseos_connect_idempotency'));
        Schema::dropIfExists(config('baseos-connect.connections.table', 'baseos_connect_connections'));
    }
};
