<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use kintai\Http\KintaiBridge;

// Toutes les requêtes HTTP sont confiées à l'application Kintai (routes/kintai.php et routes des bundles), voir
// KintaiBridge. Les routes passeront une à une dans ce fichier au fil de la migration.
Route::any('/{path?}', KintaiBridge::class)->where('path', '.*');
