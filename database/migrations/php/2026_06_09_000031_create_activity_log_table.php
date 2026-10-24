<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('activity_log')) {
            return;
        }

        $this->schema()->create('activity_log', function (Blueprint $table) {
            $table->increments('id');
            $table->string('level', 20)->index()->default('info');
            $table->string('channel', 50)->index()->default('business');
            $table->text('message')->nullable();
            $table->string('action', 150)->nullable()->index();
            $table->string('resource_type', 50)->nullable();
            $table->integer('resource_id')->nullable();
            $table->text('context')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('store_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_uri', 500)->nullable();
            $table->integer('response_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['level', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
        });

        $db = $this->capsule->getConnection();

        if ($this->schema()->hasTable('logs')) {
            $rows = $db->table('logs')->get();
            foreach ($rows as $row) {
                $data = (array) $row;
                $context = isset($data['context']) && is_string($data['context'])
                    ? json_decode($data['context'], true) : null;
                $action = null;
                $resourceType = null;
                $resourceId = null;

                if (is_array($context)) {
                    $action = $context['action'] ?? null;
                    $resourceType = $context['resource_type'] ?? null;
                    $resourceId = $context['resource_id'] ?? null;
                    unset($context['action'], $context['resource_type'], $context['resource_id']);
                    $details = $context['details'] ?? null;
                    unset($context['details']);
                }

                $db->table('activity_log')->insert([
                    'level'        => $data['level'] ?? 'info',
                    'channel'      => $data['channel'] ?? 'business',
                    'message'      => $data['message'] ?? null,
                    'action'       => $action,
                    'resource_type' => $resourceType,
                    'resource_id'  => $resourceId,
                    'context'      => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (!empty($data['context']) ? $data['context'] : null),
                    'user_id'      => $data['user_id'] ?? null,
                    'store_id'     => $data['store_id'] ?? null,
                    'ip_address'   => $data['ip_address'] ?? null,
                    'user_agent'   => $data['user_agent'] ?? null,
                    'created_at'   => $data['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }

        if ($this->schema()->hasTable('audit_log')) {
            $rows = $db->table('audit_log')->get();
            foreach ($rows as $row) {
                $data = (array) $row;
                $details = isset($data['details']) && is_string($data['details'])
                    ? $data['details'] : null;
                $db->table('activity_log')->insert([
                    'level'         => 'info',
                    'channel'       => 'audit',
                    'message'       => $data['action'] ?? null,
                    'action'        => $data['action'] ?? null,
                    'resource_type' => $data['resource_type'] ?? null,
                    'resource_id'   => $data['resource_id'] ?? null,
                    'context'       => $details,
                    'user_id'       => $data['user_id'] ?? null,
                    'store_id'      => $data['store_id'] ?? null,
                    'ip_address'    => $data['ip_address'] ?? null,
                    'user_agent'    => $data['user_agent'] ?? null,
                    'created_at'    => $data['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('activity_log');
    }
};
