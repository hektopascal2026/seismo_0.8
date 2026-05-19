<?php
/**
 * Per-plugin "Recent runs" collapsible table (Settings → Diagnostics).
 *
 * Rendered once per plugin card (core and third-party) from
 * `views/partials/diagnostics_panel.php`. Extracted as a partial to keep the two
 * otherwise-identical 30-line blocks in one place, matching the
 * partials pattern introduced in Slice 6.
 *
 * @var list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}> $hist
 */

declare(strict_types=1);

if ($hist === []) {
    return;
}
?>
<details class="plugin-runs-details">
    <summary>Recent runs (<?= count($hist) ?>)</summary>
    <table class="plugin-runs-table">
        <thead>
        <tr>
            <th>Time (UTC)</th>
            <th>Status</th>
            <th>Items</th>
            <th>ms</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($hist as $h): ?>
            <tr>
                <td><?= e((string)seismo_format_utc($h['run_at'], 'Y-m-d H:i:s')) ?></td>
                <td><?= e(match ($h['status']) {
                    'warn' => 'partial',
                    default => $h['status'],
                }) ?></td>
                <td><?= (int)$h['item_count'] ?></td>
                <td><?= (int)$h['duration_ms'] ?></td>
            </tr>
            <?php if (!empty($h['error_message'])): ?>
            <tr><td colspan="4" class="plugin-run-error"><?= e((string)$h['error_message']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
