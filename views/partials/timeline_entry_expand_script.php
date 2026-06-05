<?php
/**
 * Timeline expand/collapse for entry cards and nested digest story blocks.
 * Included once per page; guarded against double-binding.
 */
?>
<script>
(function() {
    if (window.__seismoTimelineEntryExpand) {
        return;
    }
    window.__seismoTimelineEntryExpand = true;

    function expandableRoot(btn) {
        return btn.closest('.digest-child-item') || btn.closest('.entry-card');
    }

    function expandBtnForUnit(unit) {
        if (unit.classList.contains('digest-child-item')) {
            return unit.querySelector('.digest-child-item__actions .entry-expand-btn');
        }
        return unit.querySelector(':scope > .entry-actions .entry-expand-btn');
    }

    function allExpandableUnits() {
        var units = [];
        document.querySelectorAll('.entry-card').forEach(function(card) {
            if (card.querySelector(':scope > .entry-full-content')) {
                units.push(card);
            }
        });
        document.querySelectorAll('.digest-child-item').forEach(function(item) {
            if (item.querySelector('.entry-full-content')) {
                units.push(item);
            }
        });
        return units;
    }

    function collapse(unit, btn) {
        var preview = unit.querySelector('.entry-preview');
        var full    = unit.querySelector('.entry-full-content');
        if (!preview || !full) return;
        full.style.display = 'none';
        preview.style.display = '';
        if (btn) btn.textContent = 'expand \u25BE';
    }

    function expand(unit, btn) {
        var preview = unit.querySelector('.entry-preview');
        var full    = unit.querySelector('.entry-full-content');
        if (!preview || !full) return;
        preview.style.display = 'none';
        full.style.display    = 'block';
        if (btn) btn.textContent = 'collapse \u25B4';
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.entry-expand-btn');
        if (!btn) return;
        var unit = expandableRoot(btn);
        if (!unit) return;
        var full = unit.querySelector('.entry-full-content');
        if (!full) return;
        full.style.display === 'block' ? collapse(unit, btn) : expand(unit, btn);
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.entry-expand-all-btn');
        if (!btn) return;
        var isExpanded = btn.dataset.expanded === 'true';
        allExpandableUnits().forEach(function(unit) {
            var unitBtn = expandBtnForUnit(unit);
            isExpanded ? collapse(unit, unitBtn) : expand(unit, unitBtn);
        });
        btn.dataset.expanded = !isExpanded;
        btn.textContent = !isExpanded ? 'collapse all \u25B4' : 'expand all \u25BE';
    });
})();
</script>
