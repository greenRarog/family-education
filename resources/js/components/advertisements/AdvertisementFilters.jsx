import {useEffect, useState} from 'react';
const formats = {
    online: 'Онлайн',
    offline: 'Офлайн',
    hybrid: 'Гибридный',
};
export default function AdvertisementFilters({
                                                 filters,
                                                 types,
                                                 onChange,
                                                 onReset,
                                                 hasActiveFilters,
                                             }) {
    const [cities, setCities] = useState([]);
    const [subjects, setSubjects] = useState([]);

    useEffect(() => {
        fetch('/api/cities', {
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
                setCities(data.cities ?? data ?? []);
            })
            .catch(() => {
                setCities([]);
            });
    }, []);

    useEffect(() => {
        fetch('/api/subjects', {
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
                setSubjects(data.subjects ?? data ?? []);
            })
            .catch(() => {
                setSubjects([]);
            });
    }, []);

    return (
        <div className="mt-8 rounded-2xl border border-gray-200 bg-white p-5">
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-medium text-gray-900">
                    Фильтры
                </h2>

                {hasActiveFilters && (
                    <button
                        type="button"
                        onClick={onReset}
                        className="text-xs text-gray-500 transition hover:text-gray-900"
                    >
                        Сбросить
                    </button>
                )}
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        htmlFor="advertisement-type"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Тип объявления
                    </label>

                    <select
                        id="advertisement-type"
                        value={filters.type}
                        onChange={(event) =>
                            onChange('type', event.target.value)
                        }
                        className="mt-2 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-gray-400 focus:ring-0"
                    >
                        <option value="">Все объявления</option>

                        {Object.entries(types).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label
                        htmlFor="advertisement-city"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Город
                    </label>

                    <select
                        id="advertisement-city"
                        value={filters.city_id}
                        onChange={(event) =>
                            onChange('city_id', event.target.value)
                        }
                        className="mt-2 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-gray-400 focus:ring-0"
                    >
                        <option value="">Все города</option>

                        {cities.map((city) => (
                            <option key={city.id} value={city.id}>
                                {city.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label
                        htmlFor="advertisement-age"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Возраст
                    </label>

                    <select
                        id="advertisement-age"
                        value={filters.age}
                        onChange={(event) =>
                            onChange('age', event.target.value)
                        }
                        className="mt-2 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-gray-400 focus:ring-0"
                    >
                        <option value="">Любой возраст</option>

                        {Array.from({length: 19}, (_, age) => (
                            <option key={age} value={age}>
                                {age} лет
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label
                        htmlFor="advertisement-subject"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Предмет
                    </label>

                    <select
                        id="advertisement-subject"
                        value={filters.subject_id}
                        onChange={(event) =>
                            onChange('subject_id', event.target.value)
                        }
                        className="mt-2 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-gray-400 focus:ring-0"
                    >
                        <option value="">Все предметы</option>

                        {subjects.map((subject) => (
                            <option key={subject.id} value={subject.id}>
                                {subject.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label
                        htmlFor="advertisement-format"
                        className="block text-sm font-medium text-gray-700"
                    >
                        Формат обучения
                    </label>

                    <select
                        id="advertisement-format"
                        value={filters.format}
                        onChange={(event) =>
                            onChange('format', event.target.value)
                        }
                        className="mt-2 block w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none transition focus:border-gray-400 focus:ring-0"
                    >
                        <option value="">Любой формат</option>

                        {Object.entries(formats).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
        </div>
    );
}
