<?php
// Saisie d'un formulaire refusé par une erreur de validation (voir kintai\Core\ValidationFeedback) : données JSON,
// jamais exécutées (type application/json, donc hors du champ de la CSP des scripts), recopiées dans le formulaire
// d'origine par public/assets/js/modules/old-input.js.
$_oldInput = \kintai\Core\OldInput::pull();
if ($_oldInput !== null): ?>
<script type="application/json" id="kintai-old-input"><?= json_encode($_oldInput, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
