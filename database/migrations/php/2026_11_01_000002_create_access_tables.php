<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Rôles et permissions, jetons (API, iCal, cron, mot de passe oublié, « se souvenir de moi »).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('roles', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'name', ['length' => 255]);
            $table->addColumn('string', 'slug', ['length' => 255, 'unique' => true]);
            $table->addColumn('string', 'color', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'description', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'is_system', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'is_manager', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
        });

        $this->schema()->create('role_permissions', function (Blueprint $table) {
            $table->addColumn('integer', 'role_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'permission_key', ['length' => 255]);
            $table->addColumn('string', 'scope', ['length' => 255, 'default' => 'local']);

            $table->primary(['role_id', 'permission_key'], 'role_permissions_role_id_permission_key_primary');
            $table->foreign(['role_id'], 'role_permissions_role_id_foreign')->references(['id'])->on('roles')->onDelete('cascade');
        });

        $this->schema()->create('role_assignments', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'role_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'scope_type', ['length' => 255]);
            $table->addColumn('integer', 'scope_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->index(['user_id'], 'role_assignments_user_id_index');
            $table->index(['scope_type', 'scope_id'], 'role_assignments_scope_type_scope_id_index');
            $table->foreign(['user_id'], 'role_assignments_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['role_id'], 'role_assignments_role_id_foreign')->references(['id'])->on('roles')->onDelete('cascade');
            $table->foreign(['scope_id'], 'role_assignments_scope_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('api_tokens', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'token', ['length' => 64, 'unique' => true]);
            $table->addColumn('string', 'name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('timestamp', 'last_used_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'expires_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['user_id'], 'api_tokens_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('ical_tokens', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'token', ['length' => 64, 'unique' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['user_id', 'store_id'], 'ical_tokens_user_id_store_id_unique');
            $table->foreign(['user_id'], 'ical_tokens_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['store_id'], 'ical_tokens_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('cron_tokens', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'job_name', ['length' => 255]);
            $table->addColumn('string', 'token', ['length' => 64, 'unique' => true]);
            $table->addColumn('string', 'name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('timestamp', 'last_used_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'expires_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->foreign(['user_id'], 'cron_tokens_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('password_reset_tokens', function (Blueprint $table) {
            $table->addColumn('string', 'email', ['length' => 255, 'primary' => true]);
            $table->addColumn('string', 'token', ['length' => 255]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'expires_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['token'], 'password_reset_tokens_token_index');
        });

        $this->schema()->create('remember_tokens', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'selector', ['length' => 24, 'unique' => true]);
            $table->addColumn('string', 'validator_hash', ['length' => 64]);
            $table->addColumn('timestamp', 'expires_at', ['precision' => 0]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->foreign(['user_id'], 'remember_tokens_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('remember_tokens');
        $this->schema()->dropIfExists('password_reset_tokens');
        $this->schema()->dropIfExists('cron_tokens');
        $this->schema()->dropIfExists('ical_tokens');
        $this->schema()->dropIfExists('api_tokens');
        $this->schema()->dropIfExists('role_assignments');
        $this->schema()->dropIfExists('role_permissions');
        $this->schema()->dropIfExists('roles');
    }
};
