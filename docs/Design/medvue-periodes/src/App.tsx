import { useMemo, useState } from 'react';
import { DEMO_TODAY, demoPlannings, demoShifts, type Scenario } from './data/mock';
import { PlanningsPage } from './pages/PlanningsPage';
import { PlanningDetailPage } from './pages/PlanningDetailPage';

/** Démo autonome — dans MedVue, branchez les pages sur votre routeur et votre API. */
export default function App() {
  const [scenario, setScenario] = useState<Scenario>('8');
  const [openId, setOpenId] = useState<string | null>(null);
  const plannings = useMemo(() => demoPlannings(scenario), [scenario]);
  const planning = plannings.find((p) => p.id === openId);

  return (
    <>
      <div className="mv-demo-bar" role="region" aria-label="Contrôles de démonstration">
        <span>Démo · aujourd’hui = 10 oct. 2026</span>
        <label>
          Trauma Delta :
          <select value={scenario} onChange={(e) => setScenario(e.target.value as Scenario)}>
            <option value="1">1 période</option>
            <option value="2">2 périodes</option>
            <option value="4">4 périodes</option>
            <option value="8">8 périodes</option>
          </select>
        </label>
      </div>
      {planning
        ? <PlanningDetailPage planning={planning} today={DEMO_TODAY} getShifts={demoShifts} onBack={() => setOpenId(null)} />
        : <PlanningsPage plannings={plannings} today={DEMO_TODAY} onOpen={setOpenId} />}
    </>
  );
}
