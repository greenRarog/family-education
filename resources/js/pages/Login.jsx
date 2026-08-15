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

        if (!form.email.trim()) {
            newErrors.email = 'Введите email.';
        } else if (!isValidEmail(form.email.trim())) {
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
                    email: form.email.trim(),
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
                    form: 'Неверный email или пароль.',
                });

                return;
            }

            setErrors({
                form: 'Не удалось войти. Попробуйте ещё раз.',
            });
        } catch {
            setErrors({
                form: 'Не удалось соединиться с сервером.',
            });
        } finally {
            setIsSubmitting(false);
        }
    }

    return (
        <div className="min-h-screen bg-gray-50 px-4 py-12">
            <div className="mx-auto max-w-md">
                <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-gray-200">
                    <div className="mb-8 text-center">
                        <h1 className="text-2xl font-semibold text-gray-900">
                            Вход в аккаунт
                        </h1>

                        <p className="mt-2 text-sm text-gray-500">
                            Введите данные для входа
                        </p>
                    </div>

                    {errors.form && (
                        <div className="mb-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                            {errors.form}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-5">
                        <div>
                            <label
                                htmlFor="email"
                                className="mb-1.5 block text-sm font-medium text-gray-700"
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
                                className={`block w-full rounded-lg border px-3 py-2.5 text-sm outline-none transition focus:ring-2 ${
                                    errors.email
                                        ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                                        : 'border-gray-300 focus:border-blue-500 focus:ring-blue-100'
                                }`}
                            />

                            {errors.email && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {Array.isArray(errors.email)
                                        ? errors.email[0]
                                        : errors.email}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="mb-1.5 block text-sm font-medium text-gray-700"
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
                                className={`block w-full rounded-lg border px-3 py-2.5 text-sm outline-none transition focus:ring-2 ${
                                    errors.password
                                        ? 'border-red-300 focus:border-red-500 focus:ring-red-100'
                                        : 'border-gray-300 focus:border-blue-500 focus:ring-blue-100'
                                }`}
                            />

                            {errors.password && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {Array.isArray(errors.password)
                                        ? errors.password[0]
                                        : errors.password}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting ? 'Вход...' : 'Войти'}
                        </button>
                    </form>

                    <div className="mt-6 text-center text-sm text-gray-500">
                        Нет аккаунта?{' '}
                        <a
                            href="/register"
                            className="font-medium text-blue-600 hover:text-blue-700"
                        >
                            Зарегистрироваться
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
