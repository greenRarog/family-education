import React from 'react';
import {createRoot} from 'react-dom/client';

import Register from './pages/Register.jsx';
import Login from './pages/Login.jsx';
import Home from './pages/Home.jsx';
import FamilyProfile from './pages/FamilyProfile.jsx';
import ForgotPassword from './pages/ForgotPassword.jsx';
import ResetPassword from './pages/ResetPassword.jsx';
import Advertisements from './pages/Advertisements.jsx';
import AdvertisementShow from './pages/AdvertisementShow.jsx';
import MyAdvertisements from './pages/MyAdvertisements.jsx';
import AdvertisementTypeSelector from './pages/AdvertisementTypeSelector.jsx';
import GroupAdvertisementForm from './pages/GroupAdvertisementForm.jsx';
import FamilyTeacherAdvertisementForm from './pages/FamilyTeacherAdvertisementForm.jsx';
import AdvertisementEditPage from './pages/AdvertisementEditPage.jsx';

import Conversations from './pages/Conversations.jsx';
import ConversationShow from './pages/ConversationShow.jsx';

import Layout from './components/Layout.jsx';

import AdminLayout from './layouts/AdminLayout.jsx';

import Dashboard from './pages/admin/Dashboard.jsx';
import Users from './pages/admin/Users.jsx';
import AdminAdvertisements from './pages/admin/Advertisements.jsx';
import Reports from './pages/admin/Reports.jsx';
import Cities from './pages/admin/Cities.jsx';
import Subjects from './pages/admin/Subjects.jsx';
import BlockedTerms from './pages/admin/BlockedTerms.jsx';

function AdminPage() {
    switch (window.location.pathname) {
        case '/admin':
            return <Dashboard/>;

        case '/admin/users':
            return <Users/>;

        case '/admin/advertisements':
            return <AdminAdvertisements/>;

        case '/admin/reports':
            return <Reports/>;

        case '/admin/cities':
            return <Cities/>;

        case '/admin/subjects':
            return <Subjects/>;

        case '/admin/blocked-terms':
            return <BlockedTerms/>;

        default:
            return (
                <div>
                    <h1>Страница не найдена</h1>
                </div>
            );
    }
}

function UserPage({children}) {
    return (
        <Layout>
            {children}
        </Layout>
    );
}

function App() {
    const path = window.location.pathname;

    /*
     * Админка использует отдельный Layout.
     */
    if (path === '/admin' || path.startsWith('/admin/')) {
        return (
            <AdminLayout>
                <AdminPage/>
            </AdminLayout>
        );
    }

    /*
     * Reset password оставляем без обычного пользовательского Layout.
     */
    if (path === '/reset-password' || path.startsWith('/reset-password/')) {
        return <ResetPassword/>;
    }

    /*
     * Страницы авторизации тоже пока оставляем без Layout.
     */
    switch (path) {
        case '/register':
            return <Register/>;

        case '/login':
            return <Login/>;

        case '/forgot-password':
            return <ForgotPassword/>;
    }

    /*
     * Личные сообщения.
     */
    if (path === '/conversations') {
        return (
            <UserPage>
                <Conversations/>
            </UserPage>
        );
    }

    const conversationMatch = path.match(
        /^\/conversations\/(\d+)$/
    );

    if (conversationMatch) {
        return (
            <UserPage>
                <ConversationShow
                    conversationId={conversationMatch[1]}
                />
            </UserPage>
        );
    }

    switch (path) {
        case '/':
            return (
                <UserPage>
                    <Home/>
                </UserPage>
            );

        case '/advertisements':
            return (
                <UserPage>
                    <Advertisements/>
                </UserPage>
            );

        case '/my-advertisements':
            return (
                <UserPage>
                    <MyAdvertisements/>
                </UserPage>
            );

        case '/advertisements/create':
            return (
                <UserPage>
                    <AdvertisementTypeSelector/>
                </UserPage>
            );

        case '/advertisements/new':
            return (
                <UserPage>
                    <GroupAdvertisementForm/>
                </UserPage>
            );

        case '/advertisements/new-teacher':
            return (
                <UserPage>
                    <FamilyTeacherAdvertisementForm/>
                </UserPage>
            );

        case '/family/profile':
            return (
                <UserPage>
                    <FamilyProfile/>
                </UserPage>
            );

        default: {
            const editMatch = path.match(
                /^\/advertisements\/(\d+)\/edit$/
            );

            if (editMatch) {
                return (
                    <UserPage>
                        <AdvertisementEditPage
                            advertisementId={editMatch[1]}
                        />
                    </UserPage>
                );
            }

            const showMatch = path.match(
                /^\/advertisements\/(\d+)$/
            );

            if (showMatch) {
                return (
                    <UserPage>
                        <AdvertisementShow
                            advertisementId={showMatch[1]}
                        />
                    </UserPage>
                );
            }

            return (
                <UserPage>
                    <div>
                        <h1>Family Education</h1>
                    </div>
                </UserPage>
            );
        }
    }
}

const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(<App/>);
}
