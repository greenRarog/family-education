export default function AdminLayout({children}) {
    const navigation = [
        {
            title: 'Основное',
            items: [
                {
                    label: 'Dashboard',
                    to: '/admin',
                },
                {
                    label: 'Пользователи',
                    to: '/admin/users',
                },
                {
                    label: 'Объявления',
                    to: '/admin/advertisements',
                },
                {
                    label: 'Жалобы',
                    to: '/admin/reports',
                },
            ],
        },
        {
            title: 'Справочники',
            items: [
                {
                    label: 'Города',
                    to: '/admin/cities',
                },
                {
                    label: 'Предметы',
                    to: '/admin/subjects',
                },
                {
                    label: 'Бан-слова',
                    to: '/admin/blocked-terms',
                },
            ],
        },
    ];

    return (
        <div className="min-h-screen bg-gray-100 text-gray-900">
            <header className="fixed inset-x-0 top-0 z-30 h-14 border-b border-gray-200 bg-white">
                <div className="flex h-full items-center justify-between px-5">
                    <a
                        href="/admin"
                        className="text-sm font-semibold tracking-tight text-gray-900"
                    >
                        Family Education
                    </a>

                    <div className="flex items-center gap-4">
                        <span className="text-sm text-gray-600">
                            Иван
                        </span>

                        <form method="POST" action="/logout">
                            <input
                                type="hidden"
                                name="_token"
                                value={
                                    document
                                        .querySelector('meta[name="csrf-token"]')
                                        ?.getAttribute('content') ?? ''
                                }
                            />

                            <button
                                type="submit"
                                className="text-sm text-gray-500 transition hover:text-gray-900"
                            >
                                Выйти
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <aside className="fixed bottom-0 left-0 top-14 z-20 w-60 overflow-y-auto border-r border-gray-200 bg-white">
                <nav className="p-3">
                    {navigation.map((section) => (
                        <div key={section.title} className="mb-6">
                            <div className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
                                {section.title}
                            </div>

                            <div className="space-y-0.5">
                                {section.items.map((item) => {
                                    const isActive =
                                        window.location.pathname === item.to;

                                    return (
                                        <a
                                            key={item.to}
                                            href={item.to}
                                            className={[
                                                'block rounded-md px-3 py-2',
                                                'text-sm transition',
                                                isActive
                                                    ? 'bg-gray-900 font-medium text-white'
                                                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                                            ].join(' ')}
                                        >
                                            {item.label}
                                        </a>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>
            </aside>

            <main className="ml-60 pt-14">
                <div className="p-6">
                    {children}
                </div>
            </main>
        </div>
    );
}
