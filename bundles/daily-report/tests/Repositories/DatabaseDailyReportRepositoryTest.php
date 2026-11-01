<?php
declare(strict_types=1);

namespace Tests\Bundles\DailyReport\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Bundles\Installed\DailyReport\Models\DailyReport;
use Illuminate\Database\Connection;
use kintai\Bundles\Installed\DailyReport\Models\DailyReport as EloquentDailyReport;

final class DatabaseDailyReportRepositoryTest extends TestCase
{
    /** Ces tests créent des schémas de départ variés dans la base en mémoire : le test suivant repart d'une base neuve. */
    protected function tearDown(): void
    {
        kintai_test_database();
    }


    protected function setUp(): void
    {
        $capsule = kintai_test_database();

        $capsule->getSchemaBuilder()->create('daily_reports', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('author_id');
            $table->string('report_date');
            $table->decimal('sales_total', 12, 2)->default(0);
            $table->integer('customer_count')->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('waste_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('data')->nullable();
            $table->string('status')->default('draft');
            $table->integer('is_finalized')->default(0);
            $table->integer('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->integer('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

    }

    private function report(int $storeId = 1, string $date = '2025-06-15', string $status = 'draft', ?string $deletedAt = null, int $authorId = 10): array
    {
        return [
            'store_id'   => $storeId,
            'report_date'=> $date,
            'status'     => $status,
            'deleted_at' => $deletedAt,
            'author_id'  => $authorId,
        ];
    }

    public function testFindByIdReturnsReport(): void
    {
        $r = EloquentDailyReport::create($this->report());
        $found = DailyReport::findById($r->id);
        $this->assertNotNull($found);
    }

    public function testFindByIdReturnsNullForDeletedReport(): void
    {
        $r = EloquentDailyReport::create($this->report(deletedAt: '2025-01-01 00:00:00'));
        $this->assertNull(DailyReport::findById($r->id));
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull(DailyReport::findById(999));
    }

    public function testFindByStoreReturnsSortedByDateDesc(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-10'));
        EloquentDailyReport::create($this->report(1, '2025-06-15'));
        EloquentDailyReport::create($this->report(1, '2025-06-12'));

        $result = DailyReport::findByStore(1);
        $dates = array_column($result, 'report_date');
        $this->assertSame(['2025-06-15', '2025-06-12', '2025-06-10'], $dates);
    }

    public function testFindByStoreExcludesDeleted(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-10', deletedAt: '2025-06-11 00:00:00'));
        $this->assertSame([], DailyReport::findByStore(1));
    }

    public function testFindByStoreAndDateReturnsReport(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-15'));
        $found = DailyReport::findByStoreAndDate(1, '2025-06-15');
        $this->assertNotNull($found);
    }

    public function testFindByStoreAndDateReturnsNullForDeleted(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-15', deletedAt: '2025-06-16 00:00:00'));
        $this->assertNull(DailyReport::findByStoreAndDate(1, '2025-06-15'));
    }

    public function testFindByAuthorReturnsSortedDesc(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-10', authorId: 5));
        EloquentDailyReport::create($this->report(1, '2025-06-15', authorId: 5));

        $result = DailyReport::findByAuthor(5);
        $this->assertSame('2025-06-15', $result[0]['report_date']);
    }

    public function testFindByStoreAndStatusFiltersCorrectly(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-10', 'submitted'));
        EloquentDailyReport::create($this->report(1, '2025-06-15', 'draft'));

        $result = DailyReport::findByStoreAndStatus(1, 'submitted');
        $this->assertCount(1, $result);
    }

    public function testFindByStoreAndDateRangeFiltersCorrectly(): void
    {
        EloquentDailyReport::create($this->report(1, '2025-06-05'));
        EloquentDailyReport::create($this->report(1, '2025-06-15'));
        EloquentDailyReport::create($this->report(1, '2025-07-01'));

        $result = DailyReport::findByStoreAndDateRange(1, '2025-06-01', '2025-06-30');
        $this->assertCount(2, $result);
    }

    public function testSaveCreatesReport(): void
    {
        $result = DailyReport::put($this->report());
        $this->assertArrayHasKey('id', $result);
        $this->assertCount(1, EloquentDailyReport::all());
    }

    public function testDeleteDeletesReport(): void
    {
        $r = EloquentDailyReport::create($this->report());
        $result = DailyReport::remove($r->id);
        $this->assertSame(1, $result);
        $this->assertNull(EloquentDailyReport::find($r->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, DailyReport::remove(999));
    }
}
