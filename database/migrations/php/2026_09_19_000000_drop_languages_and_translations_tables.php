<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * languages/translations n'ont jamais eu la moindre implémentation active : seuls
 * JsonLanguageRepository/JsonTranslationRepository (lecture depuis lang/*.json) sont
 * liés dans RepositoryServiceProvider, aucun Database*Repository ni modèle Eloquent
 * correspondant, aucune requête vers ces tables nulle part dans src/. La migration de
 * création n'insérait que 3 lignes fixes (fr/en/ja) à l'installation ; aucune donnée
 * utilisateur n'y a jamais été écrite depuis — rien à migrer avant suppression.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        // translations référence languages par clé étrangère : la dropper en premier.
        $this->schema()->dropIfExists('translations');
        $this->schema()->dropIfExists('languages');
    }

    public function down(): void
    {
        // Tables mortes retirées : rien à recréer (jamais aucune donnée réelle, aucun code ne les utilisait).
    }
};
