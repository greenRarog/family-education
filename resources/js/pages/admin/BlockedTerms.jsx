import {useEffect, useState} from 'react';
import AdminLayout from '../../layouts/AdminLayout';

export default function BlockedTerms() {
    const [blockedTerms, setBlockedTerms] = useState([]);
    const [word, setWord] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        loadBlockedTerms();
    }, []);

    async function loadBlockedTerms() {
        setError('');

        try {
            const response = await fetch('/api/admin/blocked-terms', {
                headers: {
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                setError('Не удалось загрузить список стоп-слов.');
                return;
            }

            setBlockedTerms(await response.json());
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setIsLoading(false);
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();

        const value = word.trim();

        if (!value) {
            return;
        }

        setIsSubmitting(true);
        setError('');

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/api/admin/blocked-terms', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    word: value,
                }),
            });

            if (response.status === 422) {
                const data = await response.json();
                setError(data.message ?? 'Такое слово уже существует.');
                return;
            }

            if (!response.ok) {
                setError('Не удалось добавить слово.');
                return;
            }

            const blockedTerm = await response.json();

            setBlockedTerms((current) =>
                [...current, blockedTerm].sort((a, b) =>
                    a.word.localeCompare(b.word, 'ru'),
                ),
            );

            setWord('');
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setIsSubmitting(false);
        }
    }

    async function handleDelete(blockedTerm) {
        if (!window.confirm(`Удалить слово «${blockedTerm.word}»?`)) {
            return;
        }

        setDeletingId(blockedTerm.id);
        setError('');

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch(
                `/api/admin/blocked-terms/${blockedTerm.id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setError('Не удалось удалить слово.');
                return;
            }

            setBlockedTerms((current) =>
                current.filter((item) => item.id !== blockedTerm.id),
            );
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <AdminLayout title="Бан-слова">
            <div className="mb-4">
                <p className="text-sm text-gray-500">
                    Слова, запрещённые для использования в объявлениях.
                </p>
            </div>

            {error && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            <form
                onSubmit={handleSubmit}
                className="mb-6 flex gap-2"
            >
                <input
                    type="text"
                    value={word}
                    onChange={(event) => setWord(event.target.value)}
                    placeholder="Запрещённое слово"
                    disabled={isSubmitting}
                    className="w-full max-w-md rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-500 focus:ring-1 focus:ring-gray-300 disabled:bg-gray-50"
                />

                <button
                    type="submit"
                    disabled={isSubmitting || !word.trim()}
                    className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {isSubmitting ? 'Добавление...' : '+ Добавить'}
                </button>
            </form>

            <div className="overflow-hidden rounded-md border border-gray-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-gray-200 bg-gray-50">
                    <tr>
                        <th className="w-24 px-4 py-3 font-medium text-gray-500">
                            ID
                        </th>

                        <th className="px-4 py-3 font-medium text-gray-500">
                            Слово
                        </th>

                        <th className="w-32 px-4 py-3 text-right font-medium text-gray-500">
                            Действия
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    {isLoading ? (
                        <tr>
                            <td
                                colSpan="3"
                                className="px-4 py-8 text-center text-sm text-gray-500"
                            >
                                Загрузка...
                            </td>
                        </tr>
                    ) : blockedTerms.length === 0 ? (
                        <tr>
                            <td
                                colSpan="3"
                                className="px-4 py-8 text-center text-sm text-gray-500"
                            >
                                Нет бан-слов.
                            </td>
                        </tr>
                    ) : (
                        blockedTerms.map((blockedTerm) => (
                            <tr
                                key={blockedTerm.id}
                                className="border-b border-gray-100 last:border-0"
                            >
                                <td className="px-4 py-3 text-gray-500">
                                    {blockedTerm.id}
                                </td>

                                <td className="px-4 py-3 text-gray-900">
                                    {blockedTerm.word}
                                </td>

                                <td className="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handleDelete(blockedTerm)
                                        }
                                        disabled={
                                            deletingId === blockedTerm.id
                                        }
                                        className="text-sm text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {deletingId === blockedTerm.id
                                            ? 'Удаление...'
                                            : 'Удалить'}
                                    </button>
                                </td>
                            </tr>
                        ))
                    )}
                    </tbody>
                </table>
            </div>
        </AdminLayout>
    );
}
