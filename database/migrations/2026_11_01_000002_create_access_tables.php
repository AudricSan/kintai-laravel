<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et permissions, jetons (API, iCal, cron, mot de passe oublié).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->nullable();
            $table->string('description')->nullable();
            $table->integer('is_system')->default(0);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->integer('is_manager')->default(0);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('permission_key');
            $table->string('scope')->default('local');

            $table->primary(['role_id', 'permission_key'], 'role_permissions_role_id_permission_key_primary');
            $table->foreign('role_id', 'role_permissions_role_id_foreign')->references('id')->on('roles')->onDelete('cascade');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('role_id');
            $table->string('scope_type');
            $table->integer('scope_id')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();

            $table->index('user_id', 'role_assignments_user_id_index');
            $table->index(['scope_type', 'scope_id'], 'role_assignments_scope_type_scope_id_index');
            $table->foreign('user_id', 'role_assignments_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id', 'role_assignments_role_id_foreign')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('scope_id', 'role_assignments_scope_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('token', 64)->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_used_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('expires_at', 0)->nullable();

            $table->foreign('user_id', 'api_tokens_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('ical_tokens', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('store_id');
            $table->string('token', 64)->unique();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['user_id', 'store_id'], 'ical_tokens_user_id_store_id_unique');
            $table->foreign('user_id', 'ical_tokens_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id', 'ical_tokens_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('cron_tokens', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('job_name');
            $table->string('token', 64)->unique();
            $table->string('name')->nullable();
            $table->timestamp('last_used_at', 0)->nullable();
            $table->timestamp('expires_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();

            $table->foreign('user_id', 'cron_tokens_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('expires_at', 0)->nullable();

            $table->index('token', 'password_reset_tokens_token_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('cron_tokens');
        Schema::dropIfExists('ical_tokens');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
