<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Résout la police CJK (japonais) à enregistrer auprès de mPDF pour qu'un même
 * PDF mélangeant caractères latins et japonais s'affiche correctement — les
 * polices "core" de mPDF ne couvrent pas les glyphes japonais, donc sans
 * police explicite ces caractères s'affichent en tofu (□). Les polices
 * retenues ici (Noto Sans JP, Yu Gothic, Meiryo, MS Gothic) couvrent aussi le
 * latin, donc les servir comme police par défaut ne dégrade pas le rendu du
 * texte français/anglais du même document.
 */
final class PdfCjkFontResolver
{
    /** @return array{dir: string[], data: array, default: string}|null */
    public static function resolve(): ?array
    {
        $customDir  = storage_path('fonts');
        $customFile = $customDir . '/NotoSansJP-Regular.ttf';
        if (file_exists($customFile)) {
            return [
                'dir'     => [$customDir],
                'data'    => ['notosansjp' => ['R' => 'NotoSansJP-Regular.ttf']],
                'default' => 'notosansjp',
            ];
        }

        $sysRoot  = rtrim((string) (getenv('SystemRoot') ?: 'C:/Windows'), '/\\');
        $winFonts = $sysRoot . '/Fonts';

        $candidates = [
            'YuGothR.ttc'  => ['fontName' => 'yugothic', 'ttcId' => 1],
            'meiryo.ttc'   => ['fontName' => 'meiryo',   'ttcId' => 1],
            'msgothic.ttc' => ['fontName' => 'msgothic', 'ttcId' => 1],
        ];

        foreach ($candidates as $file => $meta) {
            if (file_exists($winFonts . '/' . $file)) {
                return [
                    'dir'     => [$winFonts],
                    'data'    => [
                        $meta['fontName'] => [
                            'R'         => $file,
                            'TTCfontID' => ['R' => $meta['ttcId']],
                        ],
                    ],
                    'default' => $meta['fontName'],
                ];
            }
        }

        return null;
    }

    /** Fusionne la police CJK résolue (si disponible) dans une config mPDF existante. */
    public static function applyTo(array $mpdfConfig): array
    {
        $font = self::resolve();
        if ($font === null) {
            return $mpdfConfig;
        }

        $mpdfConfig['fontDir']      = $font['dir'];
        $mpdfConfig['fontdata']     = $font['data'];
        $mpdfConfig['default_font'] = $font['default'];

        return $mpdfConfig;
    }
}
