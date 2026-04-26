(function () {
    const toggle = document.querySelector('.nav-toggle');
    const menu = document.querySelector('#primary-navigation');

    if (!toggle || !menu) {
        return;
    }

    function setMenuState(isOpen) {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Menü bezárása' : 'Menü megnyitása');
        toggle.classList.toggle('is-open', isOpen);
        menu.classList.toggle('is-open', isOpen);
    }

    toggle.addEventListener('click', function () {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        setMenuState(!isOpen);
    });

    menu.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            setMenuState(false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setMenuState(false);
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 760) {
            setMenuState(false);
        }
    });
})();
