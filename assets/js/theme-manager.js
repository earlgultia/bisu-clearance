(function () {
    const STORAGE_KEY = 'theme';
    const DARK_CLASS = 'dark-mode';
    const LIGHT_THEME_COLOR_FALLBACK = '#412886';
    const DARK_THEME_COLOR_FALLBACK = '#0f172a';
    const root = document.documentElement;

    function hasBody() {
        return !!(document.body && document.body.nodeType === 1);
    }

    function safeGetStoredTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }

    function safeSetStoredTheme(theme) {
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // Ignore storage failures so the toggle still works.
        }
    }

    function getCurrentTheme() {
        return root.classList.contains(DARK_CLASS) ? 'dark' : 'light';
    }

    function setThemeMetaColor(theme) {
        const metaThemeColor = document.querySelector('meta[name="theme-color"]');
        if (!metaThemeColor) {
            return;
        }

        if (!metaThemeColor.dataset.lightThemeColor) {
            metaThemeColor.dataset.lightThemeColor = metaThemeColor.getAttribute('content') || LIGHT_THEME_COLOR_FALLBACK;
        }

        if (!metaThemeColor.dataset.darkThemeColor) {
            metaThemeColor.dataset.darkThemeColor = DARK_THEME_COLOR_FALLBACK;
        }

        metaThemeColor.setAttribute(
            'content',
            theme === 'dark' ? metaThemeColor.dataset.darkThemeColor : metaThemeColor.dataset.lightThemeColor
        );
    }

    function syncThemeToggle(theme) {
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const isDark = theme === 'dark';
        const toggleLabel = isDark ? 'Switch to light mode' : 'Switch to dark mode';

        if (themeToggle) {
            themeToggle.setAttribute('aria-label', toggleLabel);
            themeToggle.setAttribute('title', toggleLabel);
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        }

        if (themeIcon) {
            themeIcon.classList.toggle('fa-sun', isDark);
            themeIcon.classList.toggle('fa-moon', !isDark);
        }
    }

    function syncTheme(theme) {
        const isDark = theme === 'dark';

        root.classList.toggle(DARK_CLASS, isDark);
        root.dataset.theme = theme;
        root.style.colorScheme = isDark ? 'dark' : 'light';

        if (hasBody()) {
            document.body.classList.toggle(DARK_CLASS, isDark);
            document.body.dataset.theme = theme;
        }

        setThemeMetaColor(theme);
        syncThemeToggle(theme);
    }

    function enterThemeSwitchingState() {
        root.classList.add('theme-switching');
        if (hasBody()) {
            document.body.classList.add('theme-switching');
        }
    }

    function clearThemeSwitchingState() {
        root.classList.remove('theme-switching');
        if (hasBody()) {
            document.body.classList.remove('theme-switching');
        }
    }

    function scheduleThemeSwitchingCleanup() {
        const raf = window.requestAnimationFrame || function (callback) {
            return window.setTimeout(callback, 16);
        };

        raf(function () {
            raf(function () {
                window.setTimeout(clearThemeSwitchingState, 80);
            });
        });
    }

    function applyTheme(theme, options) {
        const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
        const settings = options || {};

        if (settings.suppressTransitions) {
            enterThemeSwitchingState();
        }

        syncTheme(normalizedTheme);

        if (settings.persist) {
            safeSetStoredTheme(normalizedTheme);
        }

        if (settings.suppressTransitions) {
            scheduleThemeSwitchingCleanup();
        }
    }

    function toggleTheme() {
        applyTheme(getCurrentTheme() === 'dark' ? 'light' : 'dark', {
            persist: true,
            suppressTransitions: true
        });
    }

    function bindThemeToggle() {
        const themeToggle = document.getElementById('themeToggle');
        if (!themeToggle || themeToggle.dataset.themeManagerBound === '1') {
            return;
        }

        themeToggle.dataset.themeManagerBound = '1';
        themeToggle.addEventListener('click', function (event) {
            event.preventDefault();
            toggleTheme();
        });
    }

    function initializeThemeManager() {
        const storedTheme = safeGetStoredTheme() === 'dark' ? 'dark' : 'light';
        syncTheme(storedTheme);
        bindThemeToggle();
    }

    const bootTheme = safeGetStoredTheme() === 'dark' ? 'dark' : 'light';
    root.classList.toggle(DARK_CLASS, bootTheme === 'dark');
    root.dataset.theme = bootTheme;
    root.style.colorScheme = bootTheme === 'dark' ? 'dark' : 'light';
    setThemeMetaColor(bootTheme);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeThemeManager, { once: true });
    } else {
        initializeThemeManager();
    }

    window.BISUThemeManager = {
        apply: function (theme, options) {
            applyTheme(theme, options || {});
        },
        sync: function () {
            syncTheme(getCurrentTheme());
        },
        toggle: toggleTheme
    };
})();
