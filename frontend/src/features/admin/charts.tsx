import { useId, useState, type CSSProperties, type MouseEvent } from 'react'
import { formatNumber } from './format'

/**
 * Two small, dependency-free charts for the administration (docs/admin.md §9). One series per bar chart (the
 * title names it, no legend); the DAU/MAU line chart has two series, a legend, and the MAU dashed as a second
 * cue besides colour. Every chart has a hover/focus tooltip and its numbers in a table (« Voir les données »).
 */

export type BarDatum = { key: string; label: string; shortLabel: string; value: number }

function niceMax(value: number): number {
  if (value <= 4) return 4
  const magnitude = 10 ** Math.floor(Math.log10(value))
  for (const step of [1, 2, 2.5, 5, 10]) {
    if (step * magnitude >= value) return step * magnitude
  }
  return 10 * magnitude
}

/** Keeps the tooltip inside the plot: anchored left near the left edge, right near the right edge. */
function tooltipPosition(percent: number): CSSProperties {
  const transform =
    percent > 70 ? 'translate(-100%, -100%)' : percent < 30 ? 'translate(0, -100%)' : undefined
  return { left: `${percent}%`, transform }
}

/** Which x labels to print: first, last and a few evenly spaced in between. */
function tickIndexes(count: number, wanted = 5): Set<number> {
  if (count <= wanted) return new Set(Array.from({ length: count }, (_, index) => index))
  const step = (count - 1) / (wanted - 1)
  return new Set(Array.from({ length: wanted }, (_, index) => Math.round(index * step)))
}

export function BarChart({
  title,
  data,
  unit,
  total: overallTotal,
}: {
  title: string
  data: BarDatum[]
  unit: string
  /** A total that is not the sum of the bars (e.g. distinct users over the period). */
  total?: number
}) {
  const [active, setActive] = useState<number | null>(null)
  const max = niceMax(Math.max(0, ...data.map((datum) => datum.value)))
  const ticks = tickIndexes(data.length)
  const total = overallTotal ?? data.reduce((sum, datum) => sum + datum.value, 0)
  const hovered = active !== null ? data[active] : null

  return (
    <figure className="adm-chart">
      <figcaption className="adm-chart__caption">
        <span className="adm-chart__title">{title}</span>
        <span className="adm-chart__total tnum">
          {formatNumber(total)} {unit}
        </span>
      </figcaption>
      <div
        className="adm-chart__plot"
        role="img"
        aria-label={`${title} : ${formatNumber(total)} ${unit} sur la période`}
      >
        <div className="adm-chart__grid" aria-hidden="true">
          <span className="adm-chart__gridline" style={{ bottom: '100%' }}>
            <span className="adm-chart__gridlabel tnum">{formatNumber(max)}</span>
          </span>
          <span className="adm-chart__gridline" style={{ bottom: '50%' }}>
            <span className="adm-chart__gridlabel tnum">{formatNumber(max / 2)}</span>
          </span>
          <span className="adm-chart__gridline adm-chart__gridline--base" style={{ bottom: 0 }} />
        </div>
        <div className="adm-chart__bars" onMouseLeave={() => setActive(null)}>
          {data.map((datum, index) => (
            <div
              key={datum.key}
              className="adm-chart__slot"
              onMouseEnter={() => setActive(index)}
              onFocus={() => setActive(index)}
              onBlur={() => setActive(null)}
              tabIndex={0}
              aria-label={`${datum.label} : ${formatNumber(datum.value)} ${unit}`}
              data-active={active === index || undefined}
            >
              <span
                className="adm-chart__bar"
                style={{ height: datum.value === 0 ? 0 : `max(2px, ${(datum.value / max) * 100}%)` }}
              />
            </div>
          ))}
        </div>
        {hovered && (
          <div
            className="adm-chart__tooltip"
            role="status"
            style={tooltipPosition(((active! + 0.5) / data.length) * 100)}
          >
            <span className="adm-chart__tooltip-label">{hovered.label}</span>
            <span className="tnum">
              <strong>{formatNumber(hovered.value)}</strong> {unit}
            </span>
          </div>
        )}
      </div>
      <div className="adm-chart__axis" aria-hidden="true">
        {data.map((datum, index) => (
          <span key={datum.key} className="adm-chart__tick">
            {ticks.has(index) ? datum.shortLabel : ''}
          </span>
        ))}
      </div>
      <DataTable
        caption={title}
        headers={['Période', unit]}
        rows={data.map((datum) => [datum.label, formatNumber(datum.value)])}
      />
    </figure>
  )
}

export type LinePoint = { key: string; label: string; shortLabel: string; values: number[] }

export function LineChart({
  title,
  series,
  points,
}: {
  title: string
  series: { name: string; className: string; dashed?: boolean }[]
  points: LinePoint[]
}) {
  const [active, setActive] = useState<number | null>(null)
  const max = niceMax(Math.max(0, ...points.flatMap((point) => point.values)))
  const ticks = tickIndexes(points.length)
  const width = 1000
  const height = 240
  const x = (index: number) => (points.length <= 1 ? width / 2 : (index / (points.length - 1)) * width)
  const y = (value: number) => height - (value / max) * height
  const hovered = active !== null ? points[active] : null

  function onMove(event: MouseEvent<HTMLDivElement>) {
    const box = event.currentTarget.getBoundingClientRect()
    const ratio = (event.clientX - box.left) / box.width
    setActive(Math.max(0, Math.min(points.length - 1, Math.round(ratio * (points.length - 1)))))
  }

  return (
    <figure className="adm-chart">
      <figcaption className="adm-chart__caption">
        <span className="adm-chart__title">{title}</span>
        <span className="adm-chart__legend">
          {series.map((serie) => (
            <span key={serie.name} className="adm-chart__legend-item">
              <span
                className={`adm-chart__swatch ${serie.className}${serie.dashed ? ' adm-chart__swatch--dashed' : ''}`}
              />
              {serie.name}
            </span>
          ))}
        </span>
      </figcaption>
      <div
        className="adm-chart__plot adm-chart__plot--line"
        role="img"
        aria-label={`${title}, ${points.length} jours`}
        onMouseMove={onMove}
        onMouseLeave={() => setActive(null)}
      >
        <div className="adm-chart__grid" aria-hidden="true">
          <span className="adm-chart__gridline" style={{ bottom: '100%' }}>
            <span className="adm-chart__gridlabel tnum">{formatNumber(max)}</span>
          </span>
          <span className="adm-chart__gridline" style={{ bottom: '50%' }}>
            <span className="adm-chart__gridlabel tnum">{formatNumber(max / 2)}</span>
          </span>
          <span className="adm-chart__gridline adm-chart__gridline--base" style={{ bottom: 0 }} />
        </div>
        <svg
          className="adm-chart__svg"
          viewBox={`0 0 ${width} ${height}`}
          preserveAspectRatio="none"
          aria-hidden="true"
        >
          {series.map((serie, serieIndex) => (
            <polyline
              key={serie.name}
              className={`adm-chart__line ${serie.className}`}
              strokeDasharray={serie.dashed ? '6 4' : undefined}
              points={points.map((point, index) => `${x(index)},${y(point.values[serieIndex])}`).join(' ')}
            />
          ))}
          {hovered && (
            <line className="adm-chart__crosshair" x1={x(active!)} x2={x(active!)} y1={0} y2={height} />
          )}
        </svg>
        {hovered && (
          <div
            className="adm-chart__tooltip"
            role="status"
            style={tooltipPosition(points.length <= 1 ? 50 : (active! / (points.length - 1)) * 100)}
          >
            <span className="adm-chart__tooltip-label">{hovered.label}</span>
            {series.map((serie, index) => (
              <span key={serie.name} className="tnum">
                <span className={`adm-chart__swatch ${serie.className}`} /> {serie.name} :{' '}
                <strong>{formatNumber(hovered.values[index])}</strong>
              </span>
            ))}
          </div>
        )}
      </div>
      <div className="adm-chart__axis" aria-hidden="true">
        {points.map((point, index) => (
          <span key={point.key} className="adm-chart__tick">
            {ticks.has(index) ? point.shortLabel : ''}
          </span>
        ))}
      </div>
      <DataTable
        caption={title}
        headers={['Jour', ...series.map((serie) => serie.name)]}
        rows={points.map((point) => [point.label, ...point.values.map(formatNumber)])}
      />
    </figure>
  )
}

/** The numbers behind a chart, for keyboard and screen-reader users and anyone who wants exact values. */
export function DataTable({
  caption,
  headers,
  rows,
}: {
  caption: string
  headers: string[]
  rows: string[][]
}) {
  const id = useId()
  return (
    <details className="adm-chart__data">
      <summary>Voir les données</summary>
      <div className="adm-table-wrap">
        <table className="adm-table adm-table--compact" aria-describedby={id}>
          <caption id={id} className="sr-only">
            {caption}
          </caption>
          <thead>
            <tr>
              {headers.map((header) => (
                <th key={header} scope="col">
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row[0]}>
                {row.map((cell, index) =>
                  index === 0 ? (
                    <th key={index} scope="row">
                      {cell}
                    </th>
                  ) : (
                    <td key={index} className="tnum">
                      {cell}
                    </td>
                  ),
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}
