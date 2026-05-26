<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Seismo\Repository\TimelineFilter;

final class TimelineFilterEmailTest extends TestCase
{
    public function testNativeFilterWithNoEmailTagsDoesNotExcludeAllEmails(): void
    {
        $filter = TimelineFilter::fromHttpGet(
            [
                'filter_form' => '1',
                'filters'     => [
                    'feed' => ['Parl. SDA'],
                ],
            ],
            [
                'feed_categories' => ['Parl. SDA'],
                'lex_sources'     => [],
                'email_tags'      => [],
            ],
        );

        self::assertFalse($filter->excludeAllEmails);
    }

    public function testNativeFilterWithEmailTagsAndNoneSelectedExcludesEmails(): void
    {
        $filter = TimelineFilter::fromHttpGet(
            [
                'filter_form' => '1',
                'filters'     => [
                    'feed' => ['Parl. SDA'],
                ],
            ],
            [
                'feed_categories' => ['Parl. SDA'],
                'lex_sources'     => [],
                'email_tags'      => ['Inbox'],
            ],
        );

        self::assertTrue($filter->excludeAllEmails);
    }
}
