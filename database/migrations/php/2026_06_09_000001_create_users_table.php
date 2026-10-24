<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('users')) {
            return;
        }
        $this->schema()->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('display_name');
            $table->string('phone')->nullable();
            $table->string('profile_image')->nullable();
            $table->string('color')->default('#3B82F6');
            $table->integer('is_admin')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('employee_code')->nullable()->unique();
            // Vide : le compte suit la langue de l'instance (APP_LOCALE, voir InstanceLocale).
            $table->string('language')->default('');

            $table->index(['is_active', 'deleted_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('users');
    }
};
