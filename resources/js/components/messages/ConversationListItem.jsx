export default function ConversationListItem({conversation}) {
    const advertisement = conversation.advertisement;
    const responseUser = conversation.response?.user;

    const title =
        advertisement?.type === 'family_to_family'
            ? 'Ищу участников в учебную группу'
            : advertisement?.type === 'family_to_teacher'
                ? 'Ищу педагога'
                : 'Объявление';

    return (
        <a
            href={`/conversations/${conversation.id}`}
            className="block rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-gray-300 hover:shadow-sm"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        {conversation.has_unread_messages && (
                            <span
                                className="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600"
                                title="Есть непрочитанные сообщения"
                            />
                        )}

                        <h2 className="truncate text-sm font-semibold text-gray-950">
                            {title}
                        </h2>
                    </div>

                    <div className="mt-2 text-xs text-gray-500">
                        {responseUser?.name}
                    </div>
                </div>

                <div className="shrink-0 text-xs text-gray-400">
                    {new Date(
                        conversation.updated_at
                    ).toLocaleDateString('ru-RU')}
                </div>
            </div>

            <div className="mt-3 line-clamp-2 text-sm text-gray-600">
                {advertisement?.description}
            </div>

            <div className="mt-4 flex items-center gap-2">
                <span
                    className={`rounded-full px-3 py-1 text-xs ${
                        conversation.response?.status === 'accepted'
                            ? 'bg-green-100 text-green-700'
                            : conversation.response?.status === 'rejected'
                                ? 'bg-red-100 text-red-700'
                                : 'bg-gray-100 text-gray-600'
                    }`}
                >
                    {conversation.response?.status === 'accepted'
                        ? 'Принят'
                        : conversation.response?.status === 'rejected'
                            ? 'Отклонён'
                            : 'Ожидает решения'}
                </span>
            </div>
        </a>
    );
}
