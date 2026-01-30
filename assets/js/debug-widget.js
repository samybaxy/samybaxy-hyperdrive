/**
 * Samybaxy's Hyperdrive Debug Widget
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const widget = document.getElementById('shypdr-debug-widget');
        if (!widget) return;

        const toggle = widget.querySelector('.shypdr-debug-toggle');
        if (!toggle) return;

        // Toggle widget expansion
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            widget.classList.toggle('expanded');
        });

        // Auto-collapse on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                widget.classList.remove('expanded');
            }
        });

        // Close when clicking outside
        document.addEventListener('click', function (e) {
            if (!widget.contains(e.target)) {
                widget.classList.remove('expanded');
            }
        });
    });
})();
