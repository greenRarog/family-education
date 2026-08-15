import {useState} from 'react';

export default function ForgotPassword() {
    const [email, setEmail] = useState('');
    const [error, setError] = useState('');
    const [errors, setErrors] = useState({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [sent, setSent] = useState(false);

    async function handleSubmit(event) {
        event.preventDefault();

        setError('');
        setErrors({});
        setSent(false);
        setIsSubmitting(true);

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/forgot-password', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    email: email.trim(),
                }),
            });

            if (response.ok) {
                setSent(true);
                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setErrors(data.errors ?? {});
                return;
            }

            setError('Не удалось отправить письмо.');
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setIsSubmitting(false);
        }
    }

    function errorMessage(error) {
        return Array.isArray(error) ? error[0] : error;
    }

    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <div className="mx-auto w-full max-w-md">
                <div className="mb-8">
                    <a
                        href="/"
                        className="text-sm text-gray-500 transition hover:text-gray-900"
                    >
                        ← Family Education
                    </a>

                    <h1 className="mt-6 text-2xl font-semibold tracking-tight">
                        Восстановление пароля
                    </h1>

                    <p className="mt-2 text-sm leading-6 text-gray-500">
                        Введите email, указанный при регистрации. Мы отправим
                        ссылку для восстановления пароля.
                    </p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                    {error && (
                        <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {error}
                        </div>
                    )}

                    {sent && (
                        <div className="mb-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-5 text-gray-700">
                            Если такой email зарегистрирован, мы отправили на
                            него ссылку для восстановления пароля.
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <label
                                htmlFor="email"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Email
                            </label>

                            <input
                                id="email"
                                name="email"
                                type="email"
                                value={email}
                                onChange={(event) => {
                                    setEmail(event.target.value);
                                    setErrors((current) => ({
                                        ...current,
                                        email: undefined,
                                    }));
                                    setSent(false);
                                }}
                                disabled={isSubmitting}
                                autoComplete="email"
                                className={[
                                    'block',
                                    'w-full',
                                    'rounded-xl',
                                    'border',
                                    'bg-white',
                                    'px-3.5',
                                    'py-3',
                                    'text-sm',
                                    'text-gray-900',
                                    'outline-none',
                                    'transition',
                                    'disabled:cursor-not-allowed',
                                    'disabled:bg-gray-50',
                                    errors.email
                                        ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100'
                                        : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100',
                                ].join(' ')}
                            />

                            {errors.email && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.email)}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting
                                ? 'Отправка...'
                                : 'Отправить ссылку'}
                        </button>
                    </form>

                    <div className="mt-6 text-center">
                        <a
                            href="/login"
                            className="text-sm text-gray-500 transition hover:text-gray-900"
                        >
                            Вернуться ко входу
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
