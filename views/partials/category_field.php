<?php
/**
 * Category text input with optional datalist suggestions.
 *
 * @var string $categoryValue
 * @var list<string> $categorySuggestions
 * @var string $datalistId
 */

declare(strict_types=1);
?>
<div class="admin-form-field">
    <label>Category
        <input type="text" name="category" class="search-input" style="width:100%; max-width:24rem;" value="<?= e($categoryValue) ?>"<?= $categorySuggestions !== [] ? ' list="' . e($datalistId) . '"' : '' ?>>
    </label>
    <?php if ($categorySuggestions !== []): ?>
    <datalist id="<?= e($datalistId) ?>">
        <?php foreach ($categorySuggestions as $cat): ?>
        <option value="<?= e($cat) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <?php endif; ?>
</div>
