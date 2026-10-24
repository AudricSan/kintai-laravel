<?php

declare(strict_types=1);

namespace kintai\Core\Install;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * Échec de l'installation, porteur d'une clé de traduction lisible par la personne qui installe. Le détail technique
 * (requête SQL, chemins, trace) ne quitte jamais le serveur : il est écrit dans storage/logs/error.log.
 */
final class InstallFailure extends RuntimeException
{
    /** @param array<string, string> $replace valeurs insérées dans le message traduit (:name) */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $replace = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }

    /**
     * Traduit une exception quelconque en échec lisible. Les erreurs MySQL les plus courantes à l'installation
     * (serveur injoignable, identifiants refusés, droits insuffisants) ont leur propre message ; le reste tombe sur
     * le message générique qui renvoie au journal.
     */
    public static function from(Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (!$cause instanceof PDOException) {
                continue;
            }
            $code = self::driverCode($cause);
            $key = match (true) {
                in_array($code, [2002, 2003, 2005, 2006], true) => 'install_error_mysql_unreachable',
                $code === 1045                                   => 'install_error_mysql_access_denied',
                in_array($code, [1044, 1142, 1227], true)        => 'install_error_mysql_privileges',
                default                                          => null,
            };
            if ($key !== null) {
                return new self($key, [], $e);
            }
        }

        return new self('install_error_generic', [], $e);
    }

    /** Code d'erreur du pilote (ex. 2002, 1045) : errorInfo pour une requête, « [1045] » dans le message à la connexion. */
    private static function driverCode(PDOException $e): ?int
    {
        if (isset($e->errorInfo[1]) && is_int($e->errorInfo[1])) {
            return $e->errorInfo[1];
        }
        return preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
    }
}
