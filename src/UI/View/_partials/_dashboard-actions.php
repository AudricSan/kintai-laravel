<?php

// Ancien chemin, gardé pour les bundles qui incluent ce partiel par son chemin : avec Laravel, src/ est devenu app/.
// Inclure plutôt app/UI/View/_partials/_dashboard-actions.php, ou mieux :
// $view->renderPartial('_partials._dashboard-actions', [...]).
include dirname(__DIR__, 4) . '/app/UI/View/_partials/_dashboard-actions.php';
