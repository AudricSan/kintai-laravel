@php
// Saisie d'un formulaire refusé par une erreur de validation : withInput() la flashe en session, et ce bloc JSON
// (type application/json, donc hors du champ de la CSP des scripts, jamais exécuté) la transmet à
// public/assets/js/modules/old-input.js, qui la recopie dans le formulaire d'origine.
$_oldFields = session()->getOldInput();
if ($_oldFields !== [] && session()->has('old_input_action')): @endphp
<script type="application/json" id="kintai-old-input">{!! json_encode(['action' => session('old_input_action'), 'fields' => $_oldFields], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endif