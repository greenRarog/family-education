import React from 'react';
import {createRoot} from 'react-dom/client';

import Register from './pages/Register.jsx';
import Login from './pages/Login.jsx';
import Home from './pages/Home.jsx';

function App() {
    switch (window.location.pathname) {
        case '/register':
            return <Register/>;

        case '/login':
            return <Login/>;

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
