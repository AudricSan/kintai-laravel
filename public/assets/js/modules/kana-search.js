/**
 * Portage JS de kintai\Core\Services\KanaSearch (PHP) : recherche de noms
 * tolérante à la langue, utilisée pour le filtrage client (sans aller-retour
 * serveur) des listes d'employés. Toute modification de la table romaji ou
 * de la logique de correspondance doit être répercutée des deux côtés.
 */
window.KanaSearch = (function () {
    'use strict';

    var ROMAJI_TO_HIRAGANA = {
        kya: 'きゃ', kyu: 'きゅ', kyo: 'きょ',
        gya: 'ぎゃ', gyu: 'ぎゅ', gyo: 'ぎょ',
        sha: 'しゃ', shu: 'しゅ', sho: 'しょ',
        sya: 'しゃ', syu: 'しゅ', syo: 'しょ',
        ja: 'じゃ', ju: 'じゅ', jo: 'じょ',
        zya: 'じゃ', zyu: 'じゅ', zyo: 'じょ',
        cha: 'ちゃ', chu: 'ちゅ', cho: 'ちょ',
        tya: 'ちゃ', tyu: 'ちゅ', tyo: 'ちょ',
        nya: 'にゃ', nyu: 'にゅ', nyo: 'にょ',
        hya: 'ひゃ', hyu: 'ひゅ', hyo: 'ひょ',
        bya: 'びゃ', byu: 'びゅ', byo: 'びょ',
        pya: 'ぴゃ', pyu: 'ぴゅ', pyo: 'ぴょ',
        mya: 'みゃ', myu: 'みゅ', myo: 'みょ',
        rya: 'りゃ', ryu: 'りゅ', ryo: 'りょ',

        shi: 'し', chi: 'ち', tsu: 'つ',

        ka: 'か', ki: 'き', ku: 'く', ke: 'け', ko: 'こ',
        ga: 'が', gi: 'ぎ', gu: 'ぐ', ge: 'げ', go: 'ご',
        sa: 'さ', si: 'し', su: 'す', se: 'せ', so: 'そ',
        za: 'ざ', ji: 'じ', zi: 'じ', zu: 'ず', ze: 'ぜ', zo: 'ぞ',
        ta: 'た', ti: 'ち', tu: 'つ', te: 'て', to: 'と',
        da: 'だ', di: 'ぢ', du: 'づ', de: 'で', do: 'ど',
        na: 'な', ni: 'に', nu: 'ぬ', ne: 'ね', no: 'の',
        ha: 'は', hi: 'ひ', fu: 'ふ', hu: 'ふ', he: 'へ', ho: 'ほ',
        ba: 'ば', bi: 'び', bu: 'ぶ', be: 'べ', bo: 'ぼ',
        pa: 'ぱ', pi: 'ぴ', pu: 'ぷ', pe: 'ぺ', po: 'ぽ',
        ma: 'ま', mi: 'み', mu: 'む', me: 'め', mo: 'も',
        ya: 'や', yu: 'ゆ', yo: 'よ',
        ra: 'ら', ri: 'り', ru: 'る', re: 'れ', ro: 'ろ',
        wa: 'わ', wo: 'を',

        a: 'あ', i: 'い', u: 'う', e: 'え', o: 'お',
        n: 'ん',
    };

    var SOKUON_CONSONANTS = ['k', 'g', 's', 'z', 't', 'd', 'h', 'b', 'p', 'm', 'r', 'y', 'w', 'j', 'c', 'f'];

    function romajiToHiragana(romaji) {
        romaji = romaji.replace(/[\s'-]+/g, '');
        var result = '';
        var i = 0;
        var length = romaji.length;

        while (i < length) {
            if (
                i + 1 < length &&
                romaji[i] === romaji[i + 1] &&
                SOKUON_CONSONANTS.indexOf(romaji[i]) !== -1
            ) {
                result += 'っ';
                i++;
                continue;
            }

            var matched = false;
            for (var chunkLength = 3; chunkLength >= 1; chunkLength--) {
                if (i + chunkLength > length) continue;
                var chunk = romaji.substr(i, chunkLength);
                if (ROMAJI_TO_HIRAGANA.hasOwnProperty(chunk)) {
                    result += ROMAJI_TO_HIRAGANA[chunk];
                    i += chunkLength;
                    matched = true;
                    break;
                }
            }

            if (!matched) i++;
        }

        return result;
    }

    /** Hiragana → katakana ; katakana/kanji/latin traversent inchangés. */
    function toKatakana(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            // Hiragana U+3041–U+3096 → katakana (décalage +0x60)
            out += (code >= 0x3041 && code <= 0x3096) ? String.fromCharCode(code + 0x60) : text[i];
        }
        return out;
    }

    function romajiToKatakana(text) {
        text = text.toLowerCase().trim();
        if (text === '' || !/^[a-z\s'-]+$/.test(text)) return '';
        var hiragana = romajiToHiragana(text);
        return hiragana === '' ? '' : toKatakana(hiragana);
    }

    /**
     * @param {string} needle
     * @param {string[]} texts     Champs littéraux (nom, email, code...).
     * @param {string[]} readings  Champs de lecture (furigana, katakana).
     */
    function matches(needle, texts, readings) {
        needle = (needle || '').trim();
        if (needle === '') return true;

        var needleLower = needle.toLowerCase();
        for (var t = 0; t < texts.length; t++) {
            if (texts[t] && texts[t].toLowerCase().indexOf(needleLower) !== -1) return true;
        }

        readings = readings || [];
        if (readings.length === 0) return false;

        var needleKatakana   = toKatakana(needle);
        var needleFromRomaji = romajiToKatakana(needle);

        for (var r = 0; r < readings.length; r++) {
            if (!readings[r]) continue;
            var readingKatakana = toKatakana(readings[r]);
            if (readingKatakana.indexOf(needleKatakana) !== -1) return true;
            if (needleFromRomaji !== '' && readingKatakana.indexOf(needleFromRomaji) !== -1) return true;
        }

        return false;
    }

    return { toKatakana: toKatakana, romajiToKatakana: romajiToKatakana, matches: matches };
})();
