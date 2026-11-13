(() => {
    'use strict';

    const contractorDestination = 'register.php?account=contractor';
    const contractorLabel = 'Sign Up as Contractor';

    const normalizedText = (element) =>
        ((element && element.textContent) || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();

    const removeClonedIds = (element) => {
        element.removeAttribute('id');
        element.querySelectorAll('[id]').forEach((child) => {
            child.removeAttribute('id');
        });
    };

    const addContractorNavigationLink = (container, itemSelector) => {
        if (container.querySelector(
            '[data-trustfix-contractor-nav-item="true"]'
        )) {
            return;
        }

        const firstItem = container.querySelector(itemSelector);
        if (!firstItem) {
            return;
        }

        const item = firstItem.cloneNode(true);
        const anchor = item.matches('a') ? item : item.querySelector('a');
        if (!anchor) {
            return;
        }

        removeClonedIds(item);
        [
            'header-nav-item--active',
            'header-nav-item--collection',
            'header-nav-item--homepage',
            'header-menu-nav-item--active',
            'header-menu-nav-item--collection',
            'header-menu-nav-item--homepage',
        ].forEach((className) => item.classList.remove(className));

        if (item.classList.contains('header-nav-item')) {
            item.classList.add('header-nav-item--external');
        }
        if (item.classList.contains('header-menu-nav-item')) {
            item.classList.add('header-menu-nav-item--external');
        }

        item.dataset.trustfixContractorNavItem = 'true';
        anchor.removeAttribute('aria-current');
        anchor.setAttribute('href', contractorDestination);
        anchor.setAttribute('aria-label', contractorLabel);
        anchor.dataset.trustfixContractorSignup = 'true';

        const mobileContent = anchor.querySelector(
            '.header-menu-nav-item-content'
        );
        if (mobileContent) {
            mobileContent.textContent = contractorLabel;
        } else {
            anchor.textContent = contractorLabel;
        }

        container.insertBefore(item, firstItem);
    };

    const updateRegistrationLinks = () => {
        document.querySelectorAll('.header-nav-list').forEach((container) => {
            addContractorNavigationLink(container, '.header-nav-item');
        });

        document.querySelectorAll(
            '.header-menu-nav-list .header-menu-nav-wrapper'
        ).forEach((container) => {
            addContractorNavigationLink(container, '.header-menu-nav-item');
        });

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
        const contractorTarget = text === 'join as a pro'
            || text === contractorLabel.toLowerCase()
            || (target
                && target.dataset.trustfixContractorSignup === 'true');
        const destination = contractorTarget
            ? contractorDestination
            : (text === 'register now' ? 'register.php' : null);

        if (target && destination) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.location.assign(destination);
        }
    }, true);

    const start = () => {
        updateRegistrationLinks();

        const mutationRoot = document.body || document.documentElement;
        if (mutationRoot && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(updateRegistrationLinks);
            observer.observe(mutationRoot, {
                childList: true,
                subtree: true,
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
