<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->dropIfExists('audit_log');
        $this->schema()->dropIfExists('logs');
    }

    public function down(): void
    {
        // Cannot recreate legacy tables — they have been superseded by activity_log.
    }
};
