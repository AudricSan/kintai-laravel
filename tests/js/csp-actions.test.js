// Tests de public/assets/js/modules/csp-actions.js et de la partie data-confirm de confirm-modal.js.
//
// Lancer : node --test tests/js/*.test.js
// Nécessite jsdom (npm install --no-save jsdom) ; sans lui les tests sont ignorés au lieu d'échouer, le
// dépôt n'ayant volontairement pas de package.json (pas de build, pas de dépendance front).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

let JSDOM = null;
let VirtualConsole = null;
try {
    ({ JSDOM, VirtualConsole } = require('jsdom'));
} catch (e) {
    // jsdom absent : voir l'en-tête.
}

const MODULES = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'modules');
const read = (name) => fs.readFileSync(path.join(MODULES, name), 'utf8');

/** Charge une page de test dans jsdom avec les modules donnés ; `setup` définit les fonctions globales de la page. */
function load(html, modules, setup) {
    // jsdom n'implémente pas la navigation : chaque tentative de quitter la page (hors ancre #) est signalée ici.
    const navigations = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('jsdomError', (e) => { if (/navigation/i.test(e.message)) navigations.push(e.message); });
    const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { url: 'http://kintai.test/page', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
    const w = dom.window;
    w.navigations = navigations;
    w.calls = [];
    if (setup) setup(w);
    modules.forEach((m) => w.eval(read(m)));
    return w;
}

const click = (w, el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
const change = (w, el) => el.dispatchEvent(new w.Event('change', { bubbles: true }));

const skip = JSDOM ? false : 'jsdom non installé';

test('data-on-click appelle la fonction globale avec ses arguments JSON', { skip }, () => {
    const w = load('<button id="b" data-on-click="pick" data-args=\'["a", 2, false]\'></button>', ['csp-actions.js'],
        (win) => { win.eval('function pick(){ calls.push([].slice.call(arguments)); }'); });
    click(w, w.document.getElementById('b'));
    assert.equal(JSON.stringify(w.calls), JSON.stringify([['a', 2, false]]));
});

test('les jetons @this / @value / @checked sont remplacés', { skip }, () => {
    const w = load('<input id="i" type="checkbox" checked value="v1" data-on-change="f" data-args=\'["@this","@value","@checked"]\'>', ['csp-actions.js'],
        (win) => { win.eval('function f(a,b,c){ calls.push([a.id,b,c]); }'); });
    change(w, w.document.getElementById('i'));
    assert.equal(JSON.stringify(w.calls), JSON.stringify([['i', 'v1', true]]));
});

test('data-stop-propagation empêche data-goto et data-on-click des ancêtres', { skip }, () => {
    const w = load('<div id="row" data-goto="#row-clicked" data-on-click="outer"><button id="stop" data-stop-propagation></button><button id="go"></button></div>', ['csp-actions.js'],
        (win) => { win.eval('function outer(){ calls.push("outer"); }'); });
    click(w, w.document.getElementById('stop'));
    assert.equal(JSON.stringify(w.calls), JSON.stringify([]));
    assert.equal(w.location.hash, '');
    click(w, w.document.getElementById('go'));
    assert.equal(JSON.stringify(w.calls), JSON.stringify(['outer']));
    assert.equal(w.location.hash, '#row-clicked');
});

test('data-submit-on-change soumet le formulaire de l\'élément', { skip }, () => {
    const w = load('<form id="f"><select id="s" data-submit-on-change><option>1</option></select></form>', ['csp-actions.js']);
    let submitted = 0;
    w.HTMLFormElement.prototype.submit = function () { submitted += 1; };
    change(w, w.document.getElementById('s'));
    assert.equal(submitted, 1);
});

test('data-submit-form soumet le formulaire désigné par son id', { skip }, () => {
    const w = load('<form id="target"></form><input id="c" type="checkbox" data-submit-form="target">', ['csp-actions.js']);
    let submittedId = null;
    w.HTMLFormElement.prototype.submit = function () { submittedId = this.id; };
    change(w, w.document.getElementById('c'));
    assert.equal(submittedId, 'target');
});

test('un lien <a href="#"> avec data-on-click ne navigue pas', { skip }, () => {
    const w = load('<a id="a" href="#" data-on-click="f"></a>', ['csp-actions.js'], (win) => { win.eval('function f(){ calls.push("f"); }'); });
    const event = new w.MouseEvent('click', { bubbles: true, cancelable: true });
    w.document.getElementById('a').dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(JSON.stringify(w.calls), JSON.stringify(['f']));
});

test('actions intégrées : @cssVar, @toggleRows, @setFormAction, @removeParent', { skip }, () => {
    const w = load(`
        <span id="chip"></span>
        <input id="color" data-on-input="@cssVar" data-target="chip" data-var="--chip-bg" value="#ff0000">
        <input id="show" type="checkbox" data-on-change="@toggleRows" data-target=".gone">
        <div class="gone"></div>
        <form id="addForm" action="/x"></form>
        <select id="sel" data-on-change="@setFormAction" data-target="addForm" data-action-template="/admin/stores/{value}/members"><option value="7" selected>7</option></select>
        <div><button id="rm" data-on-click="@removeParent"></button></div>`, ['csp-actions.js']);
    const d = w.document;
    d.getElementById('color').dispatchEvent(new w.Event('input', { bubbles: true }));
    assert.equal(d.getElementById('chip').style.getPropertyValue('--chip-bg'), '#ff0000');

    change(w, d.getElementById('show'));
    assert.equal(d.querySelector('.gone').style.display, 'none');
    d.getElementById('show').checked = true;
    change(w, d.getElementById('show'));
    assert.equal(d.querySelector('.gone').style.display, '');

    change(w, d.getElementById('sel'));
    assert.equal(d.getElementById('addForm').getAttribute('action'), '/admin/stores/7/members');

    click(w, d.getElementById('rm'));
    assert.equal(d.getElementById('rm'), null);
});

test('SÉCURITÉ : une fonction native (eval, setTimeout, open…) ne peut pas être appelée par un attribut', { skip }, () => {
    const w = load('<button id="b" data-on-click="eval" data-args=\'["window.pwned = 1"]\'></button><button id="t" data-on-click="setTimeout" data-args=\'["window.pwned = 2", 0]\'></button>', ['csp-actions.js']);
    click(w, w.document.getElementById('b'));
    click(w, w.document.getElementById('t'));
    assert.equal(w.pwned, undefined);
});

test('SÉCURITÉ : seules les propriétés propres de window sont appelables (constructor, __proto__…)', { skip }, () => {
    const w = load('<button id="b" data-on-click="constructor"></button><button id="c" data-on-click="toString"></button>', ['csp-actions.js']);
    assert.doesNotThrow(() => { click(w, w.document.getElementById('b')); click(w, w.document.getElementById('c')); });
});

test('SÉCURITÉ : data-goto refuse javascript: et data:', { skip }, () => {
    const w = load('<a id="j" data-goto="javascript:window.pwned=1"></a><a id="d" data-goto="data:text/html,x"></a><a id="ok" data-goto="#fine"></a>', ['csp-actions.js']);
    click(w, w.document.getElementById('j'));
    click(w, w.document.getElementById('d'));
    assert.equal(w.pwned, undefined);
    assert.equal(w.navigations.length, 0, 'aucune navigation vers javascript: ni data:');
    assert.equal(w.location.hash, '');
    click(w, w.document.getElementById('ok'));
    assert.equal(w.location.hash, '#fine');
});

test('data-goto navigue vers une adresse http(s) (témoin positif du test précédent)', { skip }, () => {
    const w = load('<a id="h" data-goto="http://kintai.test/admin/users"></a>', ['csp-actions.js']);
    click(w, w.document.getElementById('h'));
    assert.equal(w.navigations.length, 1);
});

test('SÉCURITÉ : les arguments ne sont jamais évalués comme du code', { skip }, () => {
    const w = load('<button id="b" data-on-click="f" data-args=\'["window.pwned = 1"]\'></button>', ['csp-actions.js'],
        (win) => { win.eval('function f(x){ calls.push(x); }'); });
    click(w, w.document.getElementById('b'));
    assert.equal(JSON.stringify(w.calls), JSON.stringify(['window.pwned = 1']));
    assert.equal(w.pwned, undefined);
});

test('confirm-modal : data-confirm sur le formulaire ouvre la modale et ne soumet pas', { skip }, () => {
    const w = load('<form id="f" data-confirm="Sûr ?"><button id="del" type="submit">x</button></form><p id="global-confirm-message"></p><button id="global-confirm-submit-btn"></button>', ['confirm-modal.js'],
        (win) => { win.openModal = (id) => win.calls.push('open:' + id); win.closeModal = (id) => win.calls.push('close:' + id); });
    let submitted = null;
    w.HTMLFormElement.prototype.requestSubmit = function (submitter) { submitted = submitter ? submitter.id : 'none'; };
    const event = new w.MouseEvent('click', { bubbles: true, cancelable: true });
    w.document.getElementById('del').dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(w.document.getElementById('global-confirm-message').textContent, 'Sûr ?');
    assert.equal(JSON.stringify(w.calls), JSON.stringify(['open:global-confirm-modal']));
    assert.equal(submitted, null, 'rien n\'est soumis avant la confirmation');
    click(w, w.document.getElementById('global-confirm-submit-btn'));
    assert.equal(submitted, 'none');
});

test('confirm-modal : data-confirm porté par un bouton rattaché par form="…" conserve ce bouton à la soumission', { skip }, () => {
    const w = load('<form id="remote"></form><button id="rm" type="submit" form="remote" formaction="/a/b" data-confirm="Retirer ?">×</button><p id="global-confirm-message"></p><button id="global-confirm-submit-btn"></button>', ['confirm-modal.js'],
        (win) => { win.openModal = () => {}; win.closeModal = () => {}; });
    let submitter = null;
    w.HTMLFormElement.prototype.requestSubmit = function (s) { submitter = s ? s.id : 'none'; };
    click(w, w.document.getElementById('rm'));
    assert.equal(w.document.getElementById('global-confirm-message').textContent, 'Retirer ?');
    click(w, w.document.getElementById('global-confirm-submit-btn'));
    assert.equal(submitter, 'rm', 'le bouton est transmis à requestSubmit (formaction, name/value conservés)');
});

test('confirm-modal : un bouton sans data-confirm n\'est pas intercepté', { skip }, () => {
    const w = load('<form id="f"><button id="ok" type="submit">x</button></form>', ['confirm-modal.js']);
    const event = new w.MouseEvent('click', { bubbles: true, cancelable: true });
    w.document.getElementById('ok').dispatchEvent(event);
    assert.equal(event.defaultPrevented, false);
});
