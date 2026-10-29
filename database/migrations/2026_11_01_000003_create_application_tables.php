<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages, journal d'activité, notifications, préférences d'affichage, agendas externes et files d'attente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at', 0)->nullable();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('level', 20)->default('info')->index();
            $table->string('channel', 50)->default('business')->index();
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
            $table->timestamp('created_at', 0)->useCurrent();

            $table->index(['level', 'created_at'], 'activity_log_level_created_at_index');
            $table->index(['channel', 'created_at'], 'activity_log_channel_created_at_index');
            $table->index(['action', 'created_at'], 'activity_log_action_created_at_index');
            $table->index(['user_id', 'created_at'], 'activity_log_user_id_created_at_index');
            $table->index(['store_id', 'created_at'], 'activity_log_store_id_created_at_index');
            $table->index(['resource_type', 'resource_id'], 'activity_log_resource_type_resource_id_index');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();

            $table->index(['user_id', 'read_at'], 'notifications_user_id_read_at_index');
            $table->index(['user_id', 'id'], 'notifications_user_id_id_index');
            $table->index(['user_id', 'reference_type', 'reference_id'], 'notifications_user_id_reference_type_reference_id_index');
            $table->foreign('user_id', 'notifications_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unsigned();
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');

            $table->unique(['user_id', 'type', 'channel'], 'notification_preferences_user_id_type_channel_unique');
        });

        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('token')->unique();
            $table->string('platform', 20)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('last_used_at', 0)->nullable();

            $table->foreign('user_id', 'device_push_tokens_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unsigned()->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('last_used_at', 0)->nullable();
        });

        Schema::create('user_dashboard_prefs', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->string('dashboard_type')->default('employee');
            $table->text('widgets')->default(DB::raw("('[]')"));
            $table->text('layout')->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['user_id', 'dashboard_type'], 'user_dashboard_prefs_user_id_dashboard_type_unique');
            $table->foreign('user_id', 'user_dashboard_prefs_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('user_nav_prefs', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unique();
            $table->text('hidden_items')->default(DB::raw("('[]')"));
            $table->text('section_order')->nullable();
            $table->text('bottom_nav_items')->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('user_id', 'user_nav_prefs_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('user_dashboard_layouts', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unsigned()->index();
            $table->string('dashboard_type', 20);
            $table->string('name', 60);
            $table->text('layout');
            $table->boolean('is_active')->default(false);
            $table->smallInteger('position')->unsigned()->default(0);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['user_id', 'dashboard_type', 'name'], 'user_dashboard_layouts_user_id_dashboard_type_name_unique');
        });

        Schema::create('dashboard_role_layouts', function (Blueprint $table) {
            $table->integer('role_id')->unsigned();
            $table->string('dashboard_type', 20);
            $table->text('layout');
            $table->timestamp('updated_at', 0)->nullable();

            $table->primary(['role_id', 'dashboard_type'], 'dashboard_role_layouts_role_id_dashboard_type_primary');
        });

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->unsigned();
            $table->string('provider', 20)->default('google');
            $table->string('account_email')->nullable();
            $table->text('refresh_token');
            $table->string('calendar_id')->nullable();
            $table->string('calendar_name')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_sync_at', 0)->nullable();
            $table->text('last_error')->nullable();
            $table->text('options')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['user_id', 'provider'], 'calendar_connections_user_id_provider_unique');
        });

        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('connection_id')->unsigned()->index();
            $table->string('source_type', 20);
            $table->integer('source_id')->unsigned();
            $table->string('external_event_id');
            $table->char('content_hash', 64);
            $table->timestamp('synced_at', 0)->nullable();

            $table->unique(['connection_id', 'source_type', 'source_id'], 'calendar_event_links_connection_id_source_type_source_id_unique');
        });

        Schema::create('calendar_sync_queue', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('connection_id')->unsigned()->index();
            $table->string('source_type', 20);
            $table->integer('source_id')->unsigned();
            $table->smallInteger('attempts')->unsigned()->default(0);
            $table->timestamp('next_attempt_at', 0)->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();

            $table->unique(['connection_id', 'source_type', 'source_id'], 'calendar_sync_queue_connection_id_source_type_source_id_unique');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigInteger('id', true, true);
            $table->string('queue')->index();
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->integer('reserved_at')->unsigned()->nullable();
            $table->integer('available_at')->unsigned();
            $table->integer('created_at')->unsigned();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->bigInteger('id', true, true);
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at', 0)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('calendar_sync_queue');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('dashboard_role_layouts');
        Schema::dropIfExists('user_dashboard_layouts');
        Schema::dropIfExists('user_nav_prefs');
        Schema::dropIfExists('user_dashboard_prefs');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('device_push_tokens');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('app_settings');
    }
};
