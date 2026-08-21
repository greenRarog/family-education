import {useEffect, useState} from 'react';

const types = {
    family_to_family: 'Ищу участников в учебную группу',
    family_to_teacher: 'Ищу педагога',
};

const formats = {
    online: 'Онлайн',
    offline: 'Очно',
    hybrid: 'Смешанный',
};

export default function AdvertisementShow({advertisementId}) {
    const [advertisement, setAdvertisement] = useState(null);
    const [viewer, setViewer] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isResponding, setIsResponding] = useState(false);
    const [error, setError] = useState('');
    const [responseError, setResponseError] = useState('');
    const defaultResponseMessage =
        'Здравствуйте! Заинтересован Вашим объявлением и хочу участвовать!';

    const [responseMessage, setResponseMessage] = useState(
        defaultResponseMessage
    );

    useEffect(() => {
        fetch(`/api/advertisements/${advertisementId}`, {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error();
                }

                return response.json();
            })
            .then((data) => {
                setAdvertisement(data.advertisement);
                setViewer(data.viewer);
            })
            .catch(() => {
                setError('Не удалось загрузить объявление.');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, [advertisementId]);

    const handleRespond = async () => {
        const text = responseMessage.trim();

        if (
            !viewer?.authenticated ||
            viewer.is_owner ||
            viewer.has_responded ||
            isResponding ||
            !text
        ) {
            return;
        }

        setIsResponding(true);
        setResponseError('');

        try {
            const response = await fetch(
                `/api/advertisements/${advertisementId}/responses`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        message: text,
                    }),
                }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || 'Не удалось отправить отклик.'
                );
            }

            setViewer((current) => ({
                ...current,
                has_responded: true,
            }));
        } catch (error) {
            setResponseError(
                error.message || 'Не удалось отправить отклик.'
            );
        } finally {
            setIsResponding(false);
        }
    };

    const renderAction = () => {
        if (!viewer) {
            return null;
        }

        if (viewer.is_owner) {
            return (
                <a
                    href={`/advertisements/${advertisement.id}/edit`}
                    className="inline-flex items-center justify-center rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-gray-800"
                >
                    Редактировать
                </a>
            );
        }

        if (viewer.has_responded) {
            return (
                <button
                    type="button"
                    disabled
                    className="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-green-50 px-5 py-2.5 text-sm font-medium text-green-700"
                >
                    ✓ Вы откликнулись
                </button>
            );
        }

        if (viewer.authenticated) {
            return (
                <div className="w-full">
                    <label
                        htmlFor="response-message"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Сообщение
                    </label>

                    <textarea
                        id="response-message"
                        value={responseMessage}
                        onChange={(event) =>
                            setResponseMessage(event.target.value)
                        }
                        rows={4}
                        placeholder="Напишите сообщение автору объявления..."
                        className="mt-2 w-full resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                    />

                    <button
                        type="button"
                        disabled={
                            !responseMessage.trim() || isResponding
                        }
                        onClick={handleRespond}
                        className="mt-3 inline-flex items-center justify-center rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {isResponding ? 'Отправка...' : 'Отправить отклик'}
                    </button>
                </div>
            );
        }

        return (
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    disabled
                    className="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-400"
                >
                    Отозваться
                </button>

                <div className="group relative">
        <span
            className="flex h-6 w-6 cursor-help items-center justify-center rounded-full border border-gray-300 text-xs text-gray-500"
        >
            ?
        </span>

                    <div
                        className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 w-64 -translate-x-1/2 rounded-lg bg-gray-900 px-3 py-2 text-center text-xs leading-5 text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100"
                    >
                        Для отклика на объявления необходимо зарегистрироваться.
                    </div>
                </div>
            </div>
        );
    };

    if (isLoading) {
        return (
            <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
                <main className="mx-auto w-full max-w-2xl">
                    <p className="text-sm text-gray-500">
                        Загрузка объявления...
                    </p>
                </main>
            </div>
        );
    }

    if (error || !advertisement) {
        return (
            <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
                <main className="mx-auto w-full max-w-2xl">
                    <a
                        href="/advertisements"
                        className="text-sm text-gray-500 transition hover:text-gray-900"
                    >
                        ← К объявлениям
                    </a>

                    <div className="mt-8 rounded-2xl border border-red-100 bg-red-50 p-6">
                        <p className="text-sm text-red-700">
                            {error || 'Объявление не найдено.'}
                        </p>
                    </div>
                </main>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <main className="mx-auto w-full max-w-2xl">
                <a
                    href="/advertisements"
                    className="text-sm text-gray-500 transition hover:text-gray-900"
                >
                    ← К объявлениям
                </a>

                <article className="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
                    <h1 className="text-xl font-semibold tracking-tight text-gray-950">
                        {types[advertisement.type] ?? 'Объявление'}
                    </h1>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {advertisement.city?.name && (
                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">
                                {advertisement.city.name}
                            </span>
                        )}

                        {advertisement.district?.name && (
                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">
                                {advertisement.district.name}
                            </span>
                        )}

                        {advertisement.metro_station?.name && (
                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">
                                Метро: {advertisement.metro_station.name}
                            </span>
                        )}
                    </div>

                    <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <div className="text-xs text-gray-500">
                                Возраст участников
                            </div>

                            <div className="mt-1 text-sm text-gray-900">
                                {advertisement.participant_age_from}
                                {'–'}
                                {advertisement.participant_age_to} лет
                            </div>
                        </div>

                        {advertisement.format && (
                            <div>
                                <div className="text-xs text-gray-500">
                                    Формат обучения
                                </div>

                                <div className="mt-1 text-sm text-gray-900">
                                    {formats[advertisement.format] ??
                                        advertisement.format}
                                </div>
                            </div>
                        )}
                    </div>

                    {advertisement.subjects?.length > 0 && (
                        <div className="mt-6">
                            <div className="text-xs text-gray-500">
                                Предметы
                            </div>

                            <div className="mt-2 flex flex-wrap gap-2">
                                {advertisement.subjects.map((subject) => (
                                    <span
                                        key={subject.id}
                                        className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                                    >
                                        {subject.name}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="mt-6 border-t border-gray-100 pt-6">
                        <div className="text-xs text-gray-500">
                            Описание
                        </div>

                        <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">
                            {advertisement.description}
                        </p>
                    </div>

                    <div className="mt-6 border-t border-gray-100 pt-6">
                        {renderAction()}

                        {responseError && (
                            <p className="mt-3 text-sm text-red-600">
                                {responseError}
                            </p>
                        )}
                    </div>
                </article>
            </main>
        </div>
    );
}

function getCsrfToken() {
    return document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
}
