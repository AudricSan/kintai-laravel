'use strict';
/* ── Timeline : drag & drop (déplacer / créer des shifts) ── */
(function () {
    /* ── Configuration ─────────────────────────── */
    var _cfgEl = document.getElementById('kintai-timeline-data');
    var cfg   = _cfgEl ? JSON.parse(_cfgEl.textContent || '{}') : (window.KintaiTimeline || {});
    var BASE  = cfg.baseUrl   || '';
    var CSRF  = cfg.csrfToken || '';
    var i18n  = cfg.i18n      || {};
    var USERS = cfg.users  || [];
    var T_START = 360, T_TOTAL = 1440;

    /* ── Helpers mathématiques ──────────────────── */
    function minToStr(min) {
        var m = ((Math.round(min) % 1440) + 1440) % 1440;
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    }
    function snap15(min) { return Math.round(min / 15) * 15; }
    function xToMin(x, w) { return Math.max(0, Math.min(1, x / w)) * T_TOTAL + T_START; }

    var _drag = null, _blockNextClick = false;

    /* Détecte un écran tactile / mobile : par sécurité, on désactive le
       déplacement d'un shift existant au doigt (risque de glissement
       accidentel) — le tap continue d'ouvrir le détail du shift. */
    var isMobile = window.matchMedia('(pointer: coarse)').matches || navigator.maxTouchPoints > 0;

    /* ── Début du drag (pointerdown : souris, tactile, stylet) ── */
    document.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        if (!(e.target instanceof Element)) return;

        /* Drag d'une barre existante (déplacement) — désactivé sur mobile */
        var bar = isMobile ? null : e.target.closest('[data-draggable="1"]');
        if (bar) {
            var lane   = bar.closest('.sd-lane');
            if (!lane) return;
            var rect   = lane.getBoundingClientRect();
            var sParts = (bar.dataset.start || '00:00').split(':');
            var eParts = (bar.dataset.end   || '00:00').split(':');
            var sMin   = parseInt(sParts[0]) * 60 + parseInt(sParts[1]);
            var eMin   = parseInt(eParts[0]) * 60 + parseInt(eParts[1]);
            var dur    = eMin >= sMin ? eMin - sMin : eMin + 1440 - sMin;
            var normS  = sMin < T_START ? sMin + 1440 : sMin;
            var clickMin = xToMin(e.clientX - rect.left, rect.width);
            _drag = {
                type: 'move', bar: bar, lane: lane, rect: rect,
                startX: e.clientX, moved: false,
                id: bar.dataset.id, date: bar.dataset.date,
                color: bar.dataset.color || '#6366f1',
                widthPct: parseFloat(bar.dataset.widthPct || '10'),
                dur: dur, normS: normS, offsetMin: clickMin - normS,
                ghost: null, newStart: null, newEnd: null,
            };
            bar.setPointerCapture(e.pointerId);
            e.preventDefault();
            return;
        }

        /* Création d'un nouveau shift (zone vide) */
        var zone = e.target.closest('.sd-create-zone');
        if (zone) {
            var dayCard = zone.closest('.sd-day-card');
            var rect2   = zone.getBoundingClientRect();
            var sMin2   = snap15(xToMin(e.clientX - rect2.left, rect2.width));
            _drag = {
                type: 'create', zone: zone, dayCard: dayCard,
                rect: rect2, startX: e.clientX, moved: false,
                startMin: sMin2, endMin: sMin2 + 60, ghost: null,
            };
            zone.setPointerCapture(e.pointerId);
            e.preventDefault();
        }
    }, true);

    /* ── Pendant le drag (pointermove) ────────────── */
    document.addEventListener('pointermove', function (e) {
        if (!_drag) return;
        if (!_drag.moved && Math.abs(e.clientX - _drag.startX) > 5) {
            _drag.moved = true;
            if (_drag.type === 'move') {
                _drag.bar.style.opacity = '0.4';
                var g = document.createElement('div');
                g.style.cssText = 'position:absolute;top:3px;height:26px;border-radius:5px;box-sizing:border-box;pointer-events:none;z-index:20;border:2px dashed rgba(255,255,255,.7);opacity:.82';
                g.style.background = _drag.color;
                g.style.width = _drag.widthPct + '%';
                _drag.lane.appendChild(g);
                _drag.ghost = g;
            } else {
                var g2 = document.createElement('div');
                g2.style.cssText = 'position:absolute;top:2px;bottom:2px;border-radius:5px;box-sizing:border-box;pointer-events:none;z-index:10;background:rgba(76,175,80,.22);border:2px dashed #4caf50';
                _drag.zone.appendChild(g2);
                _drag.ghost = g2;
            }
        }
        if (!_drag.moved) return;
        if (_drag.type === 'move') {
            var x    = e.clientX - _drag.rect.left;
            var cMin = xToMin(x, _drag.rect.width);
            var newS = ((snap15(cMin - _drag.offsetMin) % 1440) + 1440) % 1440;
            _drag.newStart = newS;
            _drag.newEnd   = (newS + _drag.dur) % 1440;
            var normNew = newS < T_START ? newS + 1440 : newS;
            var lPct    = Math.max(0, Math.min(99, (normNew - T_START) / T_TOTAL * 100));
            if (_drag.ghost) _drag.ghost.style.left = lPct + '%';
        } else {
            var x3  = Math.max(0, e.clientX - _drag.rect.left);
            var cur = snap15(xToMin(x3, _drag.rect.width));
            _drag.endMin = cur > _drag.startMin + 14 ? cur : _drag.startMin + 30;
            var lP  = Math.max(0, Math.min(99, (_drag.startMin - T_START) / T_TOTAL * 100));
            var wP  = Math.max(0.5, Math.min(100 - lP, (_drag.endMin - _drag.startMin) / T_TOTAL * 100));
            if (_drag.ghost) { _drag.ghost.style.left = lP + '%'; _drag.ghost.style.width = wP + '%'; }
        }
    });

    /* ── Déplacement d'un shift ──────────────────────
       Si l'employé s'est déclaré non disponible ce jour-là, le serveur répond 409 « availability_conflict » : on
       affiche l'encadré (Annuler en action principale) et « Forcer l'horaire » rejoue la requête avec le drapeau. */
    var _pendingMove = null;

    function sendMove(id, payload) {
        fetch(BASE + '/admin/shifts/' + id + '/move', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(payload),
        }).then(function (r) {
            if (r.ok) { window.location.reload(); return; }
            r.json().then(function (d) {
                if (r.status === 409 && d.code === 'availability_conflict' && !payload.force_availability) {
                    askAvailability(d.error, id, payload);
                    return;
                }
                alert(i18n.error_prefix + (d.error || r.status));
            });
        }).catch(function () { alert(i18n.network_error); });
    }

    function askAvailability(message, id, payload) {
        _pendingMove = { id: id, payload: payload };
        var el = document.getElementById('availability-message');
        if (el) el.textContent = message || '';
        if (window.openModal) window.openModal('availability-overlay');
    }

    window.availabilityClose = function () {
        _pendingMove = null;
        if (window.closeModal) window.closeModal('availability-overlay');
    };

    window.availabilityForce = function () {
        var pending = _pendingMove;
        _pendingMove = null;
        if (window.closeModal) window.closeModal('availability-overlay');
        if (pending) {
            pending.payload.force_availability = true;
            sendMove(pending.id, pending.payload);
        }
    };

    /* ── Fin du drag (pointerup) ──────────────────── */
    document.addEventListener('pointerup', function () {
        if (!_drag) return;
        var drag = _drag;
        _drag = null;
        if (drag.ghost) drag.ghost.remove();
        if (drag.type === 'move') {
            drag.bar.style.opacity = '1';
            if (!drag.moved) return;
            _blockNextClick = true;
            if (drag.newStart === null) return;
            sendMove(drag.id, {
                shift_date: drag.date,
                start_time: minToStr(drag.newStart),
                end_time:   minToStr(drag.newEnd),
            });
        } else {
            var date = drag.dayCard ? drag.dayCard.dataset.date : '';
            sdQcOpen(date, minToStr(drag.startMin % 1440), minToStr(drag.endMin % 1440));
        }
    });

    /* ── Bloque le clic après un drag pour éviter le détail shift ── */
    document.addEventListener('click', function (e) {
        if (_blockNextClick) {
            _blockNextClick = false;
            e.stopImmediatePropagation();
            e.preventDefault();
        }
    }, true);

    /* ── Modal création rapide : ouverture ──────── */
    window.sdQcOpen = function (date, startTime, endTime) {
        var userSel = document.querySelector('#qc-form select[name="user_id"]');
        if (!userSel) return;
        var d = date || new Date().toISOString().slice(0, 10);
        userSel.value = '';
        USERS.forEach(function (u) {
            var opt = userSel.querySelector('option[value="' + u.id + '"]');
            if (!opt) {
                opt = document.createElement('option');
                opt.value = u.id;
                userSel.appendChild(opt);
            }
            opt.textContent = u.name;
            opt.dataset.storeId = u.store_id;
        });
        document.querySelector('#qc-form input[name="shift_date"]').value = d;
        document.querySelector('#qc-form input[name="start_time"]').value = startTime || '';
        document.querySelector('#qc-form input[name="end_time"]').value   = endTime   || '';
        document.getElementById('qc-overlay').classList.add('open');
    };

    /* ── Modal création rapide : fermeture ──────── */
    window.sdQcClose = function () {
        var overlay = document.getElementById('qc-overlay');
        if (overlay) overlay.classList.remove('open');
    };

    /* ── Soumission du formulaire quick-create : POST natif, pas d'AJAX ── */
    var qcForm = document.getElementById('qc-form');
    if (qcForm) {
        qcForm.addEventListener('submit', function () {
            var btn = document.getElementById('qc-submit');
            btn.disabled = true;
            btn.textContent = i18n.creating;
        });
    }
}());
