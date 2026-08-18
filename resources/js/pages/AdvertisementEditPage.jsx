import {useEffect, useState} from 'react';

import FamilyTeacherAdvertisementForm from './FamilyTeacherAdvertisementForm.jsx';
import GroupAdvertisementForm from './GroupAdvertisementForm.jsx';

export default function AdvertisementEditPage({advertisementId}) {
    const [type, setType] = useState(null);

    useEffect(() => {
        fetch(`/api/advertisements/${advertisementId}/edit`, {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error();
                }

                return response.json();
            })
            .then((data) => {
                setType(data.advertisement.type);
            });
    }, [advertisementId]);

    if (!type) {
        return (
            <div className="min-h-screen bg-[#f7f7f8] px-4 py-12 text-center text-sm text-gray-500">
                Загрузка объявления...
            </div>
        );
    }

    if (type === 'family_to_teacher') {
        return (
            <FamilyTeacherAdvertisementForm
                advertisementId={advertisementId}
            />
        );
    }

    return (
        <GroupAdvertisementForm
            advertisementId={advertisementId}
        />
    );
}
