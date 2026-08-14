import React from 'react';
import { createRoot } from 'react-dom/client';

import Register from './pages/Register.jsx';

console.log('0');

function App() {
    console.log('111');

    if (window.location.pathname === '/register') {
        console.log('1');

        return <Register />;
    }

    console.log('2');

    return (
        <div>
            <h1>Family Education</h1>
        </div>
    );
}

const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(<App />);
}
