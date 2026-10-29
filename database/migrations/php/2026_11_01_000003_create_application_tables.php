<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Réglages, journal d'activité, notifications, préférences d'affichage, agendas externes et files d'attente.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('app_settings', function (Blueprint $table) {
            $table->addColumn('string', 'key', ['length' => 255, 'primary' => true]);
            $table->addColumn('text', 'value', ['nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
        });

        $this->schema()->create('activity_log', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'level', ['length' => 20, 'index' => true, 'default' => 'info']);
            $table->addColumn('string', 'channel', ['length' => 50, 'index' => true, 'default' => 'business']);
            $table->addColumn('text', 'message', ['nullable' => true]);
            $table->addColumn('string', 'action', ['length' => 150, 'nullable' => true, 'index' => true]);
            $table->addColumn('string', 'resource_type', ['length' => 50, 'nullable' => true]);
            $table->addColumn('integer', 'resource_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('text', 'context', ['nullable' => true]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'ip_address', ['length' => 45, 'nullable' => true]);
            $table->addColumn('string', 'user_agent', ['length' => 500, 'nullable' => true]);
            $table->addColumn('string', 'request_method', ['length' => 10, 'nullable' => true]);
            $table->addColumn('string', 'request_uri', ['length' => 500, 'nullable' => true]);
            $table->addColumn('integer', 'response_status', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'duration_ms', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'session_id', ['length' => 100, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->index(['level', 'created_at'], 'activity_log_level_created_at_index');
            $table->index(['channel', 'created_at'], 'activity_log_channel_created_at_index');
            $table->index(['action', 'created_at'], 'activity_log_action_created_at_index');
            $table->index(['user_id', 'created_at'], 'activity_log_user_id_created_at_index');
            $table->index(['store_id', 'created_at'], 'activity_log_store_id_created_at_index');
            $table->index(['resource_type', 'resource_id'], 'activity_log_resource_type_resource_id_index');
        });

        $this->schema()->create('notifications', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'type', ['length' => 255]);
            $table->addColumn('string', 'body_key', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'params', ['nullable' => true]);
            $table->addColumn('text', 'body', ['nullable' => true]);
            $table->addColumn('string', 'link', ['length' => 500, 'nullable' => true]);
            $table->addColumn('string', 'reference_type', ['length' => 40, 'nullable' => true]);
            $table->addColumn('integer', 'reference_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'read_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->index(['user_id', 'read_at'], 'notifications_user_id_read_at_index');
            $table->index(['user_id', 'id'], 'notifications_user_id_id_index');
            $table->index(['user_id', 'reference_type', 'reference_id'], 'notifications_user_id_reference_type_reference_id_index');
            $table->foreign(['user_id'], 'notifications_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('notification_preferences', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('string', 'type', ['length' => 64]);
            $table->addColumn('string', 'channel', ['length' => 16]);
            $table->addColumn('boolean', 'enabled');

            $table->unique(['user_id', 'type', 'channel'], 'notification_preferences_user_id_type_channel_unique');
        });

        $this->schema()->create('device_push_tokens', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'token', ['length' => 255, 'unique' => true]);
            $table->addColumn('string', 'platform', ['length' => 20, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'last_used_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['user_id'], 'device_push_tokens_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('web_push_subscriptions', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => true, 'index' => true]);
            $table->addColumn('text', 'endpoint');
            $table->addColumn('char', 'endpoint_hash', ['length' => 64, 'unique' => true]);
            $table->addColumn('string', 'p256dh', ['length' => 255]);
            $table->addColumn('string', 'auth', ['length' => 255]);
            $table->addColumn('string', 'user_agent', ['length' => 255, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'last_used_at', ['precision' => 0, 'nullable' => true]);
        });

        $this->schema()->create('user_dashboard_prefs', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'dashboard_type', ['length' => 255, 'default' => 'employee']);
            $table->addColumn('text', 'widgets', ['default' => $this->textDefault('[]')]);
            $table->addColumn('text', 'layout', ['nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['user_id', 'dashboard_type'], 'user_dashboard_prefs_user_id_dashboard_type_unique');
            $table->foreign(['user_id'], 'user_dashboard_prefs_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('user_nav_prefs', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'unique' => true]);
            $table->addColumn('text', 'hidden_items', ['default' => $this->textDefault('[]')]);
            $table->addColumn('text', 'section_order', ['nullable' => true]);
            $table->addColumn('text', 'bottom_nav_items', ['nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['user_id'], 'user_nav_prefs_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('user_dashboard_layouts', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => true, 'index' => true]);
            $table->addColumn('string', 'dashboard_type', ['length' => 20]);
            $table->addColumn('string', 'name', ['length' => 60]);
            $table->addColumn('text', 'layout');
            $table->addColumn('boolean', 'is_active', ['default' => false]);
            $table->addColumn('smallInteger', 'position', ['autoIncrement' => false, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['user_id', 'dashboard_type', 'name'], 'user_dashboard_layouts_user_id_dashboard_type_name_unique');
        });

        $this->schema()->create('dashboard_role_layouts', function (Blueprint $table) {
            $table->addColumn('integer', 'role_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('string', 'dashboard_type', ['length' => 20]);
            $table->addColumn('text', 'layout');
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->primary(['role_id', 'dashboard_type'], 'dashboard_role_layouts_role_id_dashboard_type_primary');
        });

        $this->schema()->create('calendar_connections', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('string', 'provider', ['length' => 20, 'default' => 'google']);
            $table->addColumn('string', 'account_email', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'refresh_token');
            $table->addColumn('string', 'calendar_id', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'calendar_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'status', ['length' => 20, 'default' => 'active']);
            $table->addColumn('timestamp', 'last_sync_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('text', 'last_error', ['nullable' => true]);
            $table->addColumn('text', 'options', ['nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['user_id', 'provider'], 'calendar_connections_user_id_provider_unique');
        });

        $this->schema()->create('calendar_event_links', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'connection_id', ['autoIncrement' => false, 'unsigned' => true, 'index' => true]);
            $table->addColumn('string', 'source_type', ['length' => 20]);
            $table->addColumn('integer', 'source_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('string', 'external_event_id', ['length' => 255]);
            $table->addColumn('char', 'content_hash', ['length' => 64]);
            $table->addColumn('timestamp', 'synced_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['connection_id', 'source_type', 'source_id'], 'calendar_event_links_connection_id_source_type_source_id_unique');
        });

        $this->schema()->create('calendar_sync_queue', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'connection_id', ['autoIncrement' => false, 'unsigned' => true, 'index' => true]);
            $table->addColumn('string', 'source_type', ['length' => 20]);
            $table->addColumn('integer', 'source_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('smallInteger', 'attempts', ['autoIncrement' => false, 'unsigned' => true, 'default' => 0]);
            $table->addColumn('timestamp', 'next_attempt_at', ['precision' => 0, 'nullable' => true, 'index' => true]);
            $table->addColumn('text', 'last_error', ['nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->unique(['connection_id', 'source_type', 'source_id'], 'calendar_sync_queue_connection_id_source_type_source_id_unique');
        });

        $this->schema()->create('jobs', function (Blueprint $table) {
            $table->addColumn('bigInteger', 'id', ['autoIncrement' => true, 'unsigned' => true]);
            $table->addColumn('string', 'queue', ['length' => 255, 'index' => true]);
            $table->addColumn('longText', 'payload');
            $table->addColumn('tinyInteger', 'attempts', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('integer', 'reserved_at', ['autoIncrement' => false, 'unsigned' => true, 'nullable' => true]);
            $table->addColumn('integer', 'available_at', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('integer', 'created_at', ['autoIncrement' => false, 'unsigned' => true]);
        });

        $this->schema()->create('failed_jobs', function (Blueprint $table) {
            $table->addColumn('bigInteger', 'id', ['autoIncrement' => true, 'unsigned' => true]);
            $table->addColumn('string', 'uuid', ['length' => 255, 'unique' => true]);
            $table->addColumn('text', 'connection');
            $table->addColumn('text', 'queue');
            $table->addColumn('longText', 'payload');
            $table->addColumn('longText', 'exception');
            $table->addColumn('timestamp', 'failed_at', ['precision' => 0, 'useCurrent' => true]);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('failed_jobs');
        $this->schema()->dropIfExists('jobs');
        $this->schema()->dropIfExists('calendar_sync_queue');
        $this->schema()->dropIfExists('calendar_event_links');
        $this->schema()->dropIfExists('calendar_connections');
        $this->schema()->dropIfExists('dashboard_role_layouts');
        $this->schema()->dropIfExists('user_dashboard_layouts');
        $this->schema()->dropIfExists('user_nav_prefs');
        $this->schema()->dropIfExists('user_dashboard_prefs');
        $this->schema()->dropIfExists('web_push_subscriptions');
        $this->schema()->dropIfExists('device_push_tokens');
        $this->schema()->dropIfExists('notification_preferences');
        $this->schema()->dropIfExists('notifications');
        $this->schema()->dropIfExists('activity_log');
        $this->schema()->dropIfExists('app_settings');
    }
};
