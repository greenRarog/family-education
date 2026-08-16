import React from 'react';
import {createRoot} from 'react-dom/client';

import Register from './pages/Register.jsx';
import Login from './pages/Login.jsx';
import Home from './pages/Home.jsx';
import FamilyProfile from './pages/FamilyProfile.jsx';
import ForgotPassword from './pages/ForgotPassword.jsx';
import ResetPassword from './pages/ResetPassword.jsx';

import AdminLayout from './layouts/AdminLayout.jsx';

import Dashboard from './pages/admin/Dashboard.jsx';
import Users from './pages/admin/Users.jsx';
import Advertisements from './pages/admin/Advertisements.jsx';
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
            return <Advertisements/>;

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

function App() {
    const path = window.location.pathname;

    if (path === '/admin' || path.startsWith('/admin/')) {
        return (
            <AdminLayout>
                <AdminPage/>
            </AdminLayout>
        );
    }

    switch (path) {
        case '/family/profile':
            return <FamilyProfile/>;

        case '/register':
            return <Register/>;

        case '/login':
            return <Login/>;

        case '/forgot-password':
            return <ForgotPassword/>;

        case '/reset-password':
            return <ResetPassword/>;

        case '/':
            return <Home/>;

        default:
            return (
                <div>
                    <h1>Family Education</h1>
                </div>
            );
    }
}

const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(<App/>);
}
