/**
 * Main index timeline: show/hide feeds.category = media entries (default off).
 */
(function () {
    var STORAGE_KEY = 'seismo_timeline_show_media';

    function readStoredShowMedia() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function timelineMediaOn() {
        return document.documentElement.classList.contains('timeline-media-on');
    }

    function mediaCards() {
        return document.querySelectorAll('[data-timeline-media="1"]');
    }

    function reconcileTimelineDaySeparators() {
        var section = document.querySelector('.latest-entries-section');
        if (!section) {
            return;
        }
        var children = section.children;
        for (var i = 0; i < children.length; i++) {
            var el = children[i];
            if (!el.classList || !el.classList.contains('magnitu-day-separator')) {
                continue;
            }
            var hasVisible = false;
            for (var j = i + 1; j < children.length; j++) {
                var next = children[j];
                if (next.classList.contains('magnitu-day-separator')) {
                    break;
                }
                if (next.classList.contains('entry-card') && next.style.display !== 'none') {
                    hasVisible = true;
                    break;
                }
            }
            el.style.display = hasVisible ? '' : 'none';
        }
    }

    function setTimelineMediaOn(on) {
        document.documentElement.classList.toggle('timeline-media-on', on);
        try {
            localStorage.setItem(STORAGE_KEY, on ? '1' : '0');
        } catch (e) {}

        mediaCards().forEach(function (card) {
            card.style.display = on ? '' : 'none';
        });

        var mediaBtn = document.querySelector('.timeline-media-toggle-btn');
        if (mediaBtn) {
            mediaBtn.classList.toggle('is-active', on);
            mediaBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            mediaBtn.title = on
                ? 'Hide media monitoring entries'
                : 'Show media monitoring entries';
        }

        reconcileTimelineDaySeparators();
    }

    function initTimelineMediaToggle() {
        var on = readStoredShowMedia();
        if (on) {
            document.documentElement.classList.add('timeline-media-on');
        }
        setTimelineMediaOn(on);
    }

    document.addEventListener('click', function (e) {
        var mediaBtn = e.target.closest('.timeline-media-toggle-btn');
        if (!mediaBtn) {
            return;
        }
        e.preventDefault();
        setTimelineMediaOn(!timelineMediaOn());
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTimelineMediaToggle);
    } else {
        initTimelineMediaToggle();
    }
})();
