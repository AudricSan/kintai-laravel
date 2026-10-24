'use strict';
(function () {
    var _cfgEl = document.getElementById('kintai-timeline-data');
    var i18n = (_cfgEl ? JSON.parse(_cfgEl.textContent || '{}') : (window.KintaiTimeline || {})).i18n || {};

    var tip = document.createElement('div');
    tip.id = 'sd-diag-tip';
    tip.className = 'tl-diag-tip';
    document.body.appendChild(tip);

    // Échappe toute valeur insérée en HTML : noms d'employés et libellés sont saisis par des administrateurs
    // ou des managers, et un manager ne doit pas pouvoir injecter du HTML dans l'écran d'un autre utilisateur.
    function esc(value) {
        var span = document.createElement('span');
        span.textContent = value === undefined || value === null ? '' : String(value);
        return span.innerHTML;
    }

    function renderDiag(d) {
        var html = '<div class="tl-diag-hdr">'
            + '👥 ' + esc(d.staff) + esc(i18n.staff_planned_label)
            + (d.min_staff > 0
                ? ' · ' + esc(i18n.peak_abbr)
                  + ' <span class="tl-diag-peak ' + (d.understaffed ? 'tl-diag-peak--under' : 'tl-diag-peak--ok') + '">' + esc(d.peak) + '</span>'
                  + '<span class="tl-diag-dim"> / min ' + esc(d.min_staff) + ' ' + esc(i18n.simult_abbr) + '</span>'
                : '')
            + (d.understaffed ? ' <span class="tl-diag-warn">— ' + esc(i18n.understaffed_warn) + '</span>' : '')
            + '</div>';
        if (d.understaffed_ranges && d.understaffed_ranges.length) {
            html += '<div class="tl-diag-section tl-diag-section--under">⚠ ' + esc(i18n.understaffed_warn) + ' (' + d.understaffed_ranges.length + ')</div>';
            d.understaffed_ranges.forEach(function (r) { html += '<div class="tl-diag-item">· ' + esc(r) + '</div>'; });
        }
        if (d.conflicts && d.conflicts.length) {
            html += '<div class="tl-diag-section tl-diag-section--conflict">⚡ ' + esc(i18n.alert_conflicts) + ' (' + d.conflicts.length + ')</div>';
            d.conflicts.forEach(function (c) { html += '<div class="tl-diag-item">· ' + esc(c) + '</div>'; });
        }
        if (d.short && d.short.length) {
            html += '<div class="tl-diag-section tl-diag-section--short">⏱ ' + esc(i18n.alert_short_shifts) + ' (' + d.short.length + ')</div>';
            d.short.forEach(function (s) { html += '<div class="tl-diag-item">· ' + esc(s) + '</div>'; });
        }
        if (d.long && d.long.length) {
            html += '<div class="tl-diag-section tl-diag-section--long">⏰ ' + esc(i18n.alert_long_shifts) + ' (' + d.long.length + ')</div>';
            d.long.forEach(function (l) { html += '<div class="tl-diag-item">· ' + esc(l) + '</div>'; });
        }
        return html;
    }

    document.addEventListener('mouseenter', function (e) {
        if (!(e.target instanceof Element)) return;
        var badge = e.target.closest('.sd-diag-badge');
        if (!badge) return;
        var d;
        try { d = JSON.parse(badge.dataset.diag || '{}'); } catch (_) { return; }
        tip.innerHTML = renderDiag(d);
        var rect   = badge.getBoundingClientRect();
        var tipW   = 340, margin = 8;
        var left   = Math.min(rect.left, window.innerWidth - tipW - margin);
        tip.classList.add('open');
        tip.style.left = Math.max(margin, left) + 'px';
        tip.style.top  = (rect.bottom + 6) + 'px';
    }, true);

    document.addEventListener('mouseleave', function (e) {
        if (e.target instanceof Element && e.target.closest('.sd-diag-badge')) tip.classList.remove('open');
    }, true);

    document.addEventListener('scroll', function () { tip.classList.remove('open'); }, true);
}());
