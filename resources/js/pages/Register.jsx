import {useState} from 'react';

export default function Register() {
    const [form, setForm] = useState({name: '', email: '', password: '', password_confirmation: '',});
    const [errors, setErrors] = useState({});
    const [isSubmitting, setIsSubmitting] = useState(false);

    function handleChange(event) {
        const {name, value} = event.target;
        setForm((current) => ({...current, [name]: value,}));
        setErrors((current) => ({...current, [name]: undefined, form: undefined,}));
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setErrors({});
        const trimmedName = form.name.trim();
        const trimmedEmail = form.email.trim();
        if (!trimmedName) {
            setErrors({name: ['Введите имя.'],});
            return;
        }
        if (!trimmedEmail) {
            setErrors({email: ['Введите email.'],});
            return;
        }
        if (!isValidEmail(trimmedEmail)) {
            setErrors({email: ['Введите корректный email.'],});
            return;
        }
        if (!form.password) {
            setErrors({password: ['Введите пароль.'],});
            return;
        }
        if (form.password !== form.password_confirmation) {
            setErrors({password_confirmation: ['Пароли не совпадают.'],});
            return;
        }
        setIsSubmitting(true);
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/register', {
                method: 'POST',
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token,},
                body: new URLSearchParams({...form, name: trimmedName, email: trimmedEmail,}),
            });
            if (response.ok) {
                window.location.href = '/';
                return;
            }
            if (response.status === 422) {
                const data = await response.json();
                setErrors(data.errors ?? {});
                return;
            }
            setErrors({form: ['Не удалось зарегистрироваться. Попробуйте ещё раз.'],});
        } catch {
            setErrors({form: ['Не удалось соединиться с сервером.'],});
        } finally {
            setIsSubmitting(false);
        }
    }

    function fieldClass(field) {
        return ['block', 'w-full', 'rounded-xl', 'border', 'bg-white', 'px-3.5', 'py-3', 'text-sm', 'text-gray-900', 'outline-none', 'transition', 'placeholder:text-gray-400', 'disabled:cursor-not-allowed', 'disabled:bg-gray-50', errors[field] ? 'border-red-400 focus:border-red-500 focus:ring-2 focus:ring-red-100' : 'border-gray-200 focus:border-gray-400 focus:ring-2 focus:ring-gray-100',].join(' ');
    }

    return (<div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-gray-900">
        <div className="mx-auto w-full max-w-md">
            <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm">
                <div className="mb-8 text-center"><h1
                    className="text-2xl font-semibold tracking-tight text-gray-900"> Создание аккаунта </h1> <p
                    className="mt-2 text-sm leading-6 text-gray-500"> Зарегистрируйтесь, чтобы продолжить </p></div>
                {errors.form && (<div
                    className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-700"> {errors.form[0]} </div>)}
                <form onSubmit={handleSubmit} noValidate className="space-y-5">
                    <div><label htmlFor="name" className="mb-2 block text-sm font-medium text-gray-700"> Имя </label>
                        <input id="name" name="name" type="text" value={form.name} onChange={handleChange}
                               autoComplete="name" disabled={isSubmitting}
                               className={fieldClass('name')}/> {errors.name && (
                            <p className="mt-1.5 text-sm text-red-600"> {errors.name[0]} </p>)} </div>
                    <div><label htmlFor="email" className="mb-2 block text-sm font-medium text-gray-700"> Email </label>
                        <input id="email" name="email" type="email" value={form.email} onChange={handleChange}
                               autoComplete="email" disabled={isSubmitting}
                               className={fieldClass('email')}/> {errors.email && (
                            <p className="mt-1.5 text-sm text-red-600"> {errors.email[0]} </p>)} </div>
                    <div><label htmlFor="password"
                                className="mb-2 block text-sm font-medium text-gray-700"> Пароль </label> <input
                        id="password" name="password" type="password" value={form.password} onChange={handleChange}
                        autoComplete="new-password" disabled={isSubmitting}
                        className={fieldClass('password')}/> {errors.password && (
                        <p className="mt-1.5 text-sm text-red-600"> {errors.password[0]} </p>)} </div>
                    <div><label htmlFor="password_confirmation"
                                className="mb-2 block text-sm font-medium text-gray-700"> Повторите пароль </label>
                        <input id="password_confirmation" name="password_confirmation" type="password"
                               value={form.password_confirmation} onChange={handleChange} autoComplete="new-password"
                               disabled={isSubmitting}
                               className={fieldClass('password_confirmation')}/> {errors.password_confirmation && (
                            <p className="mt-1.5 text-sm text-red-600"> {errors.password_confirmation[0]} </p>)} </div>
                    <button type="submit" disabled={isSubmitting}
                            className="w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"> {isSubmitting ? 'Регистрация...' : 'Зарегистрироваться'} </button>
                </form>
                <div className="mt-6 border-t border-gray-100 pt-6 text-center text-sm text-gray-500"> Уже есть
                    аккаунт?{' '} <a href="/login" className="font-medium text-gray-900 hover:underline"> Войти </a>
                </div>
            </div>
        </div>
    </div>);
}
