import {Tooltip} from "bootstrap";

window.addEventListener('load', () => load());

function load() {
    loadForm();
    loadCopy();

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => {
        new Tooltip(element, {trigger: 'click'});
    });
}

function loadCopy() {
    const copyButton = document.getElementById('copyButton');
    if (!copyButton) return;

    copyButton.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const url = document.getElementById('url');
        if (url) navigator.clipboard.writeText(url.value);
    });
}

let recaptchaInitPromise;

function getRecaptcha() {
    if (!recaptchaInitPromise) {
        recaptchaInitPromise = (async () => {
            const response = await fetch('/api/recaptcha-config', {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('reCAPTCHA is not configured.');
            const {siteKey} = await response.json();
            if (!siteKey) throw new Error('Missing reCAPTCHA site key.');

            if (!window.grecaptcha) {
                await new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
                    script.async = true;
                    script.onload = resolve;
                    script.onerror = () => reject(new Error('Could not load reCAPTCHA.'));
                    document.head.appendChild(script);
                });
            }

            await new Promise(resolve => window.grecaptcha.ready(resolve));
            return siteKey;
        })().catch(error => {
            recaptchaInitPromise = undefined; // Allow a retry on the next submit.
            throw error;
        });
    }
    return recaptchaInitPromise;
}

function showFormError(form, message) {
    let alert = form.querySelector('[data-recaptcha-error]');
    if (!alert) {
        alert = document.createElement('div');
        alert.className = 'alert alert-danger mt-3';
        alert.setAttribute('role', 'alert');
        alert.dataset.recaptchaError = '';
        form.appendChild(alert);
    }
    alert.textContent = message;
}

function loadForm() {
    const form = document.getElementById('urlForm');
    if (!form) return;

    const submitButton = document.getElementById('formSubmit');
    if (!submitButton) return;

    // Load Google's script early for better detection context; generate a fresh token on submit.
    getRecaptcha().catch(() => {});

    form.addEventListener('submit', async event => {
        event.preventDefault();
        event.stopPropagation();

        const url = document.getElementById('url');
        if (!url) return;

        if (!/^https?:\/\//i.test(url.value)) {
            url.setCustomValidity('invalid.');
            form.classList.add('was-validated');
            url.classList.remove('is-valid');
            url.classList.add('is-invalid');
            return;
        }
        url.setCustomValidity('');
        url.classList.remove('is-invalid');

        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        form.classList.remove('was-validated');
        form.querySelector('[data-recaptcha-error]')?.remove();

        const formData = new FormData(form, submitButton);
        const inputs = [...form.querySelectorAll('input, select, button')];
        const previousDisabled = inputs.map(input => input.disabled);
        const originalButtonHtml = submitButton.innerHTML;

        inputs.forEach(input => { input.disabled = true; });
        submitButton.innerHTML = '<span>Generate</span> <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

        try {
            const siteKey = await getRecaptcha();
            const captchaToken = await window.grecaptcha.execute(siteKey, {action: 'create_url'});
            if (!captchaToken) throw new Error('reCAPTCHA did not return a token.');

            formData.set('recaptchaToken', captchaToken);

            const response = await fetch('/api', {method: 'POST', body: formData});
            if (!response.ok) {
                let message = 'Unable to create the URL. Please try again.';
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    const body = await response.json();
                    if (typeof body.error === 'string') message = body.error;
                }
                throw new Error(message);
            }
            const result = await response.text();
            const resultDocument = new DOMParser().parseFromString(result, 'text/html');
            const resultMain = resultDocument.querySelector('main');
            const currentMain = document.querySelector('main');
            if (!resultMain || !currentMain) throw new Error('Unexpected server response.');
            currentMain.innerHTML = resultMain.innerHTML;
            load();
        } catch (error) {
            showFormError(form, error.message || 'reCAPTCHA verification failed.');
        } finally {
            inputs.forEach((input, index) => { input.disabled = previousDisabled[index]; });
            // Only restore the original button if it's still in the DOM.
            submitButton.innerHTML = originalButtonHtml;
        }
    }, false);
}
