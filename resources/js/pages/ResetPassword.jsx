import {useState} from 'react';

export default function ResetPassword() {
    const [form, setForm] = useState({
        password: '',
        password_confirmation: '',
    });

    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [resetSuccessful, setResetSuccessful] = useState(false);

    const token = window.location.pathname.split('/').pop();
    const email = new URLSearchParams(window.location.search).get('email') ?? '';

    function handleChange(event) {
        const {name, value} = event.target;

        setForm((current) => ({
            ...current,
            [name]: value,
        }));

        setErrors((current) => ({
            ...current,
            [name]: undefined,
        }));

        setFormError('');
    }

    async function handleSubmit(event) {
        event.preventDefault();

        setErrors({});
        setFormError('');
        setResetSuccessful(false);

        const newErrors = {};

        if (!form.password) {
            newErrors.password = 'Введите новый пароль.';
        }

        if (!form.password_confirmation) {
            newErrors.password_confirmation =
                'Подтвердите новый пароль.';
        }

        if (
            form.password &&
            form.password_confirmation &&
            form.password !== form.password_confirmation
        ) {
            newErrors.password_confirmation =
                'Пароли не совпадают.';
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setIsSubmitting(true);

        try {
            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/reset-password', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    token,
                    email,
                    password: form.password,
                    password_confirmation: form.password_confirmation,
                }),
            });

            if (response.ok) {
                setResetSuccessful(true);
                setForm({
                    password: '',
                    password_confirmation: '',
                });

                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setErrors(data.errors ?? {});
                return;
            }

            setFormError(
                'Не удалось изменить пароль. Попробуйте ещё раз.',
            );
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsSubmitting(false);
        }
    }

    function fieldClass(field) {
        return [
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
            'placeholder:text-gray-400',
            'disabled:cursor-not-allowed',
            'disabled:bg-gray-50',
            errors[field]
                ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100'
                : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100',
        ].join(' ');
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

                    <h1 className="mt-6 text-2xl font-semibold tracking-tight text-gray-900">
                        Новый пароль
                    </h1>

                    <p className="mt-2 text-sm leading-6 text-gray-500">
                        Придумайте новый пароль для вашего аккаунта.
                    </p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                    {formError && (
                        <div
                            className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700"
                        >
                            {formError}
                        </div>
                    )}

                    {resetSuccessful ? (
                        <div>
                            <div
                                className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-5 text-gray-700"
                            >
                                Пароль успешно изменён.
                            </div>

                            <a
                                href="/login"
                                className="mt-6 block w-full rounded-xl bg-gray-900 px-4 py-3 text-center text-sm font-medium text-white transition hover:bg-gray-800"
                            >
                                Войти
                            </a>
                        </div>
                    ) : (
                        <form
                            onSubmit={handleSubmit}
                            noValidate
                            className="space-y-5"
                        >
                            <div>
                                <label
                                    htmlFor="password"
                                    className="mb-2 block text-sm font-medium text-gray-700"
                                >
                                    Новый пароль
                                </label>

                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    value={form.password}
                                    onChange={handleChange}
                                    autoComplete="new-password"
                                    autoFocus
                                    disabled={isSubmitting}
                                    className={fieldClass('password')}
                                />

                                {errors.password && (
                                    <p className="mt-1.5 text-sm text-red-600">
                                        {errorMessage(errors.password)}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label
                                    htmlFor="password_confirmation"
                                    className="mb-2 block text-sm font-medium text-gray-700"
                                >
                                    Повторите пароль
                                </label>

                                <input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    value={form.password_confirmation}
                                    onChange={handleChange}
                                    autoComplete="new-password"
                                    disabled={isSubmitting}
                                    className={fieldClass(
                                        'password_confirmation',
                                    )}
                                />

                                {errors.password_confirmation && (
                                    <p className="mt-1.5 text-sm text-red-600">
                                        {errorMessage(
                                            errors.password_confirmation,
                                        )}
                                    </p>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isSubmitting
                                    ? 'Сохранение...'
                                    : 'Изменить пароль'}
                            </button>
                        </form>
                    )}

                    {!resetSuccessful && (
                        <div className="mt-6 border-t border-gray-100 pt-6 text-center text-sm text-gray-500">
                            <a
                                href="/login"
                                className="font-medium text-gray-900 hover:underline"
                            >
                                Вернуться ко входу
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
