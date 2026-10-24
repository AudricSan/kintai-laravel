<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use kintai\Core\Response;

final class ResponseTest extends TestCase
{
    // -------------------------------------------------------------------------
    // html()
    // -------------------------------------------------------------------------

    public function testHtmlSetsBodyAndStatus(): void
    {
        $r = Response::html('<h1>Hello</h1>');
        $this->assertSame('<h1>Hello</h1>', $r->body());
        $this->assertSame(200, $r->status());
    }

    public function testHtmlCustomStatus(): void
    {
        $r = Response::html('Not Found', 404);
        $this->assertSame(404, $r->status());
    }

    public function testHtmlEmptyBody(): void
    {
        $r = Response::html('');
        $this->assertSame('', $r->body());
    }

    // -------------------------------------------------------------------------
    // json()
    // -------------------------------------------------------------------------

    public function testJsonEncodesArray(): void
    {
        $r       = Response::json(['key' => 'value', 'num' => 42]);
        $decoded = json_decode($r->body(), true);
        $this->assertSame('value', $decoded['key']);
        $this->assertSame(42, $decoded['num']);
    }

    public function testJsonStatus(): void
    {
        $r = Response::json(['error' => 'Not Found'], 404);
        $this->assertSame(404, $r->status());
    }

    public function testJsonPreservesUnicode(): void
    {
        $r = Response::json(['msg' => '日本語']);
        $this->assertStringContainsString('日本語', $r->body());
    }

    public function testJsonNull(): void
    {
        $r = Response::json(null);
        $this->assertSame('null', $r->body());
    }

    public function testJsonNestedStructure(): void
    {
        $data    = ['items' => [['id' => 1], ['id' => 2]]];
        $decoded = json_decode(Response::json($data)->body(), true);
        $this->assertCount(2, $decoded['items']);
    }

    // -------------------------------------------------------------------------
    // redirect()
    // -------------------------------------------------------------------------

    public function testRedirectDefaultStatus(): void
    {
        $r = Response::redirect('/login');
        $this->assertSame(302, $r->status());
        $this->assertSame('', $r->body());
    }

    public function testRedirectPermanent(): void
    {
        $r = Response::redirect('/home', 301);
        $this->assertSame(301, $r->status());
    }

    // -------------------------------------------------------------------------
    // jsonDownload()
    // -------------------------------------------------------------------------

    public function testJsonDownloadEncodesBodyAsJson(): void
    {
        $r = Response::jsonDownload(['id' => 1, 'name' => 'Alice'], 'export.json');
        $this->assertSame(['id' => 1, 'name' => 'Alice'], json_decode($r->body(), true));
        $this->assertSame(200, $r->status());
    }

    // -------------------------------------------------------------------------
    // csv()
    // -------------------------------------------------------------------------

    public function testCsvSetsBody(): void
    {
        $csv = "id,name\n1,Alice\n";
        $r   = Response::csv($csv, 'export.csv');
        $this->assertSame($csv, $r->body());
        $this->assertSame(200, $r->status());
    }

    // -------------------------------------------------------------------------
    // pdf()
    // -------------------------------------------------------------------------

    public function testPdfSetsBody(): void
    {
        $bytes = '%PDF-1.4 fake';
        $r     = Response::pdf($bytes, 'report.pdf');
        $this->assertSame($bytes, $r->body());
        $this->assertSame(200, $r->status());
    }

    // -------------------------------------------------------------------------
    // fileStream()
    // -------------------------------------------------------------------------

    public function testFileStreamStatus200(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kintai_resp_');
        file_put_contents($path, 'fake-pdf-content');

        $r = Response::fileStream($path, 'application/pdf', 'test.pdf');
        $this->assertSame(200, $r->status());

        unlink($path);
    }

    public function testFileStreamBodyIsEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kintai_resp_');
        file_put_contents($path, 'fake-pdf-content');

        $r = Response::fileStream($path, 'application/pdf', 'test.pdf');
        $this->assertSame('', $r->body());

        unlink($path);
    }

    public function testFileStreamAcceptsDifferentContentTypes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kintai_resp_');
        file_put_contents($path, 'data');

        $r = Response::fileStream($path, 'text/plain', 'file.txt');
        $this->assertSame(200, $r->status());

        unlink($path);
    }

    // -------------------------------------------------------------------------
    // empty()
    // -------------------------------------------------------------------------

    public function testEmptyDefaultStatus204(): void
    {
        $r = Response::empty();
        $this->assertSame(204, $r->status());
        $this->assertSame('', $r->body());
    }

    public function testEmptyCustomStatus(): void
    {
        $r = Response::empty(200);
        $this->assertSame(200, $r->status());
    }

    // -------------------------------------------------------------------------
    // withHeader() / withStatus()
    // -------------------------------------------------------------------------

    public function testWithStatusReturnsSelf(): void
    {
        $r  = Response::html('ok');
        $r2 = $r->withStatus(201);
        $this->assertSame($r, $r2);
        $this->assertSame(201, $r->status());
    }

    public function testWithHeaderReturnsSelf(): void
    {
        $r  = Response::html('ok');
        $r2 = $r->withHeader('X-Foo', 'bar');
        $this->assertSame($r, $r2);
    }

    public function testFluentChaining(): void
    {
        $r = Response::html('ok')->withStatus(422)->withHeader('Allow', 'GET');
        $this->assertSame(422, $r->status());
    }

    public function testWithStatusOverridesPrevious(): void
    {
        $r = Response::json(['ok' => true], 200);
        $r->withStatus(422);
        $this->assertSame(422, $r->status());
    }
}
