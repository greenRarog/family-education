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
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');

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
            })
            .catch(() => {
                setError('Не удалось загрузить объявление.');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, [advertisementId]);

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
                </article>
            </main>
        </div>
    );
}
