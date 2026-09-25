import { StrictMode, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './tokens.css';
import './planning.css';
import { PlanningDetail } from './PlanningDetail';
import * as data from './data';
import type { Collecte, Line } from './types';

// Démo locale : l'état vit ici. En prod, branchez chaque callback sur l'API / les routes.
function Demo() {
  const [lines, setLines] = useState<Line[]>(data.lines);
  const [collecte, setCollecte] = useState<Collecte | null>(data.initialCollecte);
  return (
    <PlanningDetail
      planning={data.planning}
      lines={lines}
      members={data.members}
      currentUserId={data.currentUserId}
      collecte={collecte}
      onGenerate={() => console.log('générer')}
      onRename={() => console.log('renommer')}
      onSettings={() => console.log('paramètres')}
      onExtend={() => console.log('prolonger')}
      onManageMembers={id => console.log('membres', id)}
      onAddLine={() => setLines(ls => [...ls, { id: 'l' + Date.now(), name: `Nouvelle ligne ${ls.length + 1}`, kind: 'secondaire' }])}
      onDeleteLine={id => setLines(ls => ls.filter(l => l.id !== id))}
      onOpenCollecte={deadline => setCollecte({ deadline, respondedIds: data.members.filter((m, i) => m.unavailableDays > 0 && i % 3 !== 0).map(m => m.id) })}
      onRemind={() => setCollecte(c => c && { ...c, remindedAt: new Date().toISOString() })}
      onCloseCollecte={() => setCollecte(null)}
    />
  );
}

createRoot(document.getElementById('root')!).render(<StrictMode><Demo /></StrictMode>);
