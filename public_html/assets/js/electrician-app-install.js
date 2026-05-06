(function () {
    const dialog = document.querySelector('[data-electrician-install-root]');

    if (!dialog) {
        return;
    }

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }

    const storageKey = 'mezoEnergyElectricianInstallPromptDismissed';
    const confirmButton = dialog.querySelector('[data-install-confirm]');
    const dismissButton = dialog.querySelector('[data-install-dismiss]');
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isTouchDevice = window.matchMedia('(pointer: coarse)').matches || 'ontouchstart' in window;
    const isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
    let deferredPrompt = null;
    let isOpen = false;

    function wasDismissed() {
        try {
            return window.localStorage.getItem(storageKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function rememberDismissed() {
        try {
            window.localStorage.setItem(storageKey, '1');
        } catch (error) {}
    }

    function closeDialog(remember) {
        if (remember) {
            rememberDismissed();
        }

        isOpen = false;

        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    function setMode(mode) {
        dialog.dataset.installMode = mode;

        if (!confirmButton) {
            return;
        }

        confirmButton.textContent = mode === 'native' ? 'Mentés' : 'Rendben';
    }

    function showDialog(mode) {
        if (isStandalone || wasDismissed() || isOpen) {
            return;
        }

        setMode(mode);
        isOpen = true;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;

        if (isOpen) {
            setMode('native');
            return;
        }

        if (isTouchDevice) {
            showDialog('native');
        }
    });

    window.addEventListener('appinstalled', function () {
        rememberDismissed();
        closeDialog(false);
    });

    if (confirmButton) {
        confirmButton.addEventListener('click', function () {
            if (!deferredPrompt) {
                closeDialog(true);
                return;
            }

            const promptEvent = deferredPrompt;
            deferredPrompt = null;
            promptEvent.prompt();

            promptEvent.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    rememberDismissed();
                }
                closeDialog(false);
            }).catch(function () {
                closeDialog(false);
            });
        });
    }

    if (dismissButton) {
        dismissButton.addEventListener('click', function () {
            closeDialog(true);
        });
    }

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            closeDialog(true);
        }
    });

    window.setTimeout(function () {
        if (deferredPrompt && isTouchDevice) {
            showDialog('native');
            return;
        }

        if (isIos && isTouchDevice) {
            showDialog('ios');
            return;
        }

        if (isTouchDevice) {
            showDialog('manual');
        }
    }, 1400);
})();
