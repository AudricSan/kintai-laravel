/**
 * Mise à jour GitHub avec barre de progression réelle (streaming SSE via fetch).
 * Repli automatique sur le formulaire classique (POST /admin/backup/update)
 * si fetch/ReadableStream n'est pas disponible ou si le flux échoue.
 */
(function () {
    'use strict';

    const form = document.getElementById('backup-update-form');
    if (!form) return;

    const streamUrl    = form.dataset.streamUrl;
    const progressBox  = document.getElementById('backup-update-progress');
    const fill         = document.getElementById('backup-update-fill');
    const label        = document.getElementById('backup-update-label');
    const submitBtn    = form.querySelector('button[type="submit"]');

    if (!streamUrl || !progressBox || !fill || !label || !window.fetch || !window.ReadableStream) {
        return; // repli sur le submit classique du formulaire
    }

    function setProgress(percent, text) {
        fill.style.width = percent + '%';
        label.textContent = text;
    }

    function showError(message) {
        const alert = document.createElement('div');
        alert.className = 'alert alert--danger mt-sm';
        alert.textContent = message;
        progressBox.insertAdjacentElement('afterend', alert);
        if (submitBtn) submitBtn.disabled = false;
    }

    form.addEventListener('submit', function (e) {
        // La confirmation (data-confirm, modale globale confirm-modal.js) a lieu avant ce
        // listener : annulée, elle n'envoie jamais de submit ; par sécurité on respecte defaultPrevented.
        if (e.defaultPrevented) return;
        e.preventDefault();

        if (submitBtn) submitBtn.disabled = true;
        progressBox.style.display = 'block';
        setProgress(0, '…');

        fetch(streamUrl, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
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
                            const template = progressBox.dataset.summaryTemplate || '';
                            const summary = template
                                .replace('{version}', data.version)
                                .replace('{copied}', data.files_copied)
                                .replace('{deleted}', data.files_deleted)
                                .replace('{migrations}', data.migrations_applied);
                            setProgress(100, summary);
                            setTimeout(function () { window.location.reload(); }, 1800);
                        } else if (eventMatch[1] === 'error') {
                            showError(data.message || 'Erreur inconnue');
                        }
                    });

                    return pump();
                });
            }

            return pump();
        }).catch(function (err) {
            showError(err.message || String(err));
        });
    });
})();
