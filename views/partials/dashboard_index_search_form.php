<?php
/**
 * GET search form for dashboard index (preserves filter query params).
 *
 * @var string $searchQuery
 * @var string $currentView 'newest'|'favourites'
 * @var bool $inlineTimeline compact single-row variant for mobile timeline toolbar
 */

declare(strict_types=1);

use Seismo\Repository\TimelineFilter;

$inlineTimeline = !empty($inlineTimeline);
$currentView    = $currentView ?? 'newest';
?>
            <form method="get" class="search-form<?= $inlineTimeline ? ' search-form--timeline-inline' : '' ?>">
                <input type="hidden" name="action" value="index">
                <?php if ($currentView === 'favourites'): ?>
                    <input type="hidden" name="view" value="favourites">
                <?php endif; ?>
                <?php
                $ff = $_GET['filter_form'] ?? null;
                if (is_scalar($ff) && trim((string)$ff) !== '') {
                    echo '<input type="hidden" name="filter_form" value="' . e((string)$ff) . '">';
                } elseif (TimelineFilter::getFiltersInQueryLooksNative($_GET['filters'] ?? null)) {
                    echo '<input type="hidden" name="filter_form" value="1">';
                }
                $noneP = $_GET['none'] ?? null;
                if (is_scalar($noneP) && trim((string)$noneP) !== '') {
                    echo '<input type="hidden" name="none" value="' . e((string)$noneP) . '">';
                }
                if (isset($_GET['filters']) && is_array($_GET['filters'])) {
                    foreach ($_GET['filters'] as $fk => $fv) {
                        if (!is_string($fk) || !preg_match('/^[a-z]+$/', $fk)) {
                            continue;
                        }
                        if (is_array($fv)) {
                            foreach ($fv as $item) {
                                if (!is_scalar($item)) {
                                    continue;
                                }
                                $s = trim((string)$item);
                                if ($s === '') {
                                    continue;
                                }
                                echo '<input type="hidden" name="filters[' . e($fk) . '][]" value="' . e($s) . '">';
                            }
                        } elseif (is_scalar($fv)) {
                            echo '<input type="hidden" name="filters[' . e($fk) . ']" value="' . e(trim((string)$fv)) . '">';
                        }
                    }
                }
                ?>
                <input type="search" name="q" placeholder="<?= $inlineTimeline ? 'Search…' : 'Search entries…' ?>" class="search-input<?= $inlineTimeline ? ' search-input--timeline-inline' : '' ?>" value="<?= e($searchQuery) ?>" autocomplete="off">
                <?php if (!$inlineTimeline): ?>
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="?<?= e($clearSearchQs) ?>" class="btn btn-secondary">Clear search</a>
                <?php endif; ?>
                <?php else: ?>
                <button type="submit" class="btn btn-primary btn-timeline-search-submit">Go</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="?<?= e($clearSearchQs) ?>" class="timeline-search-clear" title="Clear search" aria-label="Clear search">×</a>
                <?php endif; ?>
                <?php endif; ?>
            </form>
