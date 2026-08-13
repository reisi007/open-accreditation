// MUST be the first import: I18nProvider activates the locale at module scope.
// Any module-scope `t` macro call in the app graph would otherwise evaluate
// before i18n.activate() in the production bundle and crash with a Lingui
// locale error.
import { I18nProvider } from './logic/I18nProvider';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './index.css';

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <I18nProvider>
            <App />
        </I18nProvider>
    </StrictMode>
);
