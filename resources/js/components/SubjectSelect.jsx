export default function SubjectSelect({
                                          subjects,
                                          selectedIds,
                                          onChange,
                                          error,
                                          isLoading,
                                      }) {
    function toggleSubject(id) {
        const value = String(id);

        onChange(
            selectedIds.includes(value)
                ? selectedIds.filter((item) => item !== value)
                : [...selectedIds, value]
        );
    }

    const selectedSubjects = subjects.filter((subject) =>
        selectedIds.includes(String(subject.id))
    );

    const availableSubjects = subjects.filter(
        (subject) => !selectedIds.includes(String(subject.id))
    );

    return (
        <div>
            <label className="text-sm font-medium text-gray-800">
                Предметы
            </label>

            <p className="mt-1 text-sm text-gray-500">
                Можно выбрать один или несколько предметов.
            </p>

            {selectedSubjects.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {selectedSubjects.map((subject) => (
                        <span
                            key={subject.id}
                            className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1.5 text-sm text-gray-800"
                        >
                            {subject.name}

                            <button
                                type="button"
                                onClick={() =>
                                    toggleSubject(subject.id)
                                }
                                disabled={isLoading}
                                className="inline-flex h-4 w-4 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-200 hover:text-gray-900 disabled:cursor-not-allowed"
                                aria-label={`Убрать предмет ${subject.name}`}
                            >
                                ×
                            </button>
                        </span>
                    ))}
                </div>
            )}

            <div
                className={`mt-3 rounded-xl border ${
                    error
                        ? 'border-red-400'
                        : 'border-gray-200'
                }`}
            >
                {isLoading ? (
                    <p className="px-4 py-3 text-sm text-gray-500">
                        Загрузка предметов...
                    </p>
                ) : subjects.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-gray-500">
                        Предметы не найдены.
                    </p>
                ) : availableSubjects.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-gray-500">
                        Все доступные предметы выбраны.
                    </p>
                ) : (
                    <div className="max-h-48 overflow-y-auto p-2">
                        {availableSubjects.map((subject) => (
                            <label
                                key={subject.id}
                                className="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-800 transition hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    checked={false}
                                    onChange={() =>
                                        toggleSubject(subject.id)
                                    }
                                    className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-400"
                                />

                                {subject.name}
                            </label>
                        ))}
                    </div>
                )}
            </div>

            {error && (
                <p className="mt-2 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
