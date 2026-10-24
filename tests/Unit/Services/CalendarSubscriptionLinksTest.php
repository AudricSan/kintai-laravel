<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\CalendarSubscriptionLinks;
use PHPUnit\Framework\TestCase;

/** Liens d'ajout en un clic d'un flux iCal aux agendas courants. */
final class CalendarSubscriptionLinksTest extends TestCase
{
    public function testLinksForAnHttpsFeed(): void
    {
        $links = CalendarSubscriptionLinks::for('https://kintai.example.com/sub/ical/tok/3/shifts.ics', 'Shibuya & Co');

        $this->assertSame('https://kintai.example.com/sub/ical/tok/3/shifts.ics', $links['https']);
        $this->assertSame('webcal://kintai.example.com/sub/ical/tok/3/shifts.ics', $links['webcal']);
        $this->assertSame('https://calendar.google.com/calendar/r?cid=webcal%3A%2F%2Fkintai.example.com%2Fsub%2Fical%2Ftok%2F3%2Fshifts.ics', $links['google']);
        $this->assertSame('https://outlook.live.com/calendar/0/addfromweb?url=https%3A%2F%2Fkintai.example.com%2Fsub%2Fical%2Ftok%2F3%2Fshifts.ics&name=Shibuya%20%26%20Co', $links['outlook']);
    }

    public function testAnHttpFeedInDevelopmentBecomesWebcalToo(): void
    {
        $this->assertSame('webcal://127.0.0.7/ical/t/1/shifts.ics', CalendarSubscriptionLinks::for('http://127.0.0.7/ical/t/1/shifts.ics')['webcal']);
    }
}