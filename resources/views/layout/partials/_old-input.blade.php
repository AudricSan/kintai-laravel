@php
// Saisie d'un formulaire refusé par une erreur de validation (voir App\ValidationFeedback) : données JSON,
// jamais exécutées (type application/json, donc hors du champ de la CSP des scripts), recopiées dans le formulaire
// d'origine par public/assets/js/modules/old-input.js.
$_oldInput = \App\OldInput::pull();
if ($_oldInput !== null): @endphp
<script type="application/json" id="kintai-old-input">{!! json_encode($_oldInput, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
