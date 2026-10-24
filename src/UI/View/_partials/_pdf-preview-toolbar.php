<?php

// Ancien chemin, gardé pour les bundles qui incluent ce partiel par son chemin : avec Laravel, src/ est devenu app/.
// Inclure plutôt app/UI/View/_partials/_pdf-preview-toolbar.php, ou mieux :
// $view->renderPartial('_partials._pdf-preview-toolbar', [...]).
include dirname(__DIR__, 4) . '/app/UI/View/_partials/_pdf-preview-toolbar.php';
