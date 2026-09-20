import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { clearJoinedTeamsFlash, readJoinedTeamsFlash } from '../features/auth/joinedTeamsFlash'

export function DashboardPage() {
  const [joinedTeams] = useState(readJoinedTeamsFlash)

  // Shown once: cleared as soon as it has been rendered.
  useEffect(() => clearJoinedTeamsFlash(), [])

  return (
    <section>
      <h1>Tableau de bord</h1>
      {joinedTeams.length > 0 && (
        <div role="status" className="joined-teams-banner">
          <p>
            Votre compte a bien été créé. Vous avez été ajouté automatiquement à{' '}
            {joinedTeams.length > 1 ? 'ces équipes' : 'cette équipe'} :
          </p>
          <ul>
            {joinedTeams.map((team) => (
              <li key={team.teamStableId}>
                <Link to={`/plannings/${team.planningStableId}`}>{team.teamName}</Link> ({team.planningName})
              </li>
            ))}
          </ul>
        </div>
      )}
      <p>Cette page sera implémentée dans une itération ultérieure.</p>
    </section>
  )
}
