<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\AppSetting as EloquentAppSetting;

final class DatabaseAppSettingsRepository implements AppSettingsRepositoryInterface
{
    public function __construct() {}

    public function all(): array
    {
        $rows = EloquentAppSetting::all();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['key']] = $row['value'];
        }
        return $map;
    }

    public function get(string $key): ?string
    {
        $record = EloquentAppSetting::find($key);
        return $record ? $record->value : null;
    }

    public function set(string $key, string $value): void
    {
        EloquentAppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set((string) $key, (string) $value);
        }
    }
}
