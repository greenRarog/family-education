export default function MessageList({messages = [], currentUserId}) {
    if (messages.length === 0) {
        return (
            <div className="py-8 text-center text-sm text-gray-500">
                Сообщений пока нет.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {messages.map((message) => {
                const isOwn = message.user?.id === currentUserId;
                const isRead = Boolean(message.read_at);

                return (
                    <div
                        key={message.id}
                        className={`flex ${
                            isOwn ? 'justify-end' : 'justify-start'
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
                                    {message.user?.name}
                                </div>
                            )}

                            <div className="whitespace-pre-wrap text-sm leading-6">
                                {message.message}
                            </div>

                            <div
                                className={`mt-1 flex items-center justify-end gap-1 text-[11px] ${
                                    isOwn
                                        ? 'text-blue-100'
                                        : 'text-gray-400'
                                }`}
                            >
                                <span>
                                    {new Date(
                                        message.created_at
                                    ).toLocaleTimeString('ru-RU', {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </span>

                                {isOwn && (
                                    <span
                                        title={
                                            isRead
                                                ? 'Прочитано'
                                                : 'Не прочитано'
                                        }
                                        className="text-xs"
                                    >
                                        {isRead ? '✓✓' : '✓'}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
