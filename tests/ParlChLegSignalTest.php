<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Util\ParlChLegSignal;

final class ParlChLegSignalTest extends TestCase
{
    public function testInsertWithoutBrResponseIsNew(): void
    {
        $row = [
            'external_id' => '20263501',
            'metadata'    => ['has_br_response' => false],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_NEW, $out['metadata']['leg_signal']);
        $this->assertNotEmpty($out['metadata']['leg_feed_at']);
    }

    public function testInsertWithBrResponseIsAntwortBr(): void
    {
        $row = [
            'external_id' => '20263501',
            'metadata'    => ['has_br_response' => true],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
    }

    public function testBrResponseAppearingLaterSetsAntwortBr(): void
    {
        $existing = [
            'has_br_response' => false,
            'leg_signal'      => ParlChLegSignal::SIGNAL_NEW,
            'leg_feed_at'     => '2026-03-20 10:00:00',
        ];
        $row = [
            'external_id' => '20263501',
            'metadata'    => ['has_br_response' => true],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertNotSame($existing['leg_feed_at'], $out['metadata']['leg_feed_at']);
    }

    public function testCatalogueTouchPreservesSignal(): void
    {
        $existing = [
            'has_br_response' => true,
            'leg_signal'      => ParlChLegSignal::SIGNAL_ANTWORT_BR,
            'leg_feed_at'     => '2026-05-20 12:00:00',
        ];
        $row = [
            'external_id' => '20263501',
            'metadata'    => ['has_br_response' => true],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertSame('2026-05-20 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testRepeatedRefreshWithSameBrContentDoesNotBumpFeedAt(): void
    {
        $brText = 'Der Bundesrat beantragt die Ablehnung der Motion.';
        $existing = [
            'has_br_response' => true,
            'leg_signal'      => ParlChLegSignal::SIGNAL_ANTWORT_BR,
            'leg_feed_at'     => '2026-05-13 10:00:00',
        ];
        $row = [
            'external_id' => '20263161',
            'content'     => $brText,
            'metadata'    => ['has_br_response' => true],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, $brText, '2026-03-18 08:00:00');
        $this->assertSame('2026-05-13 10:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testBackfillBrSignalUsesCreatedAtNotRefreshTime(): void
    {
        $brText = 'Der Bundesrat beantragt die Ablehnung.';
        $row = [
            'external_id' => '20263161',
            'content'     => $brText,
            'metadata'    => ['has_br_response' => true],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, false, $brText, '2026-03-18 08:00:00');
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertSame('2026-03-18 08:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testSessionsSkipped(): void
    {
        $row = ['external_id' => 'session_99', 'metadata' => []];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertArrayNotHasKey('leg_signal', $out['metadata'] ?? []);
    }
}
