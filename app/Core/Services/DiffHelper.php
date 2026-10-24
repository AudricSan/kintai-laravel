<?php

declare(strict_types=1);

namespace kintai\Core\Services;

final class DiffHelper
{
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($keys as $key) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            if ($oldVal !== $newVal) {
                $changes[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $changes;
    }
}
