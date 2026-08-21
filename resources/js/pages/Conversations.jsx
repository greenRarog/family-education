import {useEffect, useState} from 'react';

const types = {
    family_to_family: 'Ищу участников в учебную группу',
    family_to_teacher: 'Ищу педагога',
    teacher_to_service: 'Предлагаю услуги',
};

const responseStatuses = {
    sent: 'Отправлен',
    accepted: 'Принят',
    rejected: 'Отклонён',
};

export default function Conversations() {
    const [conversations, setConversations] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        async function loadConversations() {
            try {
                const response = await fetch('/api/conversations', {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error();
                }

                const data = await response.json();

                setConversations(data.conversations ?? []);
            } catch {
                setError('Не удалось загрузить диалоги.');
            } finally {
                setIsLoading(false);
            }
        }

        loadConversations();
    }, []);

    if (isLoading) {
        return (
            <main className="mx-auto w-full max-w-2xl px-4 py-8">
                <h1 className="text-2xl font-semibold tracking-tight text-gray-950">
                    Личные сообщения
                </h1>

                <p className="mt-8 text-sm text-gray-500">
                    Загрузка диалогов...
                </p>
            </main>
        );
    }

    return (
        <main className="mx-auto w-full max-w-2xl px-4 py-8">
            <h1 className="text-2xl font-semibold tracking-tight text-gray-950">
                Личные сообщения
            </h1>

            {error && (
                <div className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            {!error && conversations.length === 0 && (
                <div className="mt-8 rounded-2xl border border-gray-200 bg-white p-8">
                    <h2 className="text-base font-medium text-gray-900">
                        Пока нет диалогов
                    </h2>

                    <p className="mt-2 text-sm leading-6 text-gray-600">
                        Здесь появятся диалоги после отклика на объявление.
                    </p>
                </div>
            )}

            {conversations.length > 0 && (
                <div className="mt-8 space-y-3">
                    {conversations.map((conversation) => {
                        const hasUnread =
                            conversation.has_unread_messages === true;

                        const isAdvertisementOwner =
                            conversation.viewer?.is_advertisement_owner === true;

                        return (
                            <a
                                key={conversation.id}
                                href={`/conversations/${conversation.id}`}
                                className={`block rounded-2xl border bg-white p-5 transition hover:bg-gray-50 ${
                                    hasUnread
                                        ? 'border-blue-200'
                                        : 'border-gray-200'
                                }`}
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            {hasUnread && (
                                                <span
                                                    className="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-500"
                                                    title="Есть непрочитанные сообщения"
                                                />
                                            )}

                                            <h2 className="truncate text-base font-medium text-gray-900">
                                                {types[
                                                    conversation.advertisement?.type
                                                    ] ?? 'Объявление'}
                                            </h2>
                                        </div>

                                        {conversation.advertisement?.description && (
                                            <p className="mt-2 line-clamp-2 text-sm leading-5 text-gray-600">
                                                {conversation.advertisement.description}
                                            </p>
                                        )}
                                    </div>

                                    <span className="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">
                                        {responseStatuses[
                                            conversation.response?.status
                                            ] ?? conversation.response?.status}
                                    </span>
                                </div>

                                <div className="mt-4 flex items-center justify-between text-xs text-gray-500">
                                    <span>
                                        {isAdvertisementOwner
                                            ? `Отклик от ${
                                                conversation.response?.user?.name ??
                                                ''
                                            }`
                                            : 'Ваш отклик'}
                                    </span>

                                    {conversation.updated_at && (
                                        <span>
                                            {new Date(
                                                conversation.updated_at
                                            ).toLocaleDateString('ru-RU')}
                                        </span>
                                    )}
                                </div>
                            </a>
                        );
                    })}
                </div>
            )}
        </main>
    );
}
