import {useEffect, useState} from 'react';
import AdminLayout from '../../layouts/AdminLayout';

export default function Subjects() {
    const [subjects, setSubjects] = useState([]);
    const [name, setName] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        loadSubjects();
    }, []);

    async function loadSubjects() {
        setError('');

        try {
            const response = await fetch('/api/admin/subjects', {
                headers: {
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                setError('Не удалось загрузить предметы.');
                return;
            }

            setSubjects(await response.json());
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setIsLoading(false);
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();

        const value = name.trim();

        if (!value) {
            return;
        }

        setIsSubmitting(true);
        setError('');

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/api/admin/subjects', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: value,
                }),
            });

            if (response.status === 422) {
                const data = await response.json();
                setError(data.message ?? 'Такой предмет уже существует.');
                return;
            }

            if (!response.ok) {
                setError('Не удалось добавить предмет.');
                return;
            }

            const subject = await response.json();

            setSubjects((current) =>
                [...current, subject].sort((a, b) =>
                    a.name.localeCompare(b.name, 'ru'),
                ),
            );

            setName('');
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setIsSubmitting(false);
        }
    }

    async function handleDelete(subject) {
        if (!window.confirm(`Удалить предмет «${subject.name}»?`)) {
            return;
        }

        setDeletingId(subject.id);
        setError('');

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch(
                `/api/admin/subjects/${subject.id}`,
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
                setError('Не удалось удалить предмет.');
                return;
            }

            setSubjects((current) =>
                current.filter((item) => item.id !== subject.id),
            );
        } catch {
            setError('Не удалось соединиться с сервером.');
        } finally {
            setDeletingId(null);
        }
    }

    return (
        <AdminLayout title="Предметы">
            <div className="mb-4">
                <p className="text-sm text-gray-500">
                    Список предметов, доступных пользователям.
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
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="Название предмета"
                    disabled={isSubmitting}
                    className="w-full max-w-md rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-500 focus:ring-1 focus:ring-gray-300 disabled:bg-gray-50"
                />

                <button
                    type="submit"
                    disabled={isSubmitting || !name.trim()}
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
                            Предмет
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
                    ) : subjects.length === 0 ? (
                        <tr>
                            <td
                                colSpan="3"
                                className="px-4 py-8 text-center text-sm text-gray-500"
                            >
                                Нет предметов.
                            </td>
                        </tr>
                    ) : (
                        subjects.map((subject) => (
                            <tr
                                key={subject.id}
                                className="border-b border-gray-100 last:border-0"
                            >
                                <td className="px-4 py-3 text-gray-500">
                                    {subject.id}
                                </td>

                                <td className="px-4 py-3 text-gray-900">
                                    {subject.name}
                                </td>

                                <td className="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        onClick={() => handleDelete(subject)}
                                        disabled={deletingId === subject.id}
                                        className="text-sm text-red-600 hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {deletingId === subject.id
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
