// Shared helper to remove flash/notification query parameters from the URL
// so that success/error banners are not re-displayed after a manual refresh.
// It is safe to include this on any page.
(function () {
    if (typeof window === 'undefined' || typeof URL === 'undefined') {
        return;
    }

    try {
        const url = new URL(window.location.href);

        // Common keys used across the project for flash messages
        const flashKeys = [
            'success',
            'error',
            'success_count',
            'error_count',
            'count',
            'label'
        ];

        let changed = false;

        flashKeys.forEach((key) => {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });

        if (changed) {
            window.history.replaceState({}, '', url.toString());
        }
    } catch (e) {
        // Fail silently on older browsers or malformed URLs
    }
})();


