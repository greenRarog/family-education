import {useEffect, useState} from 'react';
import Header from './Header.jsx';

export default function Layout({children}) {
    const [user, setUser] = useState(null);
    const [isAuthenticated, setIsAuthenticated] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [unreadMessagesCount, setUnreadMessagesCount] = useState(0);

    useEffect(() => {
        async function loadUser() {
            try {
                const response = await fetch('/api/user', {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    setIsAuthenticated(false);
                    setUser(null);
                    setUnreadMessagesCount(0);
                    return;
                }

                const data = await response.json();

                const authenticated = data.authenticated === true;

                setIsAuthenticated(authenticated);
                setUser(data.user ?? null);

                if (authenticated) {
                    const unreadResponse = await fetch(
                        '/api/conversations/unread-count',
                        {
                            method: 'GET',
                            headers: {
                                Accept: 'application/json',
                            },
                            credentials: 'same-origin',
                        }
                    );

                    if (unreadResponse.ok) {
                        const unreadData = await unreadResponse.json();

                        setUnreadMessagesCount(unreadData.count ?? 0);
                    }
                }
            } catch {
                setIsAuthenticated(false);
                setUser(null);
                setUnreadMessagesCount(0);
            } finally {
                setIsLoading(false);
            }
        }

        loadUser();
    }, []);

    return (
        <div className="min-h-screen bg-[#f7f7f8] text-gray-900">
            <Header
                user={user}
                isAuthenticated={isAuthenticated}
                isLoading={isLoading}
                unreadMessagesCount={unreadMessagesCount}
            />

            <main>
                {children}
            </main>

            <footer className="mx-auto max-w-5xl px-6 py-8 text-center text-sm text-gray-400">
                Family Education
            </footer>
        </div>
    );
}
