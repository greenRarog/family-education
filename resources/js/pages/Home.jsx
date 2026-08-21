export default function Home() {
    return (
        <>
            <section className="mx-auto max-w-3xl px-6 pb-24 pt-24">
                <div className="text-center">
                    <h1 className="text-4xl font-semibold tracking-tight text-gray-950 sm:text-5xl">
                        Образование детей —
                        <br/>
                        вместе с другими родителями
                    </h1>

                    <p className="mx-auto mt-6 max-w-2xl text-lg leading-8 text-gray-600">
                        Находите родителей со схожими взглядами на
                        образование, объединяйтесь и создавайте
                        образовательную среду для своих детей.
                    </p>
                </div>
            </section>

            <section
                id="about"
                className="border-y border-gray-200 bg-white"
            >
                <div className="mx-auto max-w-3xl px-6 py-16">
                    <h2 className="text-2xl font-semibold tracking-tight text-gray-950">
                        О проекте
                    </h2>

                    <div className="mt-6 space-y-5 text-base leading-7 text-gray-600">
                        <p>
                            Семейное образование позволяет родителям
                            самостоятельно определять образовательный путь
                            ребёнка, сохраняя возможность проходить
                            государственную аттестацию.
                        </p>

                        <p>
                            При этом самостоятельное образование не
                            обязательно означает образование в одиночку.
                        </p>

                        <p>
                            Родители могут объединяться в небольшие
                            сообщества, находить педагогов, договариваться
                            о формате занятий и вместе создавать
                            образовательную среду.
                        </p>

                        <p>
                            <span className="font-medium text-gray-900">
                                Family Education
                            </span>{' '}
                            создаётся как пространство для поиска таких
                            людей и построения между ними устойчивых связей.
                        </p>
                    </div>
                </div>
            </section>
        </>
    );
}
