import { createRoot } from 'react-dom/client';

function App() {
    return (
        <div>
            <h1>Family Education</h1>
        </div>
    );
}

const element = document.getElementById('app');

if (element) {
    createRoot(element).render(<App />);
}
