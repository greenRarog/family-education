import {useEffect, useState} from 'react';
import Header from './Header.jsx';

export default function Layout({children}) {
    const [user, setUser] = useState(null);
    const [isAuthenticated, setIsAuthenticated] = useState(false);
    const [isLoading, setIsLoading] = useState(true);

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
                    return;
                }

                const data = await response.json();

                setIsAuthenticated(data.authenticated === true);
                setUser(data.user ?? null);
            } catch {
                setIsAuthenticated(false);
                setUser(null);
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
