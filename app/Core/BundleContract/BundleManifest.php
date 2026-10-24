<?php

declare(strict_types=1);

namespace kintai\Core\BundleContract;

use kintai\Contract\Contract;
use kintai\Contract\VersionConstraint;

/**
 * Contenu parsé et validé du bundle.json d'un bundle installé dynamiquement
 * (voir docs/creating-a-bundle.md pour le format complet). Fait partie du
 * contrat stable au même titre que Bundle : un auteur de bundle s'engage sur
 * ces champs, ils ne changent pas sans annonce au CHANGELOG.
 */
final readonly class BundleManifest
{
    /** Racine PSR-4 du code du bundle, relative à la racine de son dépôt/archive. */
    private const SOURCE_ROOT = 'src';

    /**
     * @param array<string, string> $requiresBundles Slug de bundle => contrainte de version (informatif pour l'instant).
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $description,
        public string $namespace,
        public string $entryClass,
        public string $kintaiCoreMin,
        public string $kintaiCoreMax,
        public array $requiresBundles,
        // Contrainte semver sur la version du contrat (« kintai_contract » : « ^1.0 »), ou null si le bundle ne la
        // déclare pas : il est alors incompatible (bundle antérieur au contrat versionné). kintai_core.min/max n'est
        // plus qu'informatif.
        public ?string $contractConstraint = null,
    ) {
    }

    /**
     * Schéma de bundle.json : une phrase par défaut constaté, pour que l'auteur sache quoi corriger (installation,
     * CI du bundle). Liste vide = manifeste valide. `$expectedSlug` (nom du dépôt sans « kintai-bundle- », slug du
     * registry…) est comparé au slug déclaré quand il est fourni. `$requireContract` faux : l'absence de
     * « kintai_contract » n'est pas signalée ici (l'installation la refuse avec son propre message traduit, voir
     * incompatibilityReason()).
     *
     * @return list<string>
     */
    public static function validationErrors(mixed $data, ?string $expectedSlug = null, bool $requireContract = true): array
    {
        if (!is_array($data)) {
            return ['bundle.json n\'est pas un objet JSON valide.'];
        }

        $errors = [];
        foreach (['slug', 'name', 'version', 'namespace', 'entry_class', 'kintai_contract'] as $required) {
            if ($required === 'kintai_contract' && !$requireContract) {
                continue;
            }
            if (!isset($data[$required]) || !is_string($data[$required]) || trim($data[$required]) === '') {
                $errors[] = "Champ « $required » obligatoire (texte non vide).";
            }
        }

        $slug = is_string($data['slug'] ?? null) ? $data['slug'] : '';
        if ($slug !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors[] = "Le slug « $slug » doit être en minuscules, chiffres et tirets (ex. « daily-report »).";
        }
        if ($slug !== '' && $expectedSlug !== null && $slug !== $expectedSlug) {
            $errors[] = "Le slug « $slug » ne correspond pas au bundle attendu « $expectedSlug ».";
        }

        $version = is_string($data['version'] ?? null) ? $data['version'] : '';
        if ($version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            $errors[] = "La version « $version » doit être au format X.Y.Z.";
        }

        $constraint = is_string($data['kintai_contract'] ?? null) ? trim($data['kintai_contract']) : '';
        if ($constraint !== '' && VersionConstraint::parse($constraint) === null) {
            $errors[] = "La contrainte « kintai_contract » (« $constraint ») est illisible (ex. « ^1.25 »).";
        }

        $namespace = is_string($data['namespace'] ?? null) ? $data['namespace'] : '';
        $identifier = '[A-Za-z_][A-Za-z0-9_]*';
        if ($namespace !== '' && preg_match('/^' . $identifier . '(?:\\\\' . $identifier . ')*$/', $namespace) !== 1) {
            $errors[] = "Le namespace « $namespace » n'est pas un namespace PHP valide.";
        }
        $entry = is_string($data['entry_class'] ?? null) ? $data['entry_class'] : '';
        if ($entry !== '' && $namespace !== '' && !str_starts_with($entry, rtrim($namespace, '\\') . '\\')) {
            $errors[] = "La classe d'entrée « $entry » doit se trouver dans le namespace « $namespace ».";
        }

        foreach (['min', 'max'] as $bound) {
            $value = $data['kintai_core'][$bound] ?? null;
            if (isset($data['kintai_core']) && $value !== null && (!is_string($value) || preg_match('/^\d+\.\d+\.\d+$/', $value) !== 1)) {
                $errors[] = "« kintai_core.$bound » doit être une version X.Y.Z (informatif).";
            }
        }
        if (isset($data['kintai_core']) && !is_array($data['kintai_core'])) {
            $errors[] = "« kintai_core » doit être un objet {min, max}.";
        }

        if (isset($data['requires_bundles'])) {
            if (!is_array($data['requires_bundles'])) {
                $errors[] = "« requires_bundles » doit être un objet slug => contrainte.";
            } else {
                foreach ($data['requires_bundles'] as $requiredSlug => $requiredConstraint) {
                    if (!is_string($requiredSlug) || !is_string($requiredConstraint)) {
                        $errors[] = "« requires_bundles » : chaque entrée doit être « slug »: « contrainte ».";
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        foreach (['slug', 'name', 'version', 'namespace', 'entry_class'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                return null;
            }
        }

        $core = is_array($data['kintai_core'] ?? null) ? $data['kintai_core'] : [];
        $requiresBundles = is_array($data['requires_bundles'] ?? null) ? $data['requires_bundles'] : [];

        return new self(
            slug: $data['slug'],
            name: $data['name'],
            version: $data['version'],
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            namespace: $data['namespace'],
            entryClass: $data['entry_class'],
            kintaiCoreMin: is_string($core['min'] ?? null) ? $core['min'] : '0.0.0',
            kintaiCoreMax: is_string($core['max'] ?? null) ? $core['max'] : '999.999.999',
            requiresBundles: array_map('strval', $requiresBundles),
            contractConstraint: is_string($data['kintai_contract'] ?? null) && trim($data['kintai_contract']) !== ''
                ? trim($data['kintai_contract'])
                : null,
        );
    }

    /**
     * Raison pour laquelle ce bundle ne peut pas tourner sur ce Core, ou null s'il est compatible. Seule la
     * contrainte de contrat compte : un bundle qui n'en déclare pas date d'avant le contrat versionné et s'appuie
     * sur des classes internes du Core qui ont pu disparaître (son ancien intervalle kintai_core.min/max, toujours
     * ouvert, ne le protégeait pas) ; il est donc incompatible, à l'installation comme au démarrage.
     * $coreVersion est conservé pour la compatibilité de la signature.
     */
    public function incompatibilityReason(string $coreVersion, string $contractVersion = Contract::VERSION): ?string
    {
        if ($this->contractConstraint !== null) {
            $constraint = VersionConstraint::parse($this->contractConstraint);
            if ($constraint === null) {
                return __('bundle_contract_constraint_invalid', ['constraint' => $this->contractConstraint]);
            }
            return $constraint->allows($contractVersion)
                ? null
                : __('bundle_contract_incompatible', ['required' => $this->contractConstraint, 'provided' => $contractVersion]);
        }

        return __('bundle_contract_missing');
    }

    public function isCompatibleWithCore(string $coreVersion): bool
    {
        return version_compare($coreVersion, $this->kintaiCoreMin, '>=')
            && version_compare($coreVersion, $this->kintaiCoreMax, '<=');
    }

    /**
     * Chemin du fichier PHP correspondant à une classe de ce bundle sous
     * $bundleRootDir (racine où bundle.json lui-même se trouve), ou null si
     * la classe n'appartient pas au namespace déclaré par ce manifeste.
     */
    public function classFilePath(string $bundleRootDir, string $class): ?string
    {
        $prefix = rtrim($this->namespace, '\\') . '\\';
        if (!str_starts_with($class, $prefix)) {
            return null;
        }

        $relative = substr($class, strlen($prefix));
        return rtrim($bundleRootDir, '/') . '/' . self::SOURCE_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';
    }
}
