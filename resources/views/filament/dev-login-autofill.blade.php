<script>
    (() => {
        const emailValue = @json(config('app.dev_login_autofill.email'));
        const passwordValue = @json(config('app.dev_login_autofill.password'));

        const setInputValue = (input, value) => {
            if (!input || input.value) {
                return;
            }

            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const fill = () => {
            const emailInput = document.querySelector('input[type="email"], input[name="data.email"]');
            const passwordInput = document.querySelector('input[type="password"], input[name="data.password"]');

            setInputValue(emailInput, emailValue);
            setInputValue(passwordInput, passwordValue);
        };

        document.addEventListener('DOMContentLoaded', fill, { once: true });
        setTimeout(fill, 100);
    })();
</script>