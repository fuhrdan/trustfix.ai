(() => {
    'use strict';

    const normalizedText = (element) =>
        ((element && element.textContent) || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const updateRegistrationLinks = () => {
        document.querySelectorAll('a').forEach((anchor) => {
            if (normalizedText(anchor) === 'register now') {
                anchor.setAttribute('href', 'register.php');
            }
        });

        document.querySelectorAll('button').forEach((button) => {
            if (normalizedText(button) === 'join as a pro') {
                button.setAttribute(
                    'aria-label',
                    'Create a TrustFix contractor account'
                );
                button.dataset.trustfixContractorSignup = 'true';
            }
        });
    };

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('button, a')
            : null;
        const text = normalizedText(target);
        const destination = text === 'join as a pro'
            ? 'register.php?account=contractor'
            : (text === 'register now' ? 'register.php' : null);

        if (target && destination) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.location.assign(destination);
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            updateRegistrationLinks,
            { once: true }
        );
    } else {
        updateRegistrationLinks();
    }
})();
