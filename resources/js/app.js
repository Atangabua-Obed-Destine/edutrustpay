import './bootstrap';

/**
 * Copy-to-clipboard for the one-time secret and invitation panels.
 *
 * Delegated from the document so it works for anything rendered later, and
 * written defensively: navigator.clipboard requires a secure context, and this
 * console is very likely to be served over plain HTTP on a local network — which
 * is exactly the moment somebody is copying a credential they cannot get back.
 */
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy]');

    if (!button) {
        return;
    }

    const source = document.querySelector(button.dataset.copy);

    if (!source) {
        return;
    }

    const text = source.innerText.trim();
    const original = button.innerText;

    const confirm = () => {
        button.innerText = button.dataset.copiedLabel || 'Copied';
        setTimeout(() => { button.innerText = original; }, 1500);
    };

    const fallback = () => {
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();

        try {
            document.execCommand('copy');
            confirm();
        } catch (e) {
            // The value is on screen and selectable; copying by hand still works.
        }

        document.body.removeChild(area);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(confirm).catch(fallback);
    } else {
        fallback();
    }
});

/**
 * Confirmation for destructive actions.
 *
 * Revoking a credential stops an institution reporting, and from the console
 * that looks identical to one that has gone quiet — worth a deliberate second
 * step rather than a stray click.
 */
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
