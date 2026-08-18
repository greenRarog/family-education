const types = [
    [
        'Ищу участников в учебную группу',
        'Объединитесь с другими семьями для совместных занятий.',
        '/advertisements/new',
    ],
    [
        'Ищу педагога',
        'Разместите запрос на подходящего преподавателя.',
        '/advertisements/new-teacher',
    ],
    [
        'Предлагаю образовательную услугу',
        'Расскажите семьям о занятиях и формате работы.',
        null,
    ],
];

export default function AdvertisementTypeSelector() {
    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <main className="mx-auto w-full max-w-2xl">
                <a
                    href="/advertisements"
                    className="text-sm text-gray-500 transition hover:text-gray-900"
                >
                    ← Объявления
                </a>

                <h1 className="mt-8 text-2xl font-semibold tracking-tight text-gray-950">
                    Выберите тип объявления
                </h1>

                <p className="mt-2 text-sm leading-6 text-gray-600">
                    Тип определяет, кому будет адресовано ваше объявление.
                </p>

                <div className="mt-6 space-y-4">
                    {types.map(([title, description, href]) =>
                        href ? (
                            <a
                                key={title}
                                href={href}
                                className="block rounded-2xl border border-gray-200 bg-white p-6 transition hover:border-gray-300 hover:bg-gray-50"
                            >
                                <h2 className="text-base font-medium text-gray-900">
                                    {title}
                                </h2>

                                <p className="mt-1 text-sm leading-6 text-gray-600">
                                    {description}
                                </p>
                            </a>
                        ) : (
                            <div
                                key={title}
                                className="rounded-2xl border border-gray-200 bg-gray-50 p-6"
                            >
                                <h2 className="text-base font-medium text-gray-700">
                                    {title}
                                </h2>

                                <p className="mt-1 text-sm leading-6 text-gray-500">
                                    {description}
                                </p>

                                <p className="mt-3 text-xs font-medium text-gray-500">
                                    Будет доступно в следующем этапе
                                </p>
                            </div>
                        )
                    )}
                </div>
            </main>
        </div>
    );
}
