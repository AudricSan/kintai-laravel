<?php

namespace App\Authorization;

use InvalidArgumentException;

/**
 * Catalogue des permissions : le corps et les bundles y déclarent leurs catégories.
 */
class PermissionRegistry
{
    /** @var array<string, list<string>> */
    private array $categories = [];

    /** @var array<string, true> */
    private array $keys = [];

    /**
     * @param  list<string>  $actions
     */
    public function register(string $category, array $actions): void
    {
        if (! preg_match('/^[a-z_]+$/', $category)) {
            throw new InvalidArgumentException("Invalid permission category [{$category}].");
        }

        if (isset($this->categories[$category])) {
            throw new InvalidArgumentException("Permission category [{$category}] is already registered.");
        }

        foreach ($actions as $action) {
            if (! preg_match('/^[a-z_]+$/', $action)) {
                throw new InvalidArgumentException("Invalid permission action [{$action}] in category [{$category}].");
            }
        }

        $this->categories[$category] = $actions;

        foreach ($actions as $action) {
            $this->keys[$category.'.'.$action] = true;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->keys);
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }
}
