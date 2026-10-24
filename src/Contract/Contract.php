<?php

// Ancien chemin du contrat de bundle : avec Laravel, src/ est devenu app/. Avant de se mettre à jour, une instance 0.x
// lit ce fichier sur GitHub pour lister les bundles que la release ne chargerait plus : la ligne ci-dessous doit
// rester la version de app/Contract/Contract.php (vérifié par LegacySrcBridgeTest).
// const VERSION = '1.30.0';
require_once dirname(__DIR__, 2) . '/app/Contract/Contract.php';
