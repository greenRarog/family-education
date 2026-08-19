import {useEffect, useState} from 'react';

const types = {
    family_to_family: 'Ищу участников в учебную группу',
    family_to_teacher: 'Ищу педагога',
};

export default function Advertisements() {
    const [advertisements, setAdvertisements] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);

    useEffect(() => {
        setIsLoading(true);
        setError('');

        fetch(`/api/advertisements/feed?page=${currentPage}`, {
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
                const pagination = data.advertisements;

                setAdvertisements(pagination?.data ?? []);
                setCurrentPage(pagination?.current_page ?? 1);
                setLastPage(pagination?.last_page ?? 1);
            })
            .catch(() => {
                setError('Не удалось загрузить объявления.');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, [currentPage]);

    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <main className="mx-auto w-full max-w-2xl">
                <div>
                    <a
                        href="/"
                        className="text-sm text-gray-500 transition hover:text-gray-900"
                    >
                        ← На главную
                    </a>

                    <h1 className="mt-6 text-2xl font-semibold tracking-tight text-gray-950">
                        Объявления
                    </h1>

                    <p className="mt-2 text-sm leading-6 text-gray-600">
                        Найдите семьи и педагогов для совместного обучения.
                    </p>
                </div>

                {error && (
                    <p className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                        {error}
                    </p>
                )}

                {isLoading ? (
                    <p className="mt-8 text-sm text-gray-500">
                        Загрузка объявлений...
                    </p>
                ) : advertisements.length === 0 ? (
                    <div className="mt-8 rounded-2xl border border-gray-200 bg-white p-8">
                        <h2 className="text-base font-medium text-gray-900">
                            Пока нет опубликованных объявлений
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-gray-600">
                            Здесь появятся объявления семей и педагогов.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="mt-8 space-y-4">
                            {advertisements.map((advertisement) => (
                                <a
                                    key={advertisement.id}
                                    href={`/advertisements/${advertisement.id}`}
                                    className="block rounded-2xl border border-gray-200 bg-white p-6 transition hover:border-gray-300 hover:bg-gray-50"
                                >
                                    <h2 className="text-base font-medium text-gray-900">
                                        {types[advertisement.type] ??
                                            'Объявление'}
                                    </h2>

                                    <p className="mt-2 line-clamp-3 text-sm leading-6 text-gray-600">
                                        {advertisement.description}
                                    </p>

                                    <div className="mt-4 text-xs text-gray-500">
                                        {advertisement.city?.name}

                                        {advertisement.district?.name
                                            ? ` · ${advertisement.district.name}`
                                            : ''}
                                    </div>
                                </a>
                            ))}
                        </div>

                        {lastPage > 1 && (
                            <div className="mt-8 flex items-center justify-between">
                                <button
                                    type="button"
                                    disabled={currentPage === 1}
                                    onClick={() =>
                                        setCurrentPage((page) => page - 1)
                                    }
                                    className="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    ← Назад
                                </button>

                                <span className="text-sm text-gray-500">
                                    Страница {currentPage} из {lastPage}
                                </span>

                                <button
                                    type="button"
                                    disabled={currentPage === lastPage}
                                    onClick={() =>
                                        setCurrentPage((page) => page + 1)
                                    }
                                    className="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Вперёд →
                                </button>
                            </div>
                        )}
                    </>
                )}
            </main>
        </div>
    );
}
