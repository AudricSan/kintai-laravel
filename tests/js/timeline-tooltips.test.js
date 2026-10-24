// Infobulles de la timeline : les noms d'employés et de types de shift sont saisis par des administrateurs ou des
// managers ; ils doivent s'afficher comme du texte, jamais être interprétés comme du HTML.
//
// Lancer : node --test tests/js/*.test.js (jsdom requis, voir csp-actions.test.js).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

let JSDOM = null;
try {
    ({ JSDOM } = require('jsdom'));
} catch (e) {
    // jsdom absent : tests ignorés.
}
const skip = JSDOM ? false : 'jsdom non installé';

const MODULES = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'modules');
const PAYLOAD = '<img src=x id="injected">';

function load(html, moduleFile) {
    const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { url: 'http://kintai.test/', runScripts: 'outside-only' });
    dom.window.eval(fs.readFileSync(path.join(MODULES, moduleFile), 'utf8'));
    return dom.window;
}

const attr = (value) => JSON.stringify(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

test('diagnostic de la timeline : un nom d\'employé piégé reste du texte', { skip }, () => {
    const diag = {
        staff: 3, min_staff: 2, peak: 1, understaffed: true,
        understaffed_ranges: ['09:00–10:00 (1/2)'],
        conflicts: [PAYLOAD + ' : 09:00–17:00 / 12:00–18:00'],
        short: [PAYLOAD + ' 09:00–10:00 (1h00)'],
        long: [PAYLOAD + ' 06:00–22:00 (16h00)'],
    };
    const cfg = { i18n: { staff_planned_label: ' prévus', alert_conflicts: 'Conflits', alert_short_shifts: 'Courts', alert_long_shifts: 'Longs', understaffed_warn: 'Sous-effectif', peak_abbr: 'pic', simult_abbr: 'simult.' } };
    const w = load(
        `<script type="application/json" id="kintai-timeline-data">${JSON.stringify(cfg)}</script>`
        + `<span class="sd-diag-badge" data-diag="${attr(diag)}">⚠</span>`,
        'timeline-diag.js',
    );
    const badge = w.document.querySelector('.sd-diag-badge');
    badge.dispatchEvent(new w.MouseEvent('mouseenter', { bubbles: false }));

    const tip = w.document.getElementById('sd-diag-tip');
    assert.equal(tip.querySelector('#injected'), null, 'aucun élément injecté');
    assert.ok(tip.textContent.includes(PAYLOAD), 'le nom est affiché tel quel, comme du texte');
    assert.equal(tip.querySelectorAll('.tl-diag-item').length, 4);
});

test('diagnostic : un libellé de traduction piégé reste aussi du texte', { skip }, () => {
    const cfg = { i18n: { staff_planned_label: PAYLOAD } };
    const w = load(
        `<script type="application/json" id="kintai-timeline-data">${JSON.stringify(cfg)}</script>`
        + `<span class="sd-diag-badge" data-diag="${attr({ staff: 2, min_staff: 0 })}">⚠</span>`,
        'timeline-diag.js',
    );
    w.document.querySelector('.sd-diag-badge').dispatchEvent(new w.MouseEvent('mouseenter'));

    assert.equal(w.document.querySelector('#injected'), null);
});

test('détail de paie d\'un shift : un nom de type de shift piégé reste du texte', { skip }, () => {
    const ids = ['sd-dot', 'sd-name', 'sd-date', 'sd-time', 'sd-hours', 'sd-type', 'sd-rate-block', 'sd-rate-rows',
        'sd-pay-row', 'sd-no-rate', 'sd-pay', 'sd-notes-row', 'sd-notes', 'sd-overlay'];
    const cfg = { canManage: true, baseUrl: '' };
    const w = load(
        `<script type="application/json" id="kintai-timeline-data">${JSON.stringify(cfg)}</script>`
        + ids.map((id) => `<div id="${id}"></div>`).join('')
        + '<a id="sd-edit-link"></a><form id="sd-delete-form"></form>',
        'shift-detail-modal.js',
    );
    const el = w.document.createElement('div');
    el.dataset.id = '1';
    el.dataset.name = 'Employé';
    el.dataset.rateDetail = JSON.stringify([{ type_name: PAYLOAD, rate_fmt: '1 200 ¥/h', minutes: 480, pay_fmt: '9 600 ¥', has_rate: true }]);
    w.sdModalOpen(el);

    const rows = w.document.getElementById('sd-rate-rows');
    assert.equal(rows.querySelector('#injected'), null, 'aucun élément injecté');
    assert.ok(rows.textContent.includes(PAYLOAD));
    assert.ok(rows.textContent.includes('9 600 ¥'));
    assert.equal(rows.querySelectorAll('.pay-label').length, 1);
});
