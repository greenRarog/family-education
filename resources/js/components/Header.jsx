import {useState} from 'react';

export default function Header({user, isAuthenticated, isLoading, unreadMessagesCount = 0,}) {
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [error, setError] = useState('');

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

    function renderUnreadMessagesCount() {
        if (!unreadMessagesCount) {
            return null;
        }

        return (
            <span
                className="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 py-0.5 text-xs font-medium leading-none text-white"
                aria-label={`Непрочитанных сообщений: ${unreadMessagesCount}`}
            >
                {unreadMessagesCount > 99 ? '99+' : unreadMessagesCount}
            </span>
        );
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
                        href="/conversations"
                        className="flex items-center text-sm text-gray-600 transition hover:text-gray-900"
                    >
                        <span>Личные сообщения</span>
                        {renderUnreadMessagesCount()}
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
        <>
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

            {error && (
                <div className="mx-auto max-w-5xl px-6 pt-4">
                    <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                        {error}
                    </div>
                </div>
            )}
        </>
    );
}
