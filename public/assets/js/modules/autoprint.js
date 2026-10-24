/**
 * Impression automatique : déclenche window.print() au chargement
 * si l'élément <meta name="autoprint" content="1"> est présent.
 */
(function () {
    var meta = document.querySelector('meta[name="autoprint"]');
    if (meta && meta.getAttribute('content') === '1') {
        window.addEventListener('load', function () { window.print(); });
    }
})();
