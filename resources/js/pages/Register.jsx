useEffect(() => {
    loadCities();

    if (!turnstileSiteKey) {
        console.error('Cloudflare Turnstile site key is not configured.');
        return;
    }

    let cancelled = false;
    let attempts = 0;

    const renderTurnstile = () => {
        if (cancelled) {
            return;
        }

        if (!window.turnstile) {
            attempts++;

            if (attempts >= 100) {
                console.error('Cloudflare Turnstile script failed to load.');
                return;
            }

            setTimeout(renderTurnstile, 100);

            return;
        }

        if (!turnstileRef.current) {
            return;
        }

        turnstileWidgetId.current = window.turnstile.render(
            turnstileRef.current,
            {
                sitekey: turnstileSiteKey,

                callback: (token) => {
                    turnstileToken.current = token;

                    setErrors((current) => ({
                        ...current,
                        'cf-turnstile-response': undefined,
                        form: undefined,
                    }));
                },

                'expired-callback': () => {
                    turnstileToken.current = '';
                },

                'error-callback': () => {
                    turnstileToken.current = '';

                    setErrors((current) => ({
                        ...current,
                        'cf-turnstile-response': [
                            'Не удалось выполнить проверку безопасности.',
                        ],
                    }));
                },
            },
        );
    };

    renderTurnstile();

    return () => {
        cancelled = true;

        if (
            turnstileWidgetId.current !== null &&
            window.turnstile
        ) {
            window.turnstile.remove(turnstileWidgetId.current);
            turnstileWidgetId.current = null;
        }
    };
}, [turnstileSiteKey]);
