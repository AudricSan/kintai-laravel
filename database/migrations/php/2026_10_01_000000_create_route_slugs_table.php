<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use kintai\Core\Routing\SlugGenerator;
use Illuminate\Database\Schema\Blueprint;

/**
 * Alias d'URL lisibles (voir kintai\Core\Routing) : /admin/stores/所沢東町店/edit au lieu de
 * /admin/stores/3/edit. Une ligne courante par magasin, plus l'historique des anciens alias (magasins
 * renommés, numéros d'employé changés) pour rediriger les anciens liens en 301.
 *
 * Remplissage : chaque magasin existant reçoit un alias tiré de son nom (sans translittération).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('route_slugs')) {
            $this->schema()->create('route_slugs', function (Blueprint $table) {
                $table->id();
                $table->string('entity_type', 20);
                $table->unsignedBigInteger('entity_id');
                $table->string('slug', 191);
                $table->boolean('is_current')->default(false);
                $table->boolean('is_manual')->default(false);
                $table->dateTime('created_at')->nullable();

                $table->unique(['entity_type', 'slug']);
                $table->index(['entity_type', 'entity_id', 'is_current']);
            });
        }

        if (!$this->schema()->hasTable('stores')) {
            return;
        }

        $db    = $this->capsule->getConnection();
        $taken = $db->table('route_slugs')->where('entity_type', 'store')->pluck('slug')->all();
        $done  = $db->table('route_slugs')->where('entity_type', 'store')->where('is_current', true)->pluck('entity_id')->all();

        foreach ($db->table('stores')->orderBy('id')->get(['id', 'name']) as $store) {
            if (in_array((int) $store->id, array_map('intval', $done), true)) {
                continue;
            }
            $base = SlugGenerator::fromName((string) $store->name);
            if ($base === '') {
                $base = 'store-' . $store->id;
            }
            $slug = SlugGenerator::firstFree(
                $base,
                fn(string $c): bool => in_array($c, $taken, true) || in_array($c, SlugGenerator::BASE_RESERVED, true),
            );
            $taken[] = $slug;

            $db->table('route_slugs')->insert([
                'entity_type' => 'store',
                'entity_id'   => (int) $store->id,
                'slug'        => $slug,
                'is_current'  => true,
                'is_manual'   => false,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('route_slugs');
    }
};
