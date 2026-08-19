import {useEffect, useMemo, useState} from 'react';

const advertisementType = 'family_to_family';
const emptyForm = {
    child_ids: [],
    participant_age_from: '',
    participant_age_to: '',
    city_id: '',
    district_id: '',
    metro_station_id: '',
    description: ''
};

export default function GroupAdvertisementForm({advertisementId = null}) {
    const [form, setForm] = useState(emptyForm);
    const [children, setChildren] = useState([]);
    const [locations, setLocations] = useState({cities: [], districts: [], metro_stations: []});
    const [status, setStatus] = useState('draft');
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [busy, setBusy] = useState('');

    useEffect(() => {
        async function load() {
            try {
                const requests = [
                    fetch('/api/family', {headers: {Accept: 'application/json'}, credentials: 'same-origin'}),
                    fetch('/api/locations', {headers: {Accept: 'application/json'}, credentials: 'same-origin'}),
                ];
                if (advertisementId) requests.push(fetch(`/api/advertisements/${advertisementId}/edit`, {
                    headers: {Accept: 'application/json'},
                    credentials: 'same-origin'
                }));
                const responses = await Promise.all(requests);
                if (responses.some((response) => !response.ok)) throw new Error();
                const family = await responses[0].json();
                setChildren(family.family?.children ?? []);
                setLocations(await responses[1].json());
                if (advertisementId) {
                    const {advertisement} = await responses[2].json();
                    if (advertisement.type !== 'family_to_family') throw new Error();
                    setStatus(advertisement.status);
                    setForm({
                        child_ids: (advertisement.children ?? []).map((child) => String(child.id)),
                        participant_age_from: String(advertisement.participant_age_from),
                        participant_age_to: String(advertisement.participant_age_to),
                        city_id: String(advertisement.city_id),
                        district_id: String(advertisement.district_id ?? ''),
                        metro_station_id: String(advertisement.metro_station_id ?? ''),
                        description: advertisement.description ?? ''
                    });
                }
            } catch {
                setMessage('Не удалось загрузить данные объявления.');
            } finally {
                setIsLoading(false);
            }
        }

        load();
    }, [advertisementId]);

    const districts = useMemo(() => (locations.districts ?? []).filter((item) => String(item.city_id) === form.city_id), [locations.districts, form.city_id]);
    const stations = useMemo(() => (locations.metro_stations ?? []).filter((item) => String(item.city_id) === form.city_id), [locations.metro_stations, form.city_id]);
    const closed = status === 'closed';
    const error = (name) => Array.isArray(errors[name]) ? errors[name][0] : errors[name];
    const inputClass = (name) => `block w-full rounded-xl border bg-white px-3.5 py-3 text-sm text-gray-900 outline-none transition disabled:cursor-not-allowed disabled:bg-gray-50 ${error(name) ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100' : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100'}`;

    function change(name, value) {
        setMessage('');
        setErrors((current) => ({...current, [name]: undefined}));
        setForm((current) => name === 'city_id' ? {
            ...current,
            city_id: value,
            district_id: '',
            metro_station_id: ''
        } : {...current, [name]: value});
    }

    function toggleChild(id) {
        const value = String(id);
        setMessage('');
        setErrors((current) => ({...current, child_ids: undefined}));
        setForm((current) => ({
            ...current,
            child_ids: current.child_ids.includes(value) ? current.child_ids.filter((item) => item !== value) : [...current.child_ids, value]
        }));
    }

    function payload() {
        return {
            ...form,
            type: advertisementType,
            child_ids: form.child_ids.map(Number),
            participant_age_from: Number(form.participant_age_from),
            participant_age_to: Number(form.participant_age_to),
            city_id: Number(form.city_id),
            district_id: form.district_id ? Number(form.district_id) : null,
            metro_station_id: form.metro_station_id ? Number(form.metro_station_id) : null,
            description: form.description.trim()
        };
    }

    async function send(url, method, body = null) {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(url, {
            method,
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token},
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : null
        });
        const data = await response.json();
        if (!response.ok) {
            const requestError = new Error(data.message ?? 'Не удалось сохранить объявление.');
            requestError.data = data;
            throw requestError;
        }
        return data;
    }

    function apply(advertisement) {
        setStatus(advertisement.status);
        setForm((current) => ({
            ...current,
            child_ids: (advertisement.children ?? []).map((child) => String(child.id))
        }));
    }

    async function submit(event) {
        event.preventDefault();
        setErrors({});
        setMessage('');
        setBusy('save');
        try {
            const data = await send(advertisementId ? `/api/advertisements/${advertisementId}` : '/api/advertisements', advertisementId ? 'PUT' : 'POST', payload());
            if (!advertisementId) {
                window.location.href = `/advertisements/${data.advertisement.id}/edit`;
                return;
            }
            apply(data.advertisement);
            setMessage('Изменения сохранены.');
        } catch (requestError) {
            requestError.data?.errors ? setErrors(requestError.data.errors) : setMessage(requestError.message);
        } finally {
            setBusy('');
        }
    }

    async function action(name) {
        setMessage('');
        setBusy(name);
        try {
            const data = await send(`/api/advertisements/${advertisementId}/${name}`, 'POST');
            apply(data.advertisement);
            setMessage(name === 'publish' ? 'Объявление опубликовано.' : 'Объявление закрыто.');
        } catch (requestError) {
            setMessage(requestError.message);
        } finally {
            setBusy('');
        }
    }

    if (isLoading) return <div
        className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-center text-sm text-gray-500">Загрузка объявления...</div>;
    return <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
        <main className="mx-auto w-full max-w-2xl">
            <a href="/my-advertisements" className="text-sm text-gray-500 transition hover:text-gray-900">← Мои
                объявления</a>
            <h1 className="mt-8 text-2xl font-semibold tracking-tight text-gray-950">Ищу участников в учебную
                группу</h1>
            <p className="mt-2 text-sm leading-6 text-gray-600">Расскажите, для каких детей ищете группу и где
                планируете заниматься.</p>
            {message &&
                <p className={`mt-6 rounded-lg px-4 py-3 text-sm ${message.includes('сохран') || message.includes('опублик') || message.includes('закрыто') ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message}</p>}
            <form onSubmit={submit} className="mt-6 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
                <fieldset disabled={closed} className="space-y-6">
                    <div><label className="text-sm font-medium text-gray-800">Дети, участвующие в группе</label><p
                        className="mt-1 text-sm text-gray-500">Выберите детей из профиля семьи.</p>
                        <div className="mt-3 space-y-2">{children.map((child) => <label key={child.id}
                                                                                        className="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-800 transition hover:bg-gray-50"><input
                            type="checkbox" checked={form.child_ids.includes(String(child.id))}
                            onChange={() => toggleChild(child.id)}
                            className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-400"/>{child.name}
                        </label>)}</div>
                        {error('child_ids') && <p className="mt-2 text-sm text-red-600">{error('child_ids')}</p>}</div>
                    <div
                        className="grid gap-4 sm:grid-cols-2">{[['participant_age_from', 'Возраст от'], ['participant_age_to', 'Возраст до']].map(([name, label]) =>
                        <div key={name}><label htmlFor={name}
                                               className="mb-2 block text-sm font-medium text-gray-800">{label}</label><input
                            id={name} type="number" min="0" max="18" value={form[name]}
                            onChange={(event) => change(name, event.target.value)}
                            className={inputClass(name)}/>{error(name) &&
                            <p className="mt-2 text-sm text-red-600">{error(name)}</p>}</div>)}</div>
                    <Select label="Город" name="city_id" value={form.city_id} onChange={change}
                            options={locations.cities ?? []} error={error('city_id')}
                            className={inputClass('city_id')}/>
                    <div className="grid gap-4 sm:grid-cols-2"><Select label="Район (необязательно)" name="district_id"
                                                                       value={form.district_id} onChange={change}
                                                                       options={districts} error={error('district_id')}
                                                                       className={inputClass('district_id')}
                                                                       disabled={!form.city_id}/><Select
                        label="Станция метро (необязательно)" name="metro_station_id" value={form.metro_station_id}
                        onChange={change} options={stations} error={error('metro_station_id')}
                        className={inputClass('metro_station_id')} disabled={!form.city_id}/></div>
                    <div><label htmlFor="description"
                                className="mb-2 block text-sm font-medium text-gray-800">Описание</label><textarea
                        id="description" rows="6" maxLength="5000" value={form.description}
                        onChange={(event) => change('description', event.target.value)}
                        placeholder="Например: ищем 3–5 семей для регулярных занятий…"
                        className={inputClass('description')}/>{error('description') &&
                        <p className="mt-2 text-sm text-red-600">{error('description')}</p>}</div>
                    {!closed && <button type="submit" disabled={busy !== ''}
                                        className="rounded-lg bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50">{busy === 'save' ? 'Сохранение...' : advertisementId ? 'Сохранить изменения' : 'Создать черновик'}</button>}
                </fieldset>
            </form>
            {advertisementId && !closed && <div className="mt-4 flex flex-wrap gap-3">{status === 'draft' &&
                <button type="button" disabled={busy !== ''} onClick={() => action('publish')}
                        className="rounded-lg bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-700 disabled:opacity-50">{busy === 'publish' ? 'Публикация...' : 'Опубликовать'}</button>}
                <button type="button" disabled={busy !== ''} onClick={() => action('close')}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50">{busy === 'close' ? 'Закрытие...' : 'Закрыть объявление'}</button>
            </div>}
        </main>
    </div>;
}

function Select({label, name, value, onChange, options, error, className, disabled = false}) {
    return <div><label htmlFor={name} className="mb-2 block text-sm font-medium text-gray-800">{label}</label><select
        id={name} value={value} onChange={(event) => onChange(name, event.target.value)} disabled={disabled}
        className={className}>
        <option value="">Не выбран</option>
        {options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select>{error &&
        <p className="mt-2 text-sm text-red-600">{error}</p>}</div>;
}
