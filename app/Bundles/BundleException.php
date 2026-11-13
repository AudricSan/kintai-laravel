<?php

namespace App\Bundles;

use RuntimeException;

/**
 * Un bundle ne peut pas être activé ou désactivé ; le message est lisible par l'Owner.
 */
class BundleException extends RuntimeException
{
    public static function unknown(string $slug): self
    {
        return new self(__('The bundle ":slug" is not installed.', ['slug' => $slug]));
    }

    public static function notLicensed(string $name): self
    {
        return new self(__('The bundle ":name" is not included in your license. Upgrade your license to enable it.', ['name' => __($name)]));
    }

    public static function incompatible(string $name, string $required, string $installed): self
    {
        return new self(__('The bundle ":name" requires the Kintai contract ":required", but this installation provides version :installed. It cannot be enabled.', [
            'name' => __($name),
            'required' => $required,
            'installed' => $installed,
        ]));
    }
}
