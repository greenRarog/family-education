import {useEffect, useMemo, useState} from 'react';

export default function FamilyProfile() {
    const [form, setForm] = useState({
        surname: '',
        city_id: '',
        district_id: '',
        metro_station_id: '',
        children: [],
    });

    const [notificationSettings, setNotificationSettings] = useState({
        email_enabled: false,
        telegram_enabled: false,
    });

    const [cities, setCities] = useState([]);
    const [districts, setDistricts] = useState([]);
    const [metroStations, setMetroStations] = useState([]);

    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [notificationError, setNotificationError] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isNotificationSubmitting, setIsNotificationSubmitting] = useState(false);
    const [saved, setSaved] = useState(false);
    const [notificationSaved, setNotificationSaved] = useState(false);

    useEffect(() => {
        loadProfile();
    }, []);

    async function loadProfile() {
        try {
            const [
                familyResponse,
                locationsResponse,
                notificationSettingsResponse,
            ] = await Promise.all([
                fetch('/api/family', {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }),
                fetch('/api/locations', {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }),
                fetch('/api/notification-settings', {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }),
            ]);

            if (
                !familyResponse.ok ||
                !locationsResponse.ok ||
                !notificationSettingsResponse.ok
            ) {
                setFormError('Не удалось загрузить профиль.');
                return;
            }

            const familyData = await familyResponse.json();
            const locationsData = await locationsResponse.json();
            const notificationSettingsData =
                await notificationSettingsResponse.json();

            const family = familyData.family;

            setForm({
                surname: family.surname ?? '',
                city_id: String(family.city_id ?? ''),
                district_id: String(family.district_id ?? ''),
                metro_station_id: String(family.metro_station_id ?? ''),
                children: (family.children ?? []).map((child) => ({
                    id: child.id,
                    name: child.name ?? '',
                    birth_date: formatDateForInput(child.birth_date),
                    sex: child.sex ?? '',
                })),
            });

            setCities(locationsData.cities ?? []);
            setDistricts(locationsData.districts ?? []);
            setMetroStations(locationsData.metro_stations ?? []);

            setNotificationSettings({
                email_enabled:
                    notificationSettingsData.email_enabled ?? false,
                telegram_enabled:
                    notificationSettingsData.telegram_enabled ?? false,
            });
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsLoading(false);
        }
    }

    const availableDistricts = useMemo(() => {
        if (!form.city_id) {
            return [];
        }

        return districts.filter(
            (district) => String(district.city_id) === String(form.city_id),
        );
    }, [districts, form.city_id]);

    const availableMetroStations = useMemo(() => {
        if (!form.city_id) {
            return [];
        }

        return metroStations.filter(
            (station) => String(station.city_id) === String(form.city_id),
        );
    }, [metroStations, form.city_id]);

    function formatDateForInput(date) {
        if (!date) {
            return '';
        }

        return String(date).slice(0, 10);
    }

    function handleChange(event) {
        const {name, value} = event.target;

        setSaved(false);

        setErrors((current) => ({
            ...current,
            [name]: undefined,
        }));

        if (name === 'city_id') {
            setForm((current) => ({
                ...current,
                city_id: value,
                district_id: '',
                metro_station_id: '',
            }));

            setErrors((current) => ({
                ...current,
                city_id: undefined,
                district_id: undefined,
                metro_station_id: undefined,
            }));

            return;
        }

        setForm((current) => ({
            ...current,
            [name]: value,
        }));
    }

    function addChild() {
        setForm((current) => ({
            ...current,
            children: [
                ...current.children,
                {
                    id: null,
                    name: '',
                    birth_date: '',
                    sex: '',
                },
            ],
        }));

        setSaved(false);
    }

    function removeChild(index) {
        setForm((current) => ({
            ...current,
            children: current.children.filter(
                (_, childIndex) => childIndex !== index,
            ),
        }));

        setErrors((current) => {
            const next = {...current};

            delete next[`children.${index}.name`];
            delete next[`children.${index}.birth_date`];
            delete next[`children.${index}.sex`];

            return next;
        });

        setSaved(false);
    }

    function handleChildChange(index, field, value) {
        setForm((current) => ({
            ...current,
            children: current.children.map((child, childIndex) =>
                childIndex === index
                    ? {
                        ...child,
                        [field]: value,
                    }
                    : child,
            ),
        }));

        setErrors((current) => ({
            ...current,
            [`children.${index}.${field}`]: undefined,
        }));

        setSaved(false);
    }

    async function handleSubmit(event) {
        event.preventDefault();

        setErrors({});
        setFormError('');
        setSaved(false);
        setIsSubmitting(true);

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/api/family', {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    surname: form.surname.trim(),
                    city_id: form.city_id,
                    district_id: form.district_id || null,
                    metro_station_id: form.metro_station_id || null,
                    children: form.children.map((child) => ({
                        id: child.id,
                        name: child.name.trim(),
                        birth_date: child.birth_date,
                        sex: child.sex,
                    })),
                }),
            });

            if (response.ok) {
                const data = await response.json();
                const family = data.family;

                setForm({
                    surname: family.surname ?? '',
                    city_id: String(family.city_id ?? ''),
                    district_id: String(family.district_id ?? ''),
                    metro_station_id: String(
                        family.metro_station_id ?? '',
                    ),
                    children: (family.children ?? []).map((child) => ({
                        id: child.id,
                        name: child.name ?? '',
                        birth_date: child.birth_date ?? '',
                        sex: child.sex ?? '',
                    })),
                });

                setSaved(true);
                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setErrors(data.errors ?? {});
                return;
            }

            setFormError('Не удалось сохранить изменения.');
        } catch {
            setFormError('Не удалось соединиться с сервером.');
        } finally {
            setIsSubmitting(false);
        }
    }

    function handleNotificationChange(event) {
        const {name, checked} = event.target;

        setNotificationSettings((current) => ({
            ...current,
            [name]: checked,
        }));

        setNotificationSaved(false);
        setNotificationError('');
    }

    async function handleNotificationSubmit(event) {
        event.preventDefault();

        setNotificationError('');
        setNotificationSaved(false);
        setIsNotificationSubmitting(true);

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const response = await fetch('/api/notification-settings', {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    email_enabled: notificationSettings.email_enabled,
                    telegram_enabled:
                    notificationSettings.telegram_enabled,
                }),
            });

            if (response.ok) {
                const data = await response.json();

                setNotificationSettings({
                    email_enabled: data.email_enabled ?? false,
                    telegram_enabled: data.telegram_enabled ?? false,
                });

                setNotificationSaved(true);
                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setNotificationError(
                    Object.values(data.errors ?? {})
                        .flat()
                        .join(' '),
                );

                return;
            }

            setNotificationError(
                'Не удалось сохранить настройки уведомлений.',
            );
        } catch {
            setNotificationError('Не удалось соединиться с сервером.');
        } finally {
            setIsNotificationSubmitting(false);
        }
    }

    function fieldClass(field) {
        return [
            'block',
            'w-full',
            'rounded-xl',
            'border',
            'bg-white',
            'px-3.5',
            'py-3',
            'text-sm',
            'text-gray-900',
            'outline-none',
            'transition',
            'disabled:cursor-not-allowed',
            'disabled:bg-gray-50',
            errors[field]
                ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100'
                : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100',
        ].join(' ');
    }

    function errorMessage(error) {
        return Array.isArray(error) ? error[0] : error;
    }

    if (isLoading) {
        return (
            <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
                <div className="mx-auto w-full max-w-2xl">
                    <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                        <p className="text-sm text-gray-500">
                            Загрузка профиля...
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <div className="mx-auto w-full max-w-2xl">
                <div className="mb-8">
                    <a
                        href="/"
                        className="text-sm text-gray-500 transition hover:text-gray-900"
                    >
                        ← Family Education
                    </a>

                    <h1 className="mt-6 text-2xl font-semibold tracking-tight text-gray-900">
                        Профиль семьи
                    </h1>

                    <p className="mt-2 text-sm leading-6 text-gray-500">
                        Здесь можно изменить информацию о семье и месте
                        проживания.
                    </p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                    {formError && (
                        <div
                            className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700">
                            {formError}
                        </div>
                    )}

                    {saved && (
                        <div
                            className="mb-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-5 text-gray-700">
                            Изменения сохранены.
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <label
                                htmlFor="surname"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Фамилия семьи
                            </label>

                            <input
                                id="surname"
                                name="surname"
                                type="text"
                                value={form.surname}
                                onChange={handleChange}
                                disabled={isSubmitting}
                                className={fieldClass('surname')}
                            />

                            {errors.surname && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.surname)}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="city_id"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Город
                            </label>

                            <select
                                id="city_id"
                                name="city_id"
                                value={form.city_id}
                                onChange={handleChange}
                                disabled={isSubmitting}
                                className={fieldClass('city_id')}
                            >
                                <option value="">Выберите город</option>

                                {cities.map((city) => (
                                    <option key={city.id} value={city.id}>
                                        {city.name}
                                    </option>
                                ))}
                            </select>

                            {errors.city_id && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.city_id)}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="district_id"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Район
                            </label>

                            <select
                                id="district_id"
                                name="district_id"
                                value={form.district_id}
                                onChange={handleChange}
                                disabled={
                                    isSubmitting ||
                                    !form.city_id ||
                                    availableDistricts.length === 0
                                }
                                className={fieldClass('district_id')}
                            >
                                <option value="">
                                    {form.city_id
                                        ? availableDistricts.length > 0
                                            ? 'Выберите район'
                                            : 'Районы не указаны'
                                        : 'Сначала выберите город'}
                                </option>

                                {availableDistricts.map((district) => (
                                    <option
                                        key={district.id}
                                        value={district.id}
                                    >
                                        {district.name}
                                    </option>
                                ))}
                            </select>

                            {errors.district_id && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.district_id)}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="metro_station_id"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Станция метро
                            </label>

                            <select
                                id="metro_station_id"
                                name="metro_station_id"
                                value={form.metro_station_id}
                                onChange={handleChange}
                                disabled={
                                    isSubmitting ||
                                    !form.city_id ||
                                    availableMetroStations.length === 0
                                }
                                className={fieldClass('metro_station_id')}
                            >
                                <option value="">
                                    {form.city_id
                                        ? availableMetroStations.length > 0
                                            ? 'Выберите станцию'
                                            : 'Станции метро не указаны'
                                        : 'Сначала выберите город'}
                                </option>

                                {availableMetroStations.map((station) => (
                                    <option
                                        key={station.id}
                                        value={station.id}
                                    >
                                        {station.name}
                                    </option>
                                ))}
                            </select>

                            {errors.metro_station_id && (
                                <p className="mt-1.5 text-sm text-red-600">
                                    {errorMessage(errors.metro_station_id)}
                                </p>
                            )}
                        </div>

                        <div className="border-t border-gray-100 pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-sm font-medium text-gray-700">
                                        Дети
                                    </h2>

                                    <p className="mt-1 text-sm text-gray-500">
                                        Добавьте информацию о детях вашей семьи.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={addChild}
                                    disabled={isSubmitting}
                                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    + Добавить
                                </button>
                            </div>

                            {errors.children && (
                                <p className="mt-3 text-sm text-red-600">
                                    {errorMessage(errors.children)}
                                </p>
                            )}

                            <div className="mt-6 space-y-5">
                                {form.children.map((child, index) => (
                                    <div
                                        key={child.id ?? `new-${index}`}
                                        className="rounded-xl border border-gray-200 bg-gray-50 p-5"
                                    >
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-medium text-gray-900">
                                                Ребёнок {index + 1}
                                            </h3>

                                            {form.children.length > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        removeChild(index)
                                                    }
                                                    disabled={isSubmitting}
                                                    className="text-sm text-gray-500 transition hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Удалить
                                                </button>
                                            )}
                                        </div>

                                        <div className="space-y-4">
                                            <div>
                                                <label
                                                    htmlFor={`child-${index}-name`}
                                                    className="mb-2 block text-sm font-medium text-gray-700"
                                                >
                                                    Имя
                                                </label>

                                                <input
                                                    id={`child-${index}-name`}
                                                    type="text"
                                                    value={child.name}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={isSubmitting}
                                                    className={fieldClass(
                                                        `children.${index}.name`,
                                                    )}
                                                />

                                                {errors[
                                                    `children.${index}.name`
                                                    ] && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {errorMessage(
                                                            errors[
                                                                `children.${index}.name`
                                                                ],
                                                        )}
                                                    </p>
                                                )}
                                            </div>

                                            <div>
                                                <label
                                                    htmlFor={`child-${index}-birth-date`}
                                                    className="mb-2 block text-sm font-medium text-gray-700"
                                                >
                                                    Дата рождения
                                                </label>

                                                <input
                                                    id={`child-${index}-birth-date`}
                                                    type="date"
                                                    value={child.birth_date}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            'birth_date',
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={isSubmitting}
                                                    className={fieldClass(
                                                        `children.${index}.birth_date`,
                                                    )}
                                                />

                                                {errors[
                                                    `children.${index}.birth_date`
                                                    ] && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {errorMessage(
                                                            errors[
                                                                `children.${index}.birth_date`
                                                                ],
                                                        )}
                                                    </p>
                                                )}
                                            </div>

                                            <div>
                                                <label
                                                    htmlFor={`child-${index}-sex`}
                                                    className="mb-2 block text-sm font-medium text-gray-700"
                                                >
                                                    Пол
                                                </label>

                                                <select
                                                    id={`child-${index}-sex`}
                                                    value={child.sex}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            'sex',
                                                            event.target.value,
                                                        )
                                                    }
                                                    disabled={isSubmitting}
                                                    className={fieldClass(
                                                        `children.${index}.sex`,
                                                    )}
                                                >
                                                    <option value="">
                                                        Выберите
                                                    </option>
                                                    <option value="male">
                                                        Мальчик
                                                    </option>
                                                    <option value="female">
                                                        Девочка
                                                    </option>
                                                </select>

                                                {errors[
                                                    `children.${index}.sex`
                                                    ] && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {errorMessage(
                                                            errors[
                                                                `children.${index}.sex`
                                                                ],
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="border-t border-gray-100 pt-6">
                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isSubmitting
                                    ? 'Сохранение...'
                                    : 'Сохранить изменения'}
                            </button>
                        </div>
                    </form>

                    {/* Настройки уведомлений */}
                    <div className="mt-10 border-t border-gray-100 pt-8">
                        <div className="mb-6">
                            <h2 className="text-sm font-medium text-gray-700">
                                Уведомления
                            </h2>

                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                Выберите, куда отправлять уведомления о важных
                                событиях.
                            </p>
                        </div>

                        {notificationError && (
                            <div
                                className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700"
                            >
                                {notificationError}
                            </div>
                        )}

                        {notificationSaved && (
                            <div
                                className="mb-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-5 text-gray-700"
                            >
                                Настройки уведомлений сохранены.
                            </div>
                        )}

                        <form
                            onSubmit={handleNotificationSubmit}
                            className="space-y-3"
                        >
                            <label
                                className="flex cursor-pointer items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 transition hover:bg-gray-100"
                            >
                                <div>
                                    <div className="text-sm font-medium text-gray-900">
                                        Электронная почта
                                    </div>

                                    <div className="mt-1 text-sm text-gray-500">
                                        Получать уведомления на электронную почту.
                                    </div>
                                </div>

                                <input
                                    type="checkbox"
                                    name="email_enabled"
                                    checked={notificationSettings.email_enabled}
                                    onChange={handleNotificationChange}
                                    disabled={isNotificationSubmitting}
                                    className="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-2 focus:ring-gray-300"
                                />
                            </label>

                            <label
                                className="flex cursor-pointer items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 transition hover:bg-gray-100"
                            >
                                <div>
                                    <div className="text-sm font-medium text-gray-900">
                                        Telegram
                                    </div>

                                    <div className="mt-1 text-sm text-gray-500">
                                        Получать уведомления в Telegram.
                                    </div>
                                </div>

                                <input
                                    type="checkbox"
                                    name="telegram_enabled"
                                    checked={
                                        notificationSettings.telegram_enabled
                                    }
                                    onChange={handleNotificationChange}
                                    disabled={isNotificationSubmitting}
                                    className="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-2 focus:ring-gray-300"
                                />
                            </label>

                            <div className="pt-3">
                                <button
                                    type="submit"
                                    disabled={isNotificationSubmitting}
                                    className="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {isNotificationSubmitting
                                        ? 'Сохранение...'
                                        : 'Сохранить настройки уведомлений'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
