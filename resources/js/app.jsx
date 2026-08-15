import React from 'react';
import {createRoot} from 'react-dom/client';

import Register from './pages/Register.jsx';
import Login from './pages/Login.jsx';
import Home from './pages/Home.jsx';
import FamilyProfile from './pages/FamilyProfile.jsx';
import ForgotPassword from './pages/ForgotPassword.jsx';
import ResetPassword from './pages/ResetPassword.jsx';

function App() {
    switch (window.location.pathname) {
        case '/family/profile':
            return <FamilyProfile />;

        case '/register':
            return <Register/>;

        case '/login':
            return <Login/>;

        case '/forgot-password':
            return <ForgotPassword />;

        case '/reset-password':
            return <ResetPassword />;

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
