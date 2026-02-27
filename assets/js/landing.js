(() => {
    const toggle = document.querySelector('[data-nav-toggle]');
    const body = document.body;
    if (toggle) {
        toggle.addEventListener('click', () => {
            const isOpen = body.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    const navLinks = document.querySelectorAll('.site-nav a');
    navLinks.forEach((link) => {
        link.addEventListener('click', () => {
            if (body.classList.contains('nav-open')) {
                body.classList.remove('nav-open');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    });

    const items = Array.from(document.querySelectorAll('[data-reveal]'));
    if (!items.length) return;

    const reveal = (entry) => {
        const delay = parseInt(entry.target.dataset.revealDelay || '0', 10);
        setTimeout(() => {
            entry.target.classList.add('is-visible');
        }, delay);
    };

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    reveal(entry);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });

        items.forEach((item) => observer.observe(item));
    } else {
        items.forEach((item) => item.classList.add('is-visible'));
    }
})();
