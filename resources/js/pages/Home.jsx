import {useEffect, useState} from 'react';

export default function Home() {
    const [user, setUser] = useState(null);
    const [isAuthenticated, setIsAuthenticated] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        async function loadUser() {
            try {
                const response = await fetch('/api/user', {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Не удалось определить пользователя.');
                }

                const data = await response.json();

                setIsAuthenticated(data.authenticated === true);
                setUser(data.user ?? null);
            } catch {
                setError('Не удалось загрузить данные пользователя.');
            } finally {
                setIsLoading(false);
            }
        }

        loadUser();
    }, []);

    async function handleLogout() {
        setError('');
        setIsLoggingOut(true);

        try {
            const tokenElement = document.querySelector(
                'meta[name="csrf-token"]'
            );

            if (!tokenElement) {
                throw new Error('CSRF token not found.');
            }

            const response = await fetch('/logout', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': tokenElement.getAttribute('content'),
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Logout failed.');
            }

            window.location.href = '/login';
        } catch {
            setError('Не удалось выйти из аккаунта.');
            setIsLoggingOut(false);
        }
    }

    function renderNavigation() {
        if (isLoading) {
            return (
                <div className="h-9 w-24 animate-pulse rounded-lg bg-gray-100"/>
            );
        }

        if (isAuthenticated && user) {
            return (
                <div className="flex items-center gap-4">
                    <a
                        href="/advertisements"
                        className="text-sm text-gray-600 transition hover:text-gray-900"
                    >
                        Объявления
                    </a>

                    <a
                        href="/my-advertisements"
                        className="text-sm text-gray-600 transition hover:text-gray-900"
                    >
                        Мои объявления
                    </a>

                    <a
                        href="/family/profile"
                        className="text-sm text-gray-600 transition hover:text-gray-900"
                    >
                        Профиль семьи
                    </a>

                    <button
                        type="button"
                        onClick={handleLogout}
                        disabled={isLoggingOut}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {isLoggingOut ? 'Выход...' : 'Выйти'}
                    </button>
                </div>
            );
        }

        return (
            <nav className="flex items-center gap-5 text-sm">
                <a
                    href="#about"
                    className="text-gray-600 transition hover:text-gray-900"
                >
                    О проекте
                </a>

                <a
                    href="/advertisements"
                    className="text-gray-600 transition hover:text-gray-900"
                >
                    Объявления
                </a>

                <a
                    href="/login"
                    className="text-gray-600 transition hover:text-gray-900"
                >
                    Войти
                </a>

                <a
                    href="/register"
                    className="rounded-lg bg-gray-900 px-4 py-2 font-medium text-white transition hover:bg-gray-700"
                >
                    Регистрация
                </a>
            </nav>
        );
    }

    return (
        <div className="min-h-screen bg-[#f7f7f8] text-gray-900">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                    <a
                        href="/"
                        className="text-lg font-semibold tracking-tight text-gray-900"
                    >
                        Family Education
                    </a>

                    {renderNavigation()}
                </div>
            </header>

            <main>
                <section className="mx-auto max-w-3xl px-6 pb-24 pt-24">
                    <div className="text-center">
                        <h1 className="text-4xl font-semibold tracking-tight text-gray-950 sm:text-5xl">
                            Образование детей —
                            <br/>
                            вместе с другими родителями
                        </h1>

                        <p className="mx-auto mt-6 max-w-2xl text-lg leading-8 text-gray-600">
                            Находите родителей со схожими взглядами на
                            образование, объединяйтесь и создавайте
                            образовательную среду для своих детей.
                        </p>

                        {!isAuthenticated && !isLoading && (
                            <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                <a
                                    href="/register"
                                    className="w-full rounded-lg bg-gray-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-gray-700 sm:w-auto"
                                >
                                    Создать аккаунт
                                </a>

                                <a
                                    href="#about"
                                    className="w-full rounded-lg border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 sm:w-auto"
                                >
                                    Узнать больше
                                </a>
                            </div>
                        )}
                    </div>
                </section>

                {error && (
                    <div className="mx-auto max-w-3xl px-6 pb-8">
                        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                            {error}
                        </div>
                    </div>
                )}

                <section
                    id="about"
                    className="border-y border-gray-200 bg-white"
                >
                    <div className="mx-auto max-w-3xl px-6 py-16">
                        <h2 className="text-2xl font-semibold tracking-tight text-gray-950">
                            О проекте
                        </h2>

                        <div className="mt-6 space-y-5 text-base leading-7 text-gray-600">
                            <p>
                                Семейное образование позволяет родителям
                                самостоятельно определять образовательный путь
                                ребёнка, сохраняя возможность проходить
                                государственную аттестацию.
                            </p>

                            <p>
                                При этом самостоятельное образование не
                                обязательно означает образование в одиночку.
                            </p>

                            <p>
                                Родители могут объединяться в небольшие
                                сообщества, находить педагогов, договариваться
                                о формате занятий и вместе создавать
                                образовательную среду, которая соответствует
                                их представлениям о том, чему и как должны
                                учиться их дети.
                            </p>

                            <p>
                                <span className="font-medium text-gray-900">
                                    Family Education
                                </span>{' '}
                                создаётся как пространство для поиска таких
                                людей и построения между ними устойчивых
                                связей.
                            </p>
                        </div>
                    </div>
                </section>
            </main>

            <footer className="mx-auto max-w-5xl px-6 py-8 text-center text-sm text-gray-400">
                Family Education
            </footer>
        </div>
    );
}
