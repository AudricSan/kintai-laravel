<?php

// Ancien chemin, gardé pour les bundles qui incluent ce partiel par son chemin : avec Laravel, src/ est devenu app/ et
// les vues sont en Blade. Le partiel est inséré dans la portée de l'appelant, comme avant. Préférer :
// $view->renderPartial('_partials._dashboard-grid', [...]).
include \kintai\UI\ViewRenderer::pathInScope('_partials._dashboard-grid');
