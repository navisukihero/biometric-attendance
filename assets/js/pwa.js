(() => {
  'use strict';

  const installButtons = Array.from(document.querySelectorAll('[data-pwa-install]'));
  const standalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const userAgent = window.navigator.userAgent || '';
  const isiOS = /iphone|ipad|ipod/i.test(userAgent)
    || (/Macintosh/i.test(userAgent) && window.navigator.maxTouchPoints > 1);
  const isDesktopSafari = /safari/i.test(userAgent)
    && !/chrome|chromium|crios|edg|opr|android/i.test(userAgent)
    && !isiOS;
  let deferredInstallPrompt = null;

  const setInstallButtonsVisible = (visible) => {
    installButtons.forEach((button) => {
      button.hidden = !visible;
    });
  };

  if (window.isSecureContext && !standalone && (isiOS || isDesktopSafari)) {
    setInstallButtonsVisible(true);
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    if (!standalone) setInstallButtonsVisible(true);
  });

  installButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      if (deferredInstallPrompt) {
        const prompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        setInstallButtonsVisible(false);
        try {
          await prompt.prompt();
          await prompt.userChoice;
        } catch (_) {
          // A dismissed or unavailable native prompt must not disrupt the page.
        }
        return;
      }
      if (isiOS) {
        window.alert('To install UCCHR: tap the Share button, then choose “Add to Home Screen”.');
        return;
      }
      if (isDesktopSafari) {
        window.alert('To install UCCHR in Safari: open the File menu, then choose “Add to Dock”.');
      }
    });
  });

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    setInstallButtonsVisible(false);
  });

  const secureLocalHost = ['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname);
  if ('serviceWorker' in navigator && (window.isSecureContext || secureLocalHost)) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./service-worker.js', {
        scope: './',
        updateViaCache: 'none'
      }).catch(() => {
        // Installation enhancement is optional; normal online use continues.
      });
    });
  }
})();
