import { useEffect, useRef } from 'react';

const SCRIPT_ID = 'cloudflare-turnstile-script';
const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

export default function Turnstile({ onToken, onError, onExpired }) {
    const containerRef = useRef(null);
    const widgetIdRef = useRef(null);

    useEffect(() => {
        let isMounted = true;

        function renderWidget() {
            if (!isMounted || !containerRef.current) {
                return;
            }

            if (!window.turnstile) {
                return;
            }

            if (widgetIdRef.current !== null) {
                return;
            }

            const siteKey = document
                .querySelector('meta[name="turnstile-site-key"]')
                ?.getAttribute('content');

            if (!siteKey) {
                onError?.();
                return;
            }

            widgetIdRef.current = window.turnstile.render(
                containerRef.current,
                {
                    sitekey: siteKey,

                    callback: (token) => {
                        onToken(token);
                    },

                    'expired-callback': () => {
                        onExpired?.();
                    },

                    'error-callback': () => {
                        onError?.();
                    },
                },
            );
        }

        if (window.turnstile) {
            renderWidget();

            return () => {
                isMounted = false;
            };
        }

        let script = document.getElementById(SCRIPT_ID);

        if (!script) {
            script = document.createElement('script');

            script.id = SCRIPT_ID;
            script.src = SCRIPT_URL;
            script.async = true;
            script.defer = true;

            document.head.appendChild(script);
        }

        script.addEventListener('load', renderWidget);

        return () => {
            isMounted = false;
            script.removeEventListener('load', renderWidget);
        };
    }, [onToken, onError, onExpired]);

    return <div ref={containerRef}/>;
}
