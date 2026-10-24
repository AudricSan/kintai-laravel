/**
 * Filtrage instantané, entièrement côté client, de la liste des employés
 * (/admin/users) : aucun aller-retour serveur pendant la frappe, l'ordre de
 * tri déjà appliqué côté serveur est conservé (les lignes sont simplement
 * masquées, pas réordonnées). Chaque ligne porte data-search-text (nom,
 * display_name, code, email, séparés par "|") et data-search-reading (kana
 * de furigana_last_name+furigana_first_name) posés par staff/users.php.
 * Voir kana-search.js pour la logique de correspondance romaji/hiragana.
 */
(function () {
    var input = document.getElementById('uf-search');
    var table = document.getElementById('users-table');
    if (!input || !table || !window.KanaSearch) return;

    var rows = table.querySelectorAll('tbody tr');

    function applyFilter() {
        var needle = input.value;
        rows.forEach(function (row) {
            var texts    = (row.dataset.searchText || '').split('|');
            var readings = (row.dataset.searchReading || '').split('|');
            row.style.display = window.KanaSearch.matches(needle, texts, readings) ? '' : 'none';
        });
    }

    input.addEventListener('input', applyFilter);
    applyFilter(); // applique la valeur pré-remplie par un lien direct (?search=...)
})();
