/**
 * Écran /admin/bundles/market : test (dry-run) et installation/mise à jour
 * d'un bundle avec barre de progression réelle (streaming SSE via fetch),
 * même pattern que backup-update.js. Repli automatique sur le formulaire
 * classique (POST .../install) si fetch/ReadableStream n'est pas disponible.
 */
(function () {
    'use strict';

    document.querySelectorAll('.bundle-market-install-form').forEach(function (form) {
        const dryRunBtn   = form.querySelector('[data-dry-run-btn]');
        const dryRunUrl   = form.dataset.dryRunUrl;
        const streamUrl   = form.dataset.streamUrl;
        const dryRunOkLabel   = form.dataset.dryRunOkLabel || 'OK';
        const genericErrorLabel = form.dataset.genericErrorLabel || 'Erreur';
        const progressBox = form.querySelector('[data-progress]');
        const fill        = form.querySelector('[data-progress-fill]');
        const label       = form.querySelector('[data-progress-label]');
        // Le bouton submit vit désormais hors du <form> (rangée commune avec
        // Désinstaller en bas de carte), associé via l'attribut form="id" —
        // form.querySelector() ne le trouve plus, il faut chercher dans le document.
        const submitBtn = form.id
            ? document.querySelector('button[type="submit"][form="' + form.id + '"]')
            : form.querySelector('button[type="submit"]');

        function setProgress(percent, text) {
            if (fill) fill.style.width = percent + '%';
            if (label) label.textContent = text;
        }

        function showMessage(message, isError) {
            const existing = form.querySelector('.bundle-market-inline-alert');
            if (existing) existing.remove();
            const alert = document.createElement('div');
            alert.className = 'bundle-market-inline-alert alert alert--' + (isError ? 'danger' : 'success') + ' mt-sm';
            alert.textContent = message;
            form.appendChild(alert);
        }

        if (dryRunBtn && dryRunUrl) {
            dryRunBtn.addEventListener('click', function () {
                dryRunBtn.disabled = true;
                const formData = new FormData(form);
                fetch(dryRunUrl, { method: 'POST', body: formData })
                    .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
                    .then(function (result) {
                        const message = result.data.ok
                            ? dryRunOkLabel
                            : (result.data.error || genericErrorLabel);
                        showMessage(message, !result.data.ok);
                    })
                    .catch(function (err) {
                        showMessage(err.message || String(err), true);
                    })
                    .finally(function () {
                        dryRunBtn.disabled = false;
                    });
            });
        }

        if (!streamUrl || !progressBox || !fill || !label || !window.fetch || !window.ReadableStream) {
            return; // repli sur le submit classique du formulaire
        }

        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            e.preventDefault();

            if (submitBtn) submitBtn.disabled = true;
            progressBox.classList.remove('hidden');
            setProgress(0, '…');

            fetch(streamUrl, {
                method: 'POST',
                body: new FormData(form),
            }).then(function (resp) {
                if (!resp.ok || !resp.body) {
                    throw new Error('HTTP ' + resp.status);
                }

                const reader  = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                function pump() {
                    return reader.read().then(function (result) {
                        if (result.done) return;

                        buffer += decoder.decode(result.value, { stream: true });
                        const chunks = buffer.split('\n\n');
                        buffer = chunks.pop();

                        chunks.forEach(function (chunk) {
                            const eventMatch = chunk.match(/^event:\s*(.+)$/m);
                            const dataMatch  = chunk.match(/^data:\s*(.+)$/m);
                            if (!eventMatch || !dataMatch) return;

                            let data;
                            try { data = JSON.parse(dataMatch[1]); } catch { return; }

                            if (eventMatch[1] === 'progress') {
                                setProgress(data.percent, data.label);
                            } else if (eventMatch[1] === 'done') {
                                setProgress(100, 'OK');
                                // Retour au catalogue avec le message d'installation et d'activation (quota).
                                setTimeout(function () {
                                    window.location.href = window.location.pathname + '?success=' + encodeURIComponent(data.slug || '')
                                        + '&activation=' + encodeURIComponent(data.activation || '');
                                }, 1200);
                            } else if (eventMatch[1] === 'error') {
                                if (submitBtn) submitBtn.disabled = false;
                                showMessage(data.message || 'Erreur inconnue', true);
                            }
                        });

                        return pump();
                    });
                }

                return pump();
            }).catch(function (err) {
                if (submitBtn) submitBtn.disabled = false;
                showMessage(err.message || String(err), true);
            });
        });
    });
})();
