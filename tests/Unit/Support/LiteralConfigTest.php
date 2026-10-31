<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LiteralConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Audit SEC-05 : le fichier de configuration d'une instance importée est lu comme des données, jamais exécuté. */
final class LiteralConfigTest extends TestCase
{
    public function testALiteralConfigIsRead(): void
    {
        $php = "<?php\n// config\nreturn [\n    'driver' => 'mysql',\n    'connections' => ['mysql' => ['port' => 3306, 'ssl' => false, 'host' => null]],\n    'list' => array(1, -2, 3.5),\n];\n";

        $this->assertSame([
            'driver'      => 'mysql',
            'connections' => ['mysql' => ['port' => 3306, 'ssl' => false, 'host' => null]],
            'list'        => [1, -2, 3.5],
        ], LiteralConfig::parse($php));
    }

    /** @return array<string, array{string}> */
    public static function code(): array
    {
        return [
            'appel de fonction'  => ["<?php return ['driver' => system('id')];"],
            'variable'           => ["<?php \$x = 1; return [\$x];"],
            'instruction'        => ["<?php file_put_contents('/tmp/pwned', 'x'); return [];"],
            'constante magique'  => ["<?php return ['path' => __DIR__];"],
            'interpolation'      => ["<?php return [\"a\" => \"b{\$c}\"];"],
            'classe'             => ["<?php return [new stdClass];"],
            'concaténation'      => ["<?php return ['a' => 'b' . 'c'];"],
            'pas un tableau'     => ["<?php return 'texte';"],
        ];
    }

    #[DataProvider('code')]
    public function testAnythingThatIsNotALiteralIsRefusedAndNotExecuted(string $php): void
    {
        @unlink(sys_get_temp_dir() . '/pwned');

        $this->assertNull(LiteralConfig::parse($php));
        $this->assertFileDoesNotExist('/tmp/pwned');
    }
}
