import {useEffect, useState} from 'react';

export default function useSubjects() {
    const [subjects, setSubjects] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        fetch('/api/subjects', {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error();
                }

                return response.json();
            })
            .then((data) => {
                setSubjects(data);
            })
            .catch(() => {
                setError('Не удалось загрузить предметы.');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, []);

    return {
        subjects,
        isLoading,
        error,
    };
}
