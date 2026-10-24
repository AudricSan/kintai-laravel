<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('role_permissions')) {
            return;
        }
        // permission_key n'est pas une FK : le catalogue de permissions est codé
        // en dur côté application (kintai\Core\Auth\PermissionCatalog), pas une
        // table — même logique que store_features.feature_key.
        $this->schema()->create('role_permissions', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('permission_key');

            $table->primary(['role_id', 'permission_key']);
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('role_permissions');
    }
};
