/**
 * Fenêtres de page déclarées par les bundles (Bundle::pageOverlays(), contrat 1.27 ; zone rendue par PageOverlayHost).
 *
 * - Le focus est placé dans la fenêtre à l'ouverture et piégé (Tab / Maj+Tab) ; il revient à l'élément précédent à la fermeture.
 * - Échap et clic sur le fond la ferment si elle est fermable (data-dismissible="1") ; tout élément [data-overlay-close] aussi.
 * - Fermer ne supprime rien côté serveur : la fenêtre revient à la page suivante tant que son bundle la juge visible. Avec
 *   data-once="1" (« une fois par session »), la fermeture est mémorisée dans sessionStorage et elle ne revient plus dans cet onglet.
 */
(function () {
    'use strict';

    var overlay = document.querySelector('[data-page-overlay]');
    if (!overlay) return;

    var key = overlay.getAttribute('data-overlay-key') || '';
    var storageKey = 'kintai.overlay.' + key;
    var dismissible = overlay.getAttribute('data-dismissible') === '1';
    var once = overlay.getAttribute('data-once') === '1';
    var previous = document.activeElement;
    var isBar = overlay.getAttribute('data-placement') === 'bar';

    try {
        if (once && sessionStorage.getItem(storageKey) === '1') {
            overlay.remove();
            return;
        }
    } catch (err) { /* stockage indisponible : la fenêtre s'affiche */ }

    function focusable() {
        return Array.prototype.slice.call(overlay.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function (el) { return el.offsetParent !== null; });
    }

    function close() {
        if (once) {
            try { sessionStorage.setItem(storageKey, '1'); } catch (err) { /* stockage indisponible */ }
        }
        overlay.remove();
        document.documentElement.classList.remove('page-overlay-open');
        if (previous && typeof previous.focus === 'function') previous.focus();
    }

    // Une barre ne bloque rien : ni défilement, ni focus déplacé, ni piège au clavier.
    if (!isBar) {
        document.documentElement.classList.add('page-overlay-open');
        var items = focusable();
        if (items.length) {
            items[0].focus();
        } else {
            overlay.setAttribute('tabindex', '-1');
            overlay.focus();
        }
    }

    overlay.addEventListener('click', function (e) {
        if (e.target.closest('[data-overlay-close]')) {
            e.preventDefault();
            close();
        } else if (dismissible && !isBar && e.target === overlay) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!document.body.contains(overlay) || isBar) return;
        if (e.key === 'Escape' && dismissible) {
            close();
            return;
        }
        if (e.key !== 'Tab') return;
        var list = focusable();
        if (!list.length) {
            e.preventDefault();
            return;
        }
        var first = list[0];
        var last = list[list.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });
}());
