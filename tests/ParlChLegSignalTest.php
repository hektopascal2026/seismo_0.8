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
            'metadata'    => [
                'has_br_response' => false,
                'submission_date' => '2026-03-20',
            ],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_NEW, $out['metadata']['leg_signal']);
        $this->assertSame('2026-03-20 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testInsertWithBrResponseUsesStellungnahmeDate(): void
    {
        $row = [
            'external_id' => '20263501',
            'metadata'    => [
                'has_br_response'  => true,
                'br_response_date' => '2026-05-20',
            ],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertSame('2026-05-20 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testBrResponseAppearingLaterUsesStellungnahmeDate(): void
    {
        $existing = [
            'has_br_response' => false,
            'leg_signal'      => ParlChLegSignal::SIGNAL_NEW,
            'leg_feed_at'     => '2026-03-20 12:00:00',
        ];
        $row = [
            'external_id' => '20263501',
            'metadata'    => [
                'has_br_response'  => true,
                'br_response_date' => '2026-05-20',
            ],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, null, null);
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertSame('2026-05-20 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testCatalogueTouchNormalizesFeedAtToStellungnahmeDate(): void
    {
        $brText = 'Der Bundesrat beantragt die Ablehnung der Motion.';
        $existing = [
            'has_br_response'  => true,
            'leg_signal'       => ParlChLegSignal::SIGNAL_ANTWORT_BR,
            'leg_feed_at'      => '2026-05-21 19:40:00',
            'br_response_date' => '2026-05-13',
        ];
        $row = [
            'external_id' => '20263161',
            'content'     => $brText,
            'metadata'    => [
                'has_br_response'  => true,
                'br_response_date' => '2026-05-13',
            ],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, $brText, '2026-03-18 08:00:00');
        $this->assertSame(ParlChLegSignal::SIGNAL_ANTWORT_BR, $out['metadata']['leg_signal']);
        $this->assertSame('2026-05-13 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testRepeatedRefreshWithSameBrContentDoesNotBumpFeedAt(): void
    {
        $brText = 'Der Bundesrat beantragt die Ablehnung der Motion.';
        $existing = [
            'has_br_response'  => true,
            'leg_signal'       => ParlChLegSignal::SIGNAL_ANTWORT_BR,
            'leg_feed_at'      => '2026-05-13 12:00:00',
            'br_response_date' => '2026-05-13',
        ];
        $row = [
            'external_id' => '20263161',
            'content'     => $brText,
            'metadata'    => [
                'has_br_response'  => true,
                'br_response_date' => '2026-05-13',
            ],
        ];
        $out = ParlChLegSignal::applyToBusinessRow($row, $existing, false, $brText, '2026-03-18 08:00:00');
        $this->assertSame('2026-05-13 12:00:00', $out['metadata']['leg_feed_at']);
    }

    public function testSessionsSkipped(): void
    {
        $row = ['external_id' => 'session_99', 'metadata' => []];
        $out = ParlChLegSignal::applyToBusinessRow($row, null, true, null, null);
        $this->assertArrayNotHasKey('leg_signal', $out['metadata'] ?? []);
    }
}
