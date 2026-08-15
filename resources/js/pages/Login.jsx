import {useState} from 'react';

export default function Login() {
    const [form, setForm] = useState({
        email: '',
        password: '',
    });

    const [errors, setErrors] = useState({});
    const [isSubmitting, setIsSubmitting] = useState(false);

    function handleChange(event) {
        const {name, value} = event.target;

        setForm((current) => ({
            ...current,
            [name]: value,
        }));

        setErrors((current) => ({
            ...current,
            [name]: undefined,
            form: undefined,
        }));
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    async function handleSubmit(event) {
        event.preventDefault();

        setErrors({});

        const newErrors = {};
        const email = form.email.trim();

        if (!email) {
            newErrors.email = 'Введите email.';
        } else if (!isValidEmail(email)) {
            newErrors.email = 'Введите корректный email.';
        }

        if (!form.password) {
            newErrors.password = 'Введите пароль.';
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setIsSubmitting(true);

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/login', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                },
                body: new URLSearchParams({
                    email,
                    password: form.password,
                }),
            });

            if (response.ok) {
                window.location.href = '/';
                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setErrors(data.errors ?? {
                    form: ['Неверный email или пароль.'],
                });

                return;
            }

            setErrors({
                form: ['Не удалось войти. Попробуйте ещё раз.'],
            });
        } catch {
            setErrors({
                form: ['Не удалось соединиться с сервером.'],
            });
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
                <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                    <div className="mb-8 text-center">
                        <h1 className="text-2xl font-semibold tracking-tight text-gray-900">
                            Вход в аккаунт
                        </h1>

                        <p className="mt-2 text-sm leading-6 text-gray-500">
                            Введите данные для входа
                        </p>
                    </div>

                    {errors.form && (
                        <div
                            className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700">
                            {errorMessage(errors.form)}
                        </div>
                    )}

                    <form
                        onSubmit={handleSubmit}
                        noValidate
                        className="space-y-5"
                    >
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
                                value={form.email}
                                onChange={handleChange}
                                autoComplete="email"
                                autoFocus
                                disabled={isSubmitting}
                                className={fieldClass('email')}
                            />

                            {errors.email && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.email)}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Пароль
                            </label>

                            <input
                                id="password"
                                name="password"
                                type="password"
                                value={form.password}
                                onChange={handleChange}
                                autoComplete="current-password"
                                disabled={isSubmitting}
                                className={fieldClass('password')}
                            />

                            {errors.password && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.password)}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting ? 'Вход...' : 'Войти'}
                        </button>
                    </form>

                    <div className="mt-6 border-t border-gray-100 pt-6 text-center text-sm text-gray-500">
                        Нет аккаунта?{' '}
                        <a
                            href="/register"
                            className="font-medium text-gray-900 hover:underline"
                        >
                            Зарегистрироваться
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
