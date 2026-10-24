<?php

declare(strict_types=1);

namespace kintai\Core\Mail;

/**
 * En-têtes supplémentaires d'un e-mail (List-Unsubscribe…). Un nom ou une valeur contenant un saut de ligne
 * permettrait d'injecter d'autres en-têtes (ou un corps) dans le message : ces en-têtes sont écartés.
 */
final class MailHeaders
{
    /**
     * @param array<string, string> $headers nom => valeur
     * @return list<string> lignes « Nom: valeur », sans saut de ligne final
     */
    public static function format(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $name  = (string) $name;
            $value = (string) $value;
            if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }
}
