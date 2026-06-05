<?php
/**
 * Re-run body processors on stored mail for one subscription (no Gmail refetch).
 *
 * @var int $subscriptionReprocessId
 * @var string $csrfField
 */
declare(strict_types=1);

if (!isset($subscriptionReprocessId) || $subscriptionReprocessId <= 0) {
    return;
}
?>
<form method="post" action="<?= e(getBasePath()) ?>/index.php?action=<?= e($subscriptionReprocessAction ?? 'mail_subscription_reprocess') ?>" class="admin-inline-form" style="margin:0.5rem 0 1rem;">
    <?= $csrfField ?>
    <input type="hidden" name="id" value="<?= (int)$subscriptionReprocessId ?>">
    <button type="submit" class="btn btn-secondary">Reprocess stored mail</button>
</form>
