<?php

// Ancien chemin, gardé pour les bundles qui incluent ce partiel par son chemin : avec Laravel, src/ est devenu app/.
// Inclure plutôt app/UI/View/_partials/_dashboard-grid.php, ou mieux :
// $view->renderPartial('_partials._dashboard-grid', [...]).
include dirname(__DIR__, 4) . '/app/UI/View/_partials/_dashboard-grid.php';
