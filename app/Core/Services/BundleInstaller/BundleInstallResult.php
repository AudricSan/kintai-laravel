<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleInstaller;

use kintai\Core\BundleContract\BundleManifest;

final readonly class BundleInstallResult
{
    private function __construct(
        public bool $success,
        public ?string $error,
        public ?BundleManifest $manifest,
        public bool $activated,
    ) {
    }

    public static function failure(string $error): self
    {
        return new self(false, $error, null, false);
    }

    public static function dryRunOk(BundleManifest $manifest): self
    {
        return new self(true, null, $manifest, false);
    }

    public static function installed(BundleManifest $manifest): self
    {
        return new self(true, null, $manifest, true);
    }
}
