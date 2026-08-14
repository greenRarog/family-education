export default function Register() {
    async function handleSubmit(event) {
        event.preventDefault();

        const form = new FormData(event.currentTarget);

        const token = document
            .querySelector('meta[name="csrf-token"]')
            .getAttribute('content');

        const response = await fetch('/register', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: form,
        });

        if (response.ok) {
            window.location.href = '/';
            return;
        }

        console.error(
            'Registration failed:',
            await response.text()
        );
    }

    return (
        <div>
            <h1>Регистрация</h1>

            <form onSubmit={handleSubmit}>
                <div>
                    <label>
                        Имя
                        <input
                            type="text"
                            name="name"
                            required
                        />
                    </label>
                </div>

                <div>
                    <label>
                        Email
                        <input
                            type="email"
                            name="email"
                            required
                        />
                    </label>
                </div>

                <div>
                    <label>
                        Пароль
                        <input
                            type="password"
                            name="password"
                            required
                        />
                    </label>
                </div>

                <div>
                    <label>
                        Повторите пароль
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                        />
                    </label>
                </div>

                <button type="submit">
                    Зарегистрироваться
                </button>
            </form>
        </div>
    );
}
