import {useEffect, useState} from 'react';

const types = {
    family_to_family: 'Ищу участников в учебную группу',
    family_to_teacher: 'Ищу педагога',
    teacher_to_service: 'Предлагаю услуги',
};

const responseStatuses = {
    sent: 'Ожидает принятия',
    accepted: 'Принят',
    rejected: 'Отклонён',
};

export default function ConversationShow({conversationId}) {
    const [conversation, setConversation] = useState(null);
    const [message, setMessage] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [isSending, setIsSending] = useState(false);
    const [isChangingStatus, setIsChangingStatus] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        loadConversation();
    }, [conversationId]);

    async function loadConversation() {
        setIsLoading(true);
        setError('');

        try {
            const response = await fetch(
                `/api/conversations/${conversationId}`,
                {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                }
            );

            if (!response.ok) {
                throw new Error();
            }

            const data = await response.json();

            setConversation(data.conversation);

            // После открытия диалога отмечаем входящие сообщения прочитанными.
            await markAsRead();

            // Сразу обновляем состояние на фронте.
            setConversation((current) => {
                if (!current) {
                    return current;
                }

                return {
                    ...current,
                    has_unread_messages: false,
                    messages: (current.messages ?? []).map((item) => {
                        if (item.user?.id === current.viewer?.id) {
                            return item;
                        }

                        if (!item.read_at) {
                            return {
                                ...item,
                                read_at: new Date().toISOString(),
                            };
                        }

                        return item;
                    }),
                };
            });
        } catch {
            setError('Не удалось загрузить диалог.');
        } finally {
            setIsLoading(false);
        }
    }

    async function markAsRead() {
        try {
            await fetch(`/api/conversations/${conversationId}/read`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            });
        } catch {
            // Ошибка read не должна мешать просмотру диалога.
        }
    }

    async function sendMessage(event) {
        event.preventDefault();

        const text = message.trim();

        if (!text || isSending || !conversation) {
            return;
        }

        const isOwner =
            conversation.viewer?.is_advertisement_owner === true;

        const isAccepted =
            conversation.response?.status === 'accepted';

        // Автор объявления может писать всегда.
        // Автор отклика — только после принятия отклика.
        if (!isOwner && !isAccepted) {
            return;
        }

        setIsSending(true);
        setError('');

        try {
            const response = await fetch(
                `/api/conversations/${conversationId}/messages`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        message: text,
                    }),
                }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || 'Не удалось отправить сообщение.'
                );
            }

            setConversation((current) => ({
                ...current,
                messages: [
                    ...(current.messages ?? []),
                    data.message,
                ],
                has_unread_messages: false,
                updated_at: data.message.created_at,
            }));

            setMessage('');
        } catch (error) {
            setError(
                error.message || 'Не удалось отправить сообщение.'
            );
        } finally {
            setIsSending(false);
        }
    }

    async function changeResponseStatus(status) {
        if (
            isChangingStatus ||
            !conversation ||
            !conversation.viewer?.is_advertisement_owner
        ) {
            return;
        }

        if (conversation.response?.status !== 'sent') {
            return;
        }

        const endpoint =
            status === 'accepted'
                ? `/api/advertisement-responses/${conversation.response.id}/accept`
                : `/api/advertisement-responses/${conversation.response.id}/reject`;

        setIsChangingStatus(true);
        setError('');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || 'Не удалось изменить статус отклика.'
                );
            }

            setConversation((current) => ({
                ...current,
                response: {
                    ...current.response,
                    status: data.response.status,
                },
            }));
        } catch (error) {
            setError(
                error.message ||
                'Не удалось изменить статус отклика.'
            );
        } finally {
            setIsChangingStatus(false);
        }
    }

    if (isLoading) {
        return (
            <main className="mx-auto w-full max-w-2xl px-4 py-8">
                <p className="text-sm text-gray-500">
                    Загрузка диалога...
                </p>
            </main>
        );
    }

    if (!conversation) {
        return (
            <main className="mx-auto w-full max-w-2xl px-4 py-8">
                <a
                    href="/conversations"
                    className="text-sm text-gray-500 transition hover:text-gray-900"
                >
                    ← К сообщениям
                </a>

                <div className="mt-8 rounded-2xl border border-red-100 bg-red-50 p-6">
                    <p className="text-sm text-red-700">
                        {error || 'Диалог не найден.'}
                    </p>
                </div>
            </main>
        );
    }

    const isAdvertisementOwner =
        conversation.viewer?.is_advertisement_owner === true;

    const isResponseAuthor =
        conversation.viewer?.is_response_author === true;

    const responseStatus =
        conversation.response?.status;

    const canSendMessages =
        isAdvertisementOwner ||
        (isResponseAuthor && responseStatus === 'accepted');

    const messages = conversation.messages ?? [];

    return (
        <main className="mx-auto w-full max-w-2xl px-4 py-8">
            <a
                href="/conversations"
                className="text-sm text-gray-500 transition hover:text-gray-900"
            >
                ← К сообщениям
            </a>

            <div className="mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white">
                {/* Заголовок */}
                <div className="border-b border-gray-100 p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <h1 className="text-lg font-semibold text-gray-950">
                                {types[
                                    conversation.advertisement?.type
                                    ] ?? 'Диалог'}
                            </h1>

                            <a
                                href={`/advertisements/${conversation.advertisement?.id}`}
                                className="mt-1 inline-block text-sm text-gray-500 transition hover:text-gray-900"
                            >
                                Перейти к объявлению →
                            </a>
                        </div>

                        <span className="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600">
                            {responseStatuses[responseStatus] ??
                                responseStatus}
                        </span>
                    </div>

                    {/* Управление откликом владельцем объявления */}
                    {isAdvertisementOwner &&
                        responseStatus === 'sent' && (
                            <div className="mt-5">
                                <div className="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3">
                                    <p className="text-sm leading-5 text-amber-800">
                                        Чтобы пользователь смог отправлять
                                        сообщения в этот чат, необходимо
                                        принять отклик.
                                    </p>
                                </div>

                                <div className="mt-3 flex gap-2">
                                    <button
                                        type="button"
                                        disabled={isChangingStatus}
                                        onClick={() =>
                                            changeResponseStatus(
                                                'accepted'
                                            )
                                        }
                                        className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {isChangingStatus
                                            ? 'Сохранение...'
                                            : 'Принять отклик'}
                                    </button>

                                    <button
                                        type="button"
                                        disabled={isChangingStatus}
                                        onClick={() =>
                                            changeResponseStatus(
                                                'rejected'
                                            )
                                        }
                                        className="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Отклонить отклик
                                    </button>
                                </div>
                            </div>
                        )}

                    {/* Принятый отклик */}
                    {isAdvertisementOwner &&
                        responseStatus === 'accepted' && (
                            <div className="mt-5">
                                <div className="rounded-xl border border-green-100 bg-green-50 px-4 py-3">
                                    <p className="text-sm leading-5 text-green-800">
                                        Отклик принят. Пользователь может
                                        отправлять сообщения в этот чат.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    disabled={isChangingStatus}
                                    onClick={() =>
                                        changeResponseStatus(
                                            'rejected'
                                        )
                                    }
                                    className="mt-3 rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {isChangingStatus
                                        ? 'Отзыв...'
                                        : 'Отозвать отклик'}
                                </button>
                            </div>
                        )}

                    {/* Отклонённый отклик */}
                    {responseStatus === 'rejected' && (
                        <div className="mt-5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                            <p className="text-sm leading-5 text-gray-600">
                                Этот отклик отклонён. Отправка новых
                                сообщений недоступна.
                            </p>
                        </div>
                    )}
                </div>

                {/* Ошибка */}
                {error && (
                    <div className="border-b border-red-100 bg-red-50 px-5 py-3">
                        <p className="text-sm text-red-700">
                            {error}
                        </p>
                    </div>
                )}

                {/* Сообщения */}
                <div className="max-h-[60vh] overflow-y-auto p-5">
                    {messages.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            Сообщений пока нет.
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {messages.map((item) => {
                                const isOwn =
                                    item.user?.id ===
                                    conversation.viewer?.id;

                                const isRead =
                                    Boolean(item.read_at);

                                return (
                                    <div
                                        key={item.id}
                                        className={`flex ${
                                            isOwn
                                                ? 'justify-end'
                                                : 'justify-start'
                                        }`}
                                    >
                                        <div
                                            className={`max-w-[80%] rounded-2xl px-4 py-3 ${
                                                isOwn
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-gray-100 text-gray-900'
                                            }`}
                                        >
                                            {!isOwn && (
                                                <div className="mb-1 text-xs font-medium text-gray-500">
                                                    {item.user?.name}
                                                </div>
                                            )}

                                            <p className="whitespace-pre-wrap text-sm leading-6">
                                                {item.message}
                                            </p>

                                            <div
                                                className={`mt-1 flex items-center justify-end gap-1 text-xs ${
                                                    isOwn
                                                        ? 'text-blue-100'
                                                        : 'text-gray-400'
                                                }`}
                                            >
                                                <span>
                                                    {new Date(
                                                        item.created_at
                                                    ).toLocaleTimeString(
                                                        'ru-RU',
                                                        {
                                                            hour: '2-digit',
                                                            minute: '2-digit',
                                                        }
                                                    )}
                                                </span>

                                                {isOwn && (
                                                    <span
                                                        title={
                                                            isRead
                                                                ? 'Прочитано'
                                                                : 'Отправлено'
                                                        }
                                                    >
                                                        {isRead
                                                            ? '✓✓'
                                                            : '✓'}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Форма отправки */}
                {canSendMessages ? (
                    <form
                        onSubmit={sendMessage}
                        className="border-t border-gray-100 p-4"
                    >
                        <div className="flex gap-2">
                            <textarea
                                value={message}
                                onChange={(event) =>
                                    setMessage(event.target.value)
                                }
                                rows={2}
                                placeholder="Введите сообщение..."
                                className="min-w-0 flex-1 resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            />

                            <button
                                type="submit"
                                disabled={
                                    !message.trim() ||
                                    isSending
                                }
                                className="self-end rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {isSending
                                    ? 'Отправка...'
                                    : 'Отправить'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <div className="border-t border-gray-100 bg-gray-50 p-4">
                        {isResponseAuthor &&
                            responseStatus === 'sent' && (
                                <div className="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3">
                                    <p className="text-sm leading-5 text-amber-800">
                                        Ваш отклик ещё не принят.
                                    </p>

                                    <p className="mt-1 text-xs leading-5 text-amber-700">
                                        Отправка сообщений станет доступна
                                        после того, как автор объявления
                                        примет ваш отклик.
                                    </p>
                                </div>
                            )}

                        {isResponseAuthor &&
                            responseStatus === 'rejected' && (
                                <div className="rounded-xl border border-gray-200 bg-white px-4 py-3">
                                    <p className="text-sm leading-5 text-gray-600">
                                        Автор объявления отклонил ваш
                                        отклик. Отправка сообщений
                                        недоступна.
                                    </p>
                                </div>
                            )}
                    </div>
                )}
            </div>
        </main>
    );
}

function getCsrfToken() {
    return document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
}
