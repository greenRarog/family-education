import {useEffect, useMemo, useState} from 'react';
import AdminLayout from '../../layouts/AdminLayout';

export default function Cities() {
    const [cities, setCities] = useState([]);
    const [districts, setDistricts] = useState([]);
    const [metroStations, setMetroStations] = useState([]);

    const [isLoading, setIsLoading] = useState(true);
    const [formError, setFormError] = useState('');

    const [expandedCityId, setExpandedCityId] = useState(null);

    const [isCityFormOpen, setIsCityFormOpen] = useState(false);
    const [editingCity, setEditingCity] = useState(null);
    const [cityName, setCityName] = useState('');
    const [cityErrors, setCityErrors] = useState({});
    const [isCitySubmitting, setIsCitySubmitting] = useState(false);

    const [editingDistrict, setEditingDistrict] = useState(null);
    const [districtName, setDistrictName] = useState('');
    const [districtErrors, setDistrictErrors] = useState({});
    const [isDistrictSubmitting, setIsDistrictSubmitting] = useState(false);

    const [editingMetroStation, setEditingMetroStation] = useState(null);
    const [metroStationName, setMetroStationName] = useState('');
    const [metroStationErrors, setMetroStationErrors] = useState({});
    const [isMetroStationSubmitting, setIsMetroStationSubmitting] =
        useState(false);

    const [deletingId, setDeletingId] = useState(null);

    useEffect(() => {
        loadData();
    }, []);

    async function loadData() {
        setIsLoading(true);
        setFormError('');

        try {
            const [citiesResponse, districtsResponse, metroResponse] =
                await Promise.all([
                    fetch('/api/admin/cities', {
                        headers: {
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                    }),
                    fetch('/api/admin/districts', {
                        headers: {
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                    }),
                    fetch('/api/admin/metro-stations', {
                        headers: {
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                    }),
                ]);

            if (
                !citiesResponse.ok ||
                !districtsResponse.ok ||
                !metroResponse.ok
            ) {
                setFormError('Не удалось загрузить справочники.');
                return;
            }

            const [
                citiesData,
                districtsData,
                metroStationsData,
            ] = await Promise.all([
                citiesResponse.json(),
                districtsResponse.json(),
                metroResponse.json(),
            ]);

            setCities(citiesData);
            setDistricts(districtsData);
            setMetroStations(metroStationsData);
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsLoading(false);
        }
    }

    function getCsrfToken() {
        return document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content');
    }

    function errorMessage(error) {
        return Array.isArray(error) ? error[0] : error;
    }

    function toggleCity(cityId) {
        setExpandedCityId((current) =>
            current === cityId ? null : cityId,
        );

        cancelDistrictEdit();
        cancelMetroStationEdit();
    }

    /*
     * Cities
     */

    function openCreateCityForm() {
        setEditingCity(null);
        setCityName('');
        setCityErrors({});
        setFormError('');
        setIsCityFormOpen(true);
    }

    function openEditCityForm(city) {
        setEditingCity(city);
        setCityName(city.name);
        setCityErrors({});
        setFormError('');
        setIsCityFormOpen(true);
    }

    function closeCityForm() {
        if (isCitySubmitting) {
            return;
        }

        setIsCityFormOpen(false);
        setEditingCity(null);
        setCityName('');
        setCityErrors({});
    }

    async function handleCitySubmit(event) {
        event.preventDefault();

        setCityErrors({});
        setFormError('');
        setIsCitySubmitting(true);

        const isEditing = editingCity !== null;

        try {
            const url = isEditing
                ? `/api/admin/cities/${editingCity.id}`
                : '/api/admin/cities';

            const response = await fetch(url, {
                method: isEditing ? 'PUT' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: cityName.trim(),
                }),
            });

            if (response.ok) {
                const city = await response.json();

                if (isEditing) {
                    setCities((current) =>
                        current
                            .map((item) =>
                                item.id === city.id ? city : item,
                            )
                            .sort((a, b) =>
                                a.name.localeCompare(b.name, 'ru'),
                            ),
                    );
                } else {
                    setCities((current) =>
                        [...current, city].sort((a, b) =>
                            a.name.localeCompare(b.name, 'ru'),
                        ),
                    );
                }

                closeCityForm();

                return;
            }

            if (response.status === 422) {
                const data = await response.json();
                setCityErrors(data.errors ?? {});
                return;
            }

            setFormError(
                isEditing
                    ? 'Не удалось изменить город.'
                    : 'Не удалось создать город.',
            );
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsCitySubmitting(false);
        }
    }

    async function handleDeleteCity(city) {
        const confirmed = window.confirm(
            `Удалить город «${city.name}»?`,
        );

        if (!confirmed) {
            return;
        }

        setDeletingId(`city-${city.id}`);
        setFormError('');

        try {
            const response = await fetch(
                `/api/admin/cities/${city.id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (response.status === 204) {
                setCities((current) =>
                    current.filter((item) => item.id !== city.id),
                );

                setDistricts((current) =>
                    current.filter(
                        (item) => item.city_id !== city.id,
                    ),
                );

                setMetroStations((current) =>
                    current.filter(
                        (item) => item.city_id !== city.id,
                    ),
                );

                if (expandedCityId === city.id) {
                    setExpandedCityId(null);
                }

                return;
            }

            setFormError('Не удалось удалить город.');
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setDeletingId(null);
        }
    }

    /*
     * Districts
     */

    function startCreateDistrict() {
        setEditingDistrict({
            id: null,
            city_id: expandedCityId,
        });

        setDistrictName('');
        setDistrictErrors({});
    }

    function startEditDistrict(district) {
        setEditingDistrict(district);
        setDistrictName(district.name);
        setDistrictErrors({});
    }

    function cancelDistrictEdit() {
        setEditingDistrict(null);
        setDistrictName('');
        setDistrictErrors({});
    }

    async function handleDistrictSubmit(event) {
        event.preventDefault();

        setDistrictErrors({});
        setFormError('');
        setIsDistrictSubmitting(true);

        const isEditing = editingDistrict?.id !== null;

        try {
            const url = isEditing
                ? `/api/admin/districts/${editingDistrict.id}`
                : '/api/admin/districts';

            const response = await fetch(url, {
                method: isEditing ? 'PUT' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    city_id: expandedCityId,
                    name: districtName.trim(),
                }),
            });

            if (response.ok) {
                const district = await response.json();

                if (isEditing) {
                    setDistricts((current) =>
                        current.map((item) =>
                            item.id === district.id
                                ? district
                                : item,
                        ),
                    );
                } else {
                    setDistricts((current) =>
                        [...current, district].sort((a, b) =>
                            a.name.localeCompare(b.name, 'ru'),
                        ),
                    );
                }

                cancelDistrictEdit();

                return;
            }

            if (response.status === 422) {
                const data = await response.json();
                setDistrictErrors(data.errors ?? {});
                return;
            }

            setFormError(
                isEditing
                    ? 'Не удалось изменить район.'
                    : 'Не удалось создать район.',
            );
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsDistrictSubmitting(false);
        }
    }

    async function handleDeleteDistrict(district) {
        const confirmed = window.confirm(
            `Удалить район «${district.name}»?`,
        );

        if (!confirmed) {
            return;
        }

        setDeletingId(`district-${district.id}`);

        try {
            const response = await fetch(
                `/api/admin/districts/${district.id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (response.status === 204) {
                setDistricts((current) =>
                    current.filter(
                        (item) => item.id !== district.id,
                    ),
                );

                return;
            }

            setFormError('Не удалось удалить район.');
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setDeletingId(null);
        }
    }

    /*
     * Metro stations
     */

    function startCreateMetroStation() {
        setEditingMetroStation({
            id: null,
            city_id: expandedCityId,
        });

        setMetroStationName('');
        setMetroStationErrors({});
    }

    function startEditMetroStation(station) {
        setEditingMetroStation(station);
        setMetroStationName(station.name);
        setMetroStationErrors({});
    }

    function cancelMetroStationEdit() {
        setEditingMetroStation(null);
        setMetroStationName('');
        setMetroStationErrors({});
    }

    async function handleMetroStationSubmit(event) {
        event.preventDefault();

        setMetroStationErrors({});
        setFormError('');
        setIsMetroStationSubmitting(true);

        const isEditing = editingMetroStation?.id !== null;

        try {
            const url = isEditing
                ? `/api/admin/metro-stations/${editingMetroStation.id}`
                : '/api/admin/metro-stations';

            const response = await fetch(url, {
                method: isEditing ? 'PUT' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    city_id: expandedCityId,
                    name: metroStationName.trim(),
                }),
            });

            if (response.ok) {
                const station = await response.json();

                if (isEditing) {
                    setMetroStations((current) =>
                        current.map((item) =>
                            item.id === station.id
                                ? station
                                : item,
                        ),
                    );
                } else {
                    setMetroStations((current) =>
                        [...current, station].sort((a, b) =>
                            a.name.localeCompare(b.name, 'ru'),
                        ),
                    );
                }

                cancelMetroStationEdit();

                return;
            }

            if (response.status === 422) {
                const data = await response.json();
                setMetroStationErrors(data.errors ?? {});
                return;
            }

            setFormError(
                isEditing
                    ? 'Не удалось изменить станцию метро.'
                    : 'Не удалось создать станцию метро.',
            );
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsMetroStationSubmitting(false);
        }
    }

    async function handleDeleteMetroStation(station) {
        const confirmed = window.confirm(
            `Удалить станцию «${station.name}»?`,
        );

        if (!confirmed) {
            return;
        }

        setDeletingId(`metro-${station.id}`);

        try {
            const response = await fetch(
                `/api/admin/metro-stations/${station.id}`,
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                },
            );

            if (response.status === 204) {
                setMetroStations((current) =>
                    current.filter(
                        (item) => item.id !== station.id,
                    ),
                );

                return;
            }

            setFormError('Не удалось удалить станцию метро.');
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setDeletingId(null);
        }
    }

    const districtsByCity = useMemo(() => {
        const result = {};

        for (const district of districts) {
            if (!result[district.city_id]) {
                result[district.city_id] = [];
            }

            result[district.city_id].push(district);
        }

        return result;
    }, [districts]);

    const metroStationsByCity = useMemo(() => {
        const result = {};

        for (const station of metroStations) {
            if (!result[station.city_id]) {
                result[station.city_id] = [];
            }

            result[station.city_id].push(station);
        }

        return result;
    }, [metroStations]);

    return (
        <AdminLayout title="Города">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <p className="text-sm text-gray-500">
                        Управление городами, районами и станциями метро.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={openCreateCityForm}
                    className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-800"
                >
                    + Добавить город
                </button>
            </div>

            {formError && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {formError}
                </div>
            )}

            {isCityFormOpen && (
                <div className="mb-6 rounded-md border border-gray-200 bg-white p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-900">
                            {editingCity
                                ? 'Редактирование города'
                                : 'Новый город'}
                        </h2>

                        <button
                            type="button"
                            onClick={closeCityForm}
                            disabled={isCitySubmitting}
                            className="text-sm text-gray-500 hover:text-gray-900"
                        >
                            Закрыть
                        </button>
                    </div>

                    <form onSubmit={handleCitySubmit}>
                        <label
                            htmlFor="city-name"
                            className="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Название
                        </label>

                        <input
                            id="city-name"
                            type="text"
                            value={cityName}
                            onChange={(event) => {
                                setCityName(event.target.value);
                                setCityErrors((current) => ({
                                    ...current,
                                    name: undefined,
                                }));
                            }}
                            disabled={isCitySubmitting}
                            autoFocus
                            className={[
                                'block',
                                'w-full',
                                'rounded-md',
                                'border',
                                'bg-white',
                                'px-3',
                                'py-2',
                                'text-sm',
                                'text-gray-900',
                                'outline-none',
                                'focus:ring-2',
                                cityErrors.name
                                    ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
                                    : 'border-gray-300 focus:border-gray-500 focus:ring-gray-100',
                            ].join(' ')}
                        />

                        {cityErrors.name && (
                            <p className="mt-1.5 text-sm text-red-600">
                                {errorMessage(cityErrors.name)}
                            </p>
                        )}

                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={closeCityForm}
                                disabled={isCitySubmitting}
                                className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                Отмена
                            </button>

                            <button
                                type="submit"
                                disabled={
                                    isCitySubmitting ||
                                    !cityName.trim()
                                }
                                className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                            >
                                {isCitySubmitting
                                    ? 'Сохранение...'
                                    : editingCity
                                        ? 'Сохранить'
                                        : 'Создать'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            <div className="overflow-hidden rounded-md border border-gray-200 bg-white">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-gray-200 bg-gray-50">
                    <tr>
                        <th className="w-20 px-4 py-3 font-medium text-gray-500">
                            ID
                        </th>

                        <th className="px-4 py-3 font-medium text-gray-500">
                            Город
                        </th>

                        <th className="w-28 px-4 py-3 font-medium text-gray-500">
                            Районы
                        </th>

                        <th className="w-40 px-4 py-3 font-medium text-gray-500">
                            Метро
                        </th>

                        <th className="w-56 px-4 py-3 text-right font-medium text-gray-500">
                            Действия
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    {isLoading ? (
                        <tr>
                            <td
                                colSpan="5"
                                className="px-4 py-8 text-center text-gray-500"
                            >
                                Загрузка...
                            </td>
                        </tr>
                    ) : cities.length === 0 ? (
                        <tr>
                            <td
                                colSpan="5"
                                className="px-4 py-8 text-center text-gray-500"
                            >
                                Нет данных.
                            </td>
                        </tr>
                    ) : (
                        cities.map((city) => {
                            const cityDistricts =
                                districtsByCity[city.id] ?? [];

                            const cityMetroStations =
                                metroStationsByCity[city.id] ?? [];

                            const isExpanded =
                                expandedCityId === city.id;

                            return (
                                <tr
                                    key={city.id}
                                    className="border-b border-gray-100 last:border-b-0"
                                >
                                    <td
                                        colSpan="5"
                                        className="p-0"
                                    >
                                        <div className="flex items-center">
                                            <div className="w-20 px-4 py-3 text-gray-500">
                                                {city.id}
                                            </div>

                                            <div className="flex-1 px-4 py-3 font-medium text-gray-900">
                                                {city.name}
                                            </div>

                                            <div className="w-28 px-4 py-3 text-gray-500">
                                                {cityDistricts.length}
                                            </div>

                                            <div className="w-40 px-4 py-3 text-gray-500">
                                                {cityMetroStations.length}
                                            </div>

                                            <div className="w-56 px-4 py-3 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggleCity(
                                                            city.id,
                                                        )
                                                    }
                                                    className="mr-3 text-sm text-gray-600 hover:text-gray-900"
                                                >
                                                    {isExpanded
                                                        ? 'Свернуть'
                                                        : 'Управлять'}
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        openEditCityForm(
                                                            city,
                                                        )
                                                    }
                                                    className="mr-3 text-sm text-gray-600 hover:text-gray-900"
                                                >
                                                    Изменить
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleDeleteCity(
                                                            city,
                                                        )
                                                    }
                                                    disabled={
                                                        deletingId ===
                                                        `city-${city.id}`
                                                    }
                                                    className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50"
                                                >
                                                    {deletingId ===
                                                    `city-${city.id}`
                                                        ? 'Удаление...'
                                                        : 'Удалить'}
                                                </button>
                                            </div>
                                        </div>

                                        {isExpanded && (
                                            <div className="border-t border-gray-200 bg-gray-50 px-6 py-6">
                                                <div className="grid gap-6 lg:grid-cols-2">
                                                    <ReferenceSection
                                                        title="Районы"
                                                        items={
                                                            cityDistricts
                                                        }
                                                        emptyText="Районов нет."
                                                        onAdd={
                                                            startCreateDistrict
                                                        }
                                                        addText="Добавить район"
                                                        editingItem={
                                                            editingDistrict
                                                        }
                                                        editingName={
                                                            districtName
                                                        }
                                                        setEditingName={
                                                            setDistrictName
                                                        }
                                                        errors={
                                                            districtErrors
                                                        }
                                                        isSubmitting={
                                                            isDistrictSubmitting
                                                        }
                                                        onSubmit={
                                                            handleDistrictSubmit
                                                        }
                                                        onCancel={
                                                            cancelDistrictEdit
                                                        }
                                                        onEdit={
                                                            startEditDistrict
                                                        }
                                                        onDelete={
                                                            handleDeleteDistrict
                                                        }
                                                        deletingId={
                                                            deletingId
                                                        }
                                                        type="district"
                                                    />

                                                    <ReferenceSection
                                                        title="Станции метро"
                                                        items={
                                                            cityMetroStations
                                                        }
                                                        emptyText="Станций метро нет."
                                                        onAdd={
                                                            startCreateMetroStation
                                                        }
                                                        addText="Добавить станцию"
                                                        editingItem={
                                                            editingMetroStation
                                                        }
                                                        editingName={
                                                            metroStationName
                                                        }
                                                        setEditingName={
                                                            setMetroStationName
                                                        }
                                                        errors={
                                                            metroStationErrors
                                                        }
                                                        isSubmitting={
                                                            isMetroStationSubmitting
                                                        }
                                                        onSubmit={
                                                            handleMetroStationSubmit
                                                        }
                                                        onCancel={
                                                            cancelMetroStationEdit
                                                        }
                                                        onEdit={
                                                            startEditMetroStation
                                                        }
                                                        onDelete={
                                                            handleDeleteMetroStation
                                                        }
                                                        deletingId={
                                                            deletingId
                                                        }
                                                        type="metro"
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            );
                        })
                    )}
                    </tbody>
                </table>
            </div>
        </AdminLayout>
    );
}

function ReferenceSection({
                              title,
                              items,
                              emptyText,
                              onAdd,
                              addText,
                              editingItem,
                              editingName,
                              setEditingName,
                              errors,
                              isSubmitting,
                              onSubmit,
                              onCancel,
                              onEdit,
                              onDelete,
                              deletingId,
                              type,
                          }) {
    return (
        <div className="rounded-md border border-gray-200 bg-white">
            <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h3 className="text-sm font-semibold text-gray-900">
                    {title}
                </h3>

                <button
                    type="button"
                    onClick={onAdd}
                    className="text-sm font-medium text-gray-700 hover:text-gray-900"
                >
                    + {addText}
                </button>
            </div>

            {editingItem && (
                <form
                    onSubmit={onSubmit}
                    className="border-b border-gray-200 bg-gray-50 p-4"
                >
                    <label className="mb-2 block text-sm font-medium text-gray-700">
                        Название
                    </label>

                    <input
                        type="text"
                        value={editingName}
                        onChange={(event) => {
                            setEditingName(event.target.value);
                        }}
                        disabled={isSubmitting}
                        autoFocus
                        className={[
                            'w-full',
                            'rounded-md',
                            'border',
                            'px-3',
                            'py-2',
                            'text-sm',
                            'outline-none',
                            errors.name
                                ? 'border-red-400'
                                : 'border-gray-300',
                        ].join(' ')}
                    />

                    {errors.name && (
                        <p className="mt-1 text-sm text-red-600">
                            {Array.isArray(errors.name)
                                ? errors.name[0]
                                : errors.name}
                        </p>
                    )}

                    <div className="mt-3 flex gap-2">
                        <button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                !editingName.trim()
                            }
                            className="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
                        >
                            {isSubmitting
                                ? 'Сохранение...'
                                : editingItem.id
                                    ? 'Сохранить'
                                    : 'Создать'}
                        </button>

                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={isSubmitting}
                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                        >
                            Отмена
                        </button>
                    </div>
                </form>
            )}

            {items.length === 0 ? (
                <div className="px-4 py-6 text-center text-sm text-gray-500">
                    {emptyText}
                </div>
            ) : (
                <div>
                    {items.map((item) => (
                        <div
                            key={item.id}
                            className="flex items-center justify-between border-b border-gray-100 px-4 py-3 last:border-b-0"
                        >
                            <span className="text-sm text-gray-900">
                                {item.name}
                            </span>

                            <div>
                                <button
                                    type="button"
                                    onClick={() => onEdit(item)}
                                    className="mr-3 text-sm text-gray-500 hover:text-gray-900"
                                >
                                    Изменить
                                </button>

                                <button
                                    type="button"
                                    onClick={() => onDelete(item)}
                                    disabled={
                                        deletingId ===
                                        `${type}-${item.id}`
                                    }
                                    className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50"
                                >
                                    {deletingId ===
                                    `${type}-${item.id}`
                                        ? 'Удаление...'
                                        : 'Удалить'}
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
