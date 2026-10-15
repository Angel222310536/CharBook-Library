import './bootstrap';

import React from 'react';
import ReactDOM from 'react-dom/client';

function App() {
    return (
        <div style={{ padding: '20px' }}>
            <h1>CharBook Library</h1>
            <p>Laravel + React funcionando 🚀</p>
        </div>
    );
}

ReactDOM.createRoot(document.getElementById('app')).render(
    <React.StrictMode>
        <App />
    </React.StrictMode>
);