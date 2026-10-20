import type { ISODate, Planning } from '../types';
import { PlanningCard } from '../components/PlanningCard';
import { Icon } from '../components/Icon';

interface Props {
  plannings: Planning[];
  today: ISODate;
  onOpen: (planningId: string) => void;
}

export function PlanningsPage({ plannings, today, onOpen }: Props) {
  return (
    <div className="mv-page">
      <header className="mv-page__head">
        <div>
          <h1>Mes plannings</h1>
          <p className="mv-muted">Chaque planning regroupe ses périodes de gardes successives.</p>
        </div>
        <button type="button" className="mv-btn mv-btn--secondary"><Icon name="plus" /> Créer un planning</button>
      </header>
      <div className="mv-stack">
        {plannings.map((p) => <PlanningCard key={p.id} planning={p} today={today} onOpen={onOpen} />)}
      </div>
    </div>
  );
}
