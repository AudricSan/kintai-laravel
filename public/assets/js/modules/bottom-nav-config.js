(function () {
    const MIN = 2;
    const MAX = 4;

    const checks = Array.from(document.querySelectorAll('.bottom-nav-check'));
    const hint   = document.getElementById('bottomNavHint');

    if (!checks.length) return;

    function countChecked() {
        return checks.filter(c => c.checked).length;
    }

    function refresh() {
        const n = countChecked();
        checks.forEach(c => {
            // Bloquer uniquement l'ajout quand MAX atteint (items non cochés)
            // Ne jamais désactiver un item coché — disabled empêche la soumission du formulaire
            c.disabled = !c.checked && n >= MAX;
        });

        if (hint) {
            hint.classList.toggle('hidden', n >= MIN && n <= MAX);
        }
    }

    checks.forEach(c => c.addEventListener('change', function () {
        // Empêcher de descendre sous le minimum en annulant le décocher
        if (!this.checked && countChecked() < MIN) {
            this.checked = true;
        }
        refresh();
    }));

    refresh();
})();
