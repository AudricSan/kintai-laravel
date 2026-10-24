(function () {
    'use strict';

    const list = document.getElementById('navSectionOrder');
    if (!list) return;

    // Détecte un écran tactile / mobile
    const isMobile = window.matchMedia('(pointer: coarse)').matches || navigator.maxTouchPoints > 0;

    // ── Flèches haut/bas (actives sur tous les appareils) ──────────────────────
    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.nav-order-btn');
        if (!btn) return;
        const item = btn.closest('.nav-order-item');
        if (!item) return;

        if (btn.classList.contains('nav-order-btn--up')) {
            const prev = item.previousElementSibling;
            if (prev) list.insertBefore(item, prev);
        } else if (btn.classList.contains('nav-order-btn--down')) {
            const next = item.nextElementSibling;
            if (next) next.after(item);
        }
    });

    // ── Drag & drop — desktop uniquement ──────────────────────────────────────
    if (isMobile) {
        list.querySelectorAll('.nav-order-item').forEach(function (item) {
            item.removeAttribute('draggable');
        });
        return;
    }

    let dragging = null;

    list.querySelectorAll('.nav-order-item').forEach(function (item) {
        item.addEventListener('dragstart', function () {
            dragging = item;
            item.classList.add('nav-order-item--dragging');
        });
        item.addEventListener('dragend', function () {
            item.classList.remove('nav-order-item--dragging');
            dragging = null;
        });
    });

    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragging) return;
        const after = getAfterElement(list, e.clientY);
        if (after === null) {
            list.appendChild(dragging);
        } else {
            list.insertBefore(dragging, after);
        }
    });

    function getAfterElement(container, y) {
        var items = Array.from(container.querySelectorAll('.nav-order-item:not(.nav-order-item--dragging)'));
        return items.reduce(function (closest, child) {
            var box    = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element || null;
    }
}());
