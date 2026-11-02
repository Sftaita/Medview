\pset tuples_only on
\pset format unaligned
SELECT 'duties|'||id||'|'||md5(t::text) FROM duties t
UNION ALL SELECT 'duty_assignments|'||id||'|'||md5(t::text) FROM duty_assignments t
UNION ALL SELECT 'duty_assignment_events|'||id||'|'||md5(t::text) FROM duty_assignment_events t
UNION ALL SELECT 'planning_generations|'||id||'|'||md5(t::text) FROM planning_generations t
UNION ALL SELECT 'planning_snapshots|'||id||'|'||md5(t::text) FROM planning_snapshots t
UNION ALL SELECT 'planning_snapshot_members|'||id||'|'||md5(t::text) FROM planning_snapshot_members t
UNION ALL SELECT 'planning_snapshot_external_commitments|'||id||'|'||md5(t::text) FROM planning_snapshot_external_commitments t
UNION ALL SELECT 'planning_snapshot_availability_periods|'||id||'|'||md5(t::text) FROM planning_snapshot_availability_periods t
UNION ALL SELECT 'planning_publications|'||id||'|'||md5(t::text) FROM planning_publications t
UNION ALL SELECT 'planning_publication_entries|'||id||'|'||md5(t::text) FROM planning_publication_entries t
UNION ALL SELECT 'planning_publication_lines|'||id||'|'||md5(t::text) FROM planning_publication_lines t
UNION ALL SELECT 'planning_publication_documents|'||id||'|'||md5(t::text) FROM planning_publication_documents t
UNION ALL SELECT 'planning_publication_notifications|'||id||'|'||md5(t::text) FROM planning_publication_notifications t
UNION ALL SELECT 'planning_publication_deliveries|'||id||'|'||md5(t::text) FROM planning_publication_deliveries t
ORDER BY 1;
