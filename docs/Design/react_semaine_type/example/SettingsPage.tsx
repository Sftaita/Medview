import { useState } from 'react';
import { WeekStructureEditor, fromPayload, preset, WeekStructure, WeekStructurePayload } from '../src';

/* Exemple d'intégration : page de paramètres d'une ligne de planning.
   Remplacer les appels fetch par votre client API. */

export function SettingsPage({ lineId, initial }: { lineId: string; initial?: WeekStructurePayload }) {
  const [structure, setStructure] = useState<WeekStructure>(() => (initial ? fromPayload(initial) : preset('vsd')));
  const [payload, setPayload] = useState<WeekStructurePayload | null>(null);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    if (!payload) return;
    setSaving(true);
    try {
      await fetch(`/api/planning-lines/${lineId}/week-structure`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      setPayload(null);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ maxWidth: 1160, margin: '0 auto', padding: 24, display: 'flex', flexDirection: 'column', gap: 16 }}>
      <WeekStructureEditor
        value={structure}
        onChange={(next, p) => { setStructure(next); setPayload(p); }}
      />
      <button type="button" disabled={!payload || saving} onClick={save}>
        {saving ? 'Enregistrement…' : 'Enregistrer la structure'}
      </button>
    </div>
  );
}
