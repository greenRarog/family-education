export default function Dashboard() {
    return (
        <div>
            <div className="mb-6">
                <h1 className="text-xl font-semibold text-gray-900">
                    Dashboard
                </h1>

                <p className="mt-1 text-sm text-gray-500">
                    Обзор административной панели.
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="border border-gray-200 bg-white p-5">
                    <div className="text-xs font-medium uppercase tracking-wide text-gray-400">
                        Пользователи
                    </div>

                    <div className="mt-2 text-2xl font-semibold text-gray-900">
                        —
                    </div>
                </div>

                <div className="border border-gray-200 bg-white p-5">
                    <div className="text-xs font-medium uppercase tracking-wide text-gray-400">
                        Объявления
                    </div>

                    <div className="mt-2 text-2xl font-semibold text-gray-900">
                        —
                    </div>
                </div>

                <div className="border border-gray-200 bg-white p-5">
                    <div className="text-xs font-medium uppercase tracking-wide text-gray-400">
                        Жалобы
                    </div>

                    <div className="mt-2 text-2xl font-semibold text-gray-900">
                        —
                    </div>
                </div>
            </div>
        </div>
    );
}
