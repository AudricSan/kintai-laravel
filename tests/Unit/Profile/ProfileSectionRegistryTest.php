<?php

namespace Tests\Unit\Profile;

use App\Models\User;
use App\Profile\DefaultProfileSectionRegistry;
use InvalidArgumentException;
use Kintai\Contracts\Profile\ProfileSection;
use PHPUnit\Framework\TestCase;

class ProfileSectionRegistryTest extends TestCase
{
    /**
     * @param  list<ProfileSection>  $sections
     * @return list<string>
     */
    private function keys(array $sections): array
    {
        return array_map(fn ($section): string => $section->key, $sections);
    }

    public function test_sections_are_sorted_by_order_then_registration(): void
    {
        $registry = new DefaultProfileSectionRegistry;
        $registry->register('c', 'C', 'c.route', 30);
        $registry->register('a', 'A', 'a.route', 10);
        $registry->register('b1', 'B1', 'b1.route');
        $registry->register('b2', 'B2', 'b2.route');
        $registry->register('first', 'First', 'f.route', 10);

        $this->assertSame(['a', 'first', 'c', 'b1', 'b2'], $this->keys($registry->sections(new User)));
    }

    public function test_duplicate_key_is_refused(): void
    {
        $registry = new DefaultProfileSectionRegistry;
        $registry->register('profile', 'Profile', 'profile.edit');

        $this->expectException(InvalidArgumentException::class);
        $registry->register('profile', 'Other', 'other');
    }

    public function test_sections_are_filtered_by_visibility(): void
    {
        $registry = new DefaultProfileSectionRegistry;
        $registry->register('always', 'Always', 'x');
        $registry->register('never', 'Never', 'y', 5, fn (User $user): bool => false);
        $registry->register('named', 'Named', 'z', 6, fn (User $user): bool => $user->name === 'Jane');

        $this->assertSame(['always'], $this->keys($registry->sections(new User)));
        $this->assertSame(['named', 'always'], $this->keys($registry->sections(new User(['name' => 'Jane']))));
    }
}
