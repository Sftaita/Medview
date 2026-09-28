import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './tokens.css';
import './dashboard.css';
import { Dashboard } from './Dashboard';
import * as data from './data';

// Démo locale : date figée pour correspondre à la maquette. En prod, retirez `today`.
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Dashboard
      user={data.user}
      plannings={data.plannings}
      unavailabilities={data.unavailabilities}
      today={new Date(2026, 8, 26)}
      onDeclare={() => console.log('déclarer une indisponibilité')}
      onLogout={() => console.log('déconnexion')}
    />
  </StrictMode>,
);
