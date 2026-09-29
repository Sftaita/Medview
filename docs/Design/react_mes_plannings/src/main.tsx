import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './tokens.css';
import './mes-plannings.css';
import { MesPlannings } from './MesPlannings';
import * as data from './data';

// Démo locale : date figée pour correspondre à la maquette. En prod, retirez `today`.
// Pour tester le regroupement : plannings={data.manyPlannings} ; état vide : plannings={[]}
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <MesPlannings
      user={data.user}
      plannings={data.plannings}
      today={new Date(2026, 8, 29)}
      onCreate={() => console.log('créer un planning')}
      onLogout={() => console.log('déconnexion')}
    />
  </StrictMode>,
);
