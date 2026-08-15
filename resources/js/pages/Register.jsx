import {useEffect, useRef, useState} from 'react';

export default function Register() {
    const [form, setForm] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        surname: '',
        city_id: '',
        district_id: '',
        metro_station_id: '',
        children: [
            {
                name: '',
                birth_date: '',
                sex: '',
            },
        ],
    });

    const [cities, setCities] = useState([]);
    const [districts, setDistricts] = useState([]);
    const [metroStations, setMetroStations] = useState([]);

    const [errors, setErrors] = useState({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isLoadingCities, setIsLoadingCities] = useState(true);
    const [isLoadingLocationData, setIsLoadingLocationData] = useState(false);

    const turnstileRef = useRef(null);
    const turnstileWidgetId = useRef(null);
    const turnstileToken = useRef('');

    const turnstileSiteKey = document
        .querySelector('meta[name="turnstile-site-key"]')
        ?.getAttribute('content');

    useEffect(() => {
        loadCities();

        if (!turnstileSiteKey) {
            console.error('Cloudflare Turnstile site key is not configured.');
            return;
        }

        if (!window.turnstile) {
            console.error('Cloudflare Turnstile script is not loaded.');
            return;
        }

        if (!turnstileRef.current) {
            return;
        }

        turnstileWidgetId.current = window.turnstile.render(
            turnstileRef.current,
            {
                sitekey: turnstileSiteKey,
                callback: (token) => {
                    turnstileToken.current = token;

                    setErrors((current) => ({
                        ...current,
                        'cf-turnstile-response': undefined,
                        form: undefined,
                    }));
                },
                'expired-callback': () => {
                    turnstileToken.current = '';
                },
                'error-callback': () => {
                    turnstileToken.current = '';
                    setErrors((current) => ({
                        ...current,
                        'cf-turnstile-response': [
                            'Не удалось выполнить проверку безопасности.',
                        ],
                    }));
                },
            },
        );

        return () => {
            if (
                turnstileWidgetId.current !== null &&
                window.turnstile
            ) {
                window.turnstile.remove(turnstileWidgetId.current);
            }
        };
    }, [turnstileSiteKey]);

    async function loadCities() {
        setIsLoadingCities(true);

        try {
            const response = await fetch('/api/cities', {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to load cities');
            }

            const data = await response.json();

            setCities(data);
        } catch {
            setErrors({
                form: [
                    'Не удалось загрузить список городов. Попробуйте обновить страницу.',
                ],
            });
        } finally {
            setIsLoadingCities(false);
        }
    }

    async function loadLocationData(cityId) {
        if (!cityId) {
            setDistricts([]);
            setMetroStations([]);
            return;
        }

        setIsLoadingLocationData(true);

        try {
            const [districtsResponse, metroStationsResponse] =
                await Promise.all([
                    fetch(`/api/cities/${cityId}/districts`, {
                        headers: {
                            Accept: 'application/json',
                        },
                    }),
                    fetch(`/api/cities/${cityId}/metro-stations`, {
                        headers: {
                            Accept: 'application/json',
                        },
                    }),
                ]);

            if (!districtsResponse.ok || !metroStationsResponse.ok) {
                throw new Error('Failed to load location data');
            }

            const [districtsData, metroStationsData] = await Promise.all([
                districtsResponse.json(),
                metroStationsResponse.json(),
            ]);

            setDistricts(districtsData);
            setMetroStations(metroStationsData);
        } catch {
            setDistricts([]);
            setMetroStations([]);

            setErrors((current) => ({
                ...current,
                form: [
                    'Не удалось загрузить районы и станции метро.',
                ],
            }));
        } finally {
            setIsLoadingLocationData(false);
        }
    }

    function handleChange(event) {
        const {name, value} = event.target;

        setForm((current) => ({
            ...current,
            [name]: value,
        }));

        setErrors((current) => ({
            ...current,
            [name]: undefined,
            form: undefined,
        }));

        if (name === 'city_id') {
            setForm((current) => ({
                ...current,
                city_id: value,
                district_id: '',
                metro_station_id: '',
            }));

            loadLocationData(value);
        }
    }

    function handleChildChange(index, event) {
        const {name, value} = event.target;

        setForm((current) => ({
            ...current,
            children: current.children.map((child, childIndex) =>
                childIndex === index
                    ? {
                        ...child,
                        [name]: value,
                    }
                    : child,
            ),
        }));

        setErrors((current) => ({
            ...current,
            [`children.${index}.${name}`]: undefined,
            children: undefined,
            form: undefined,
        }));
    }

    function addChild() {
        setForm((current) => ({
            ...current,
            children: [
                ...current.children,
                {
                    name: '',
                    birth_date: '',
                    sex: '',
                },
            ],
        }));
    }

    function removeChild(index) {
        if (form.children.length === 1) {
            return;
        }

        setForm((current) => ({
            ...current,
            children: current.children.filter(
                (_, childIndex) => childIndex !== index,
            ),
        }));
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function getError(field) {
        const error = errors[field];

        if (!error) {
            return null;
        }

        return Array.isArray(error) ? error[0] : error;
    }

    async function handleSubmit(event) {
        event.preventDefault();

        setErrors({});

        const trimmedName = form.name.trim();
        const trimmedEmail = form.email.trim();
        const trimmedSurname = form.surname.trim();

        if (!trimmedName) {
            setErrors({
                name: ['Введите имя.'],
            });

            return;
        }

        if (!trimmedEmail) {
            setErrors({
                email: ['Введите email.'],
            });

            return;
        }

        if (!isValidEmail(trimmedEmail)) {
            setErrors({
                email: ['Введите корректный email.'],
            });

            return;
        }

        if (!form.password) {
            setErrors({
                password: ['Введите пароль.'],
            });

            return;
        }

        if (form.password !== form.password_confirmation) {
            setErrors({
                password_confirmation: ['Пароли не совпадают.'],
            });

            return;
        }

        if (!trimmedSurname) {
            setErrors({
                surname: ['Введите фамилию семьи.'],
            });

            return;
        }

        if (!form.city_id) {
            setErrors({
                city_id: ['Выберите город.'],
            });

            return;
        }

        if (!turnstileToken.current) {
            setErrors({
                'cf-turnstile-response': [
                    'Пройдите проверку безопасности.',
                ],
            });

            return;
        }

        for (let index = 0; index < form.children.length; index++) {
            const child = form.children[index];

            if (!child.name.trim()) {
                setErrors({
                    [`children.${index}.name`]: ['Введите имя ребёнка.'],
                });

                return;
            }

            if (!child.birth_date) {
                setErrors({
                    [`children.${index}.birth_date`]: [
                        'Укажите дату рождения ребёнка.',
                    ],
                });

                return;
            }

            if (!child.sex) {
                setErrors({
                    [`children.${index}.sex`]: [
                        'Укажите пол ребёнка.',
                    ],
                });

                return;
            }
        }

        setIsSubmitting(true);

        try {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const body = new URLSearchParams();

            body.append('name', trimmedName);
            body.append('email', trimmedEmail);
            body.append('password', form.password);
            body.append(
                'password_confirmation',
                form.password_confirmation,
            );
            body.append('surname', trimmedSurname);
            body.append('city_id', form.city_id);

            if (form.district_id) {
                body.append('district_id', form.district_id);
            }

            if (form.metro_station_id) {
                body.append(
                    'metro_station_id',
                    form.metro_station_id,
                );
            }

            form.children.forEach((child, index) => {
                body.append(
                    `children[${index}][name]`,
                    child.name.trim(),
                );
                body.append(
                    `children[${index}][birth_date]`,
                    child.birth_date,
                );
                body.append(
                    `children[${index}][sex]`,
                    child.sex,
                );
            });

            body.append(
                'cf-turnstile-response',
                turnstileToken.current,
            );

            const response = await fetch('/register', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body,
            });

            if (response.ok) {
                window.location.href = '/';
                return;
            }

            if (response.status === 422) {
                const data = await response.json();

                setErrors(data.errors ?? {});

                if (window.turnstile && turnstileWidgetId.current !== null) {
                    window.turnstile.reset(turnstileWidgetId.current);
                }

                turnstileToken.current = '';

                return;
            }

            setErrors({
                form: [
                    'Не удалось зарегистрироваться. Попробуйте ещё раз.',
                ],
            });
        } catch {
            setErrors({
                form: [
                    'Не удалось соединиться с сервером.',
                ],
            });
        } finally {
            setIsSubmitting(false);
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
            'placeholder:text-gray-400',
            'disabled:cursor-not-allowed',
            'disabled:bg-gray-50',
            getError(field)
                ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100'
                : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100',
        ].join(' ');
    }

    function selectClass(field) {
        return fieldClass(field);
    }

    return (
        <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
            <div className="mx-auto w-full max-w-md">
                <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                    <div className="mb-8 text-center">
                        <h1 className="text-2xl font-semibold tracking-tight text-gray-900">
                            Создание аккаунта
                        </h1>

                        <p className="mt-2 text-sm leading-6 text-gray-500">
                            Создайте профиль семьи и добавьте ребёнка
                        </p>
                    </div>

                    {getError('form') && (
                        <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700">
                            {getError('form')}
                        </div>
                    )}

                    <form
                        onSubmit={handleSubmit}
                        noValidate
                        className="space-y-8"
                    >
                        <section>
                            <h2 className="text-base font-semibold text-gray-900">
                                Аккаунт
                            </h2>

                            <div className="mt-4 space-y-5">
                                <div>
                                    <label
                                        htmlFor="name"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Имя
                                    </label>

                                    <input
                                        id="name"
                                        name="name"
                                        type="text"
                                        value={form.name}
                                        onChange={handleChange}
                                        autoComplete="name"
                                        disabled={isSubmitting}
                                        className={fieldClass('name')}
                                    />

                                    {getError('name') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('name')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label
                                        htmlFor="email"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Email
                                    </label>

                                    <input
                                        id="email"
                                        name="email"
                                        type="email"
                                        value={form.email}
                                        onChange={handleChange}
                                        autoComplete="email"
                                        disabled={isSubmitting}
                                        className={fieldClass('email')}
                                    />

                                    {getError('email') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('email')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label
                                        htmlFor="password"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Пароль
                                    </label>

                                    <input
                                        id="password"
                                        name="password"
                                        type="password"
                                        value={form.password}
                                        onChange={handleChange}
                                        autoComplete="new-password"
                                        disabled={isSubmitting}
                                        className={fieldClass('password')}
                                    />

                                    {getError('password') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('password')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label
                                        htmlFor="password_confirmation"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Повторите пароль
                                    </label>

                                    <input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        value={form.password_confirmation}
                                        onChange={handleChange}
                                        autoComplete="new-password"
                                        disabled={isSubmitting}
                                        className={fieldClass(
                                            'password_confirmation',
                                        )}
                                    />

                                    {getError('password_confirmation') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('password_confirmation')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </section>

                        <section>
                            <h2 className="text-base font-semibold text-gray-900">
                                Семья
                            </h2>

                            <div className="mt-4 space-y-5">
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
                                        autoComplete="family-name"
                                        disabled={isSubmitting}
                                        className={fieldClass('surname')}
                                    />

                                    {getError('surname') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('surname')}
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
                                        disabled={
                                            isSubmitting ||
                                            isLoadingCities
                                        }
                                        className={selectClass('city_id')}
                                    >
                                        <option value="">
                                            {isLoadingCities
                                                ? 'Загрузка городов...'
                                                : 'Выберите город'}
                                        </option>

                                        {cities.map((city) => (
                                            <option
                                                key={city.id}
                                                value={city.id}
                                            >
                                                {city.name}
                                            </option>
                                        ))}
                                    </select>

                                    {getError('city_id') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('city_id')}
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
                                            isLoadingLocationData
                                        }
                                        className={selectClass(
                                            'district_id',
                                        )}
                                    >
                                        <option value="">
                                            Не указывать
                                        </option>

                                        {districts.map((district) => (
                                            <option
                                                key={district.id}
                                                value={district.id}
                                            >
                                                {district.name}
                                            </option>
                                        ))}
                                    </select>

                                    {getError('district_id') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('district_id')}
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
                                            isLoadingLocationData
                                        }
                                        className={selectClass(
                                            'metro_station_id',
                                        )}
                                    >
                                        <option value="">
                                            Не указывать
                                        </option>

                                        {metroStations.map((station) => (
                                            <option
                                                key={station.id}
                                                value={station.id}
                                            >
                                                {station.name}
                                            </option>
                                        ))}
                                    </select>

                                    {getError('metro_station_id') && (
                                        <p className="mt-1.5 text-sm text-red-600">
                                            {getError('metro_station_id')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </section>

                        <section>
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-base font-semibold text-gray-900">
                                        Дети
                                    </h2>

                                    <p className="mt-1 text-sm text-gray-500">
                                        Добавьте хотя бы одного ребёнка
                                    </p>
                                </div>
                            </div>

                            <div className="mt-4 space-y-5">
                                {form.children.map((child, index) => (
                                    <div
                                        key={index}
                                        className="rounded-xl border border-gray-200 bg-gray-50 p-4"
                                    >
                                        <div className="mb-4 flex items-center justify-between">
                                            <span className="text-sm font-medium text-gray-700">
                                                Ребёнок {index + 1}
                                            </span>

                                            {form.children.length > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        removeChild(index)
                                                    }
                                                    disabled={isSubmitting}
                                                    className="text-sm text-gray-500 hover:text-red-600"
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
                                                    name="name"
                                                    type="text"
                                                    value={child.name}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            event,
                                                        )
                                                    }
                                                    disabled={isSubmitting}
                                                    className={fieldClass(
                                                        `children.${index}.name`,
                                                    )}
                                                />

                                                {getError(
                                                    `children.${index}.name`,
                                                ) && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {getError(
                                                            `children.${index}.name`,
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
                                                    name="birth_date"
                                                    type="date"
                                                    value={child.birth_date}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            event,
                                                        )
                                                    }
                                                    disabled={isSubmitting}
                                                    className={fieldClass(
                                                        `children.${index}.birth_date`,
                                                    )}
                                                />

                                                {getError(
                                                    `children.${index}.birth_date`,
                                                ) && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {getError(
                                                            `children.${index}.birth_date`,
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
                                                    name="sex"
                                                    value={child.sex}
                                                    onChange={(event) =>
                                                        handleChildChange(
                                                            index,
                                                            event,
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

                                                {getError(
                                                    `children.${index}.sex`,
                                                ) && (
                                                    <p className="mt-1.5 text-sm text-red-600">
                                                        {getError(
                                                            `children.${index}.sex`,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                <button
                                    type="button"
                                    onClick={addChild}
                                    disabled={isSubmitting}
                                    className="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    + Добавить ребёнка
                                </button>
                            </div>
                        </section>

                        <section>
                            <div
                                ref={turnstileRef}
                                className="flex justify-center"
                            />

                            {getError('cf-turnstile-response') && (
                                <p className="mt-2 text-center text-sm text-red-600">
                                    {getError('cf-turnstile-response')}
                                </p>
                            )}
                        </section>

                        <button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                isLoadingCities ||
                                isLoadingLocationData
                            }
                            className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isSubmitting
                                ? 'Регистрация...'
                                : 'Зарегистрироваться'}
                        </button>
                    </form>

                    <div className="mt-6 border-t border-gray-100 pt-6 text-center text-sm text-gray-500">
                        Уже есть аккаунт?{' '}
                        <a
                            href="/login"
                            className="font-medium text-gray-900 hover:underline"
                        >
                            Войти
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
