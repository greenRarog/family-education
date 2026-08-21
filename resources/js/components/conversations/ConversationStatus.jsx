import {useState} from 'react';

const statuses = {
    sent: 'Ожидает решения',
    accepted: 'Отклик принят',
    rejected: 'Отклик отклонён',
};

export default function ConversationStatus({
                                               conversation,
                                               onStatusChanged,
                                           }) {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');

    const status = conversation.response?.status;
    const isOwner = conversation.viewer?.is_advertisement_owner;

    const changeStatus = async (action) => {
        setIsLoading(true);
        setError('');

        try {
            const response = await fetch(
                `/api/advertisement-responses/${conversation.response.id}/${action}`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content'),
                    },
                    credentials: 'same-origin',
                }
            );

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || 'Не удалось изменить статус отклика.'
                );
            }

            onStatusChanged?.(data.response);
        } catch (error) {
            setError(error.message);
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="border-b border-gray-100 bg-white px-6 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="text-xs text-gray-500">
                        Статус отклика
                    </div>

                    <div className="mt-1 text-sm font-medium text-gray-900">
                        {statuses[status] ?? status}
                    </div>
                </div>

                {isOwner && status === 'sent' && (
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={isLoading}
                            onClick={() => changeStatus('accept')}
                            className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Принять
                        </button>

                        <button
                            type="button"
                            disabled={isLoading}
                            onClick={() => changeStatus('reject')}
                            className="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Отклонить
                        </button>
                    </div>
                )}
            </div>

            {error && (
                <div className="mt-3 text-sm text-red-600">
                    {error}
                </div>
            )}
        </div>
    );
}
