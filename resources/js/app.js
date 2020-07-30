// Small progressive enhancements; every page works without JavaScript.

// <form data-confirm="Are you sure?"> asks before submitting.
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Disable submit buttons after the first click so a double click sends one
// request (checkout is idempotent anyway; this just avoids the flash).
document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    for (const button of event.target.querySelectorAll('button[type="submit"][data-once]')) {
        setTimeout(() => (button.disabled = true), 0);
    }
});

// <select data-autosubmit> submits its form on change (filters, quantities).
document.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit]')) {
        event.target.form?.requestSubmit();
    }
});
