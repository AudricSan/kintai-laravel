/**
 * Profil employé : activation/désactivation des champs horaires de disponibilité
 * selon l'état des cases à cocher par jour de la semaine.
 */
(function () {
    document.querySelectorAll('.form-toggle__input[data-dow]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var dow = this.dataset.dow;
            var row = document.getElementById('avail-row-' + dow);
            var st  = document.getElementById('start-' + dow);
            var en  = document.getElementById('end-'   + dow);
            if (this.checked) {
                if (row) row.classList.remove('avail-row--off');
                if (st)  st.disabled = false;
                if (en)  en.disabled = false;
            } else {
                if (row) row.classList.add('avail-row--off');
                if (st)  st.disabled = true;
                if (en)  en.disabled = true;
            }
        });
    });
})();
