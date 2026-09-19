/* @ds-bundle: {"format":3,"namespace":"DesignSystem_d99104","components":[{"name":"Avatar","sourcePath":"components/data-display/Avatar.jsx"},{"name":"Card","sourcePath":"components/data-display/Card.jsx"},{"name":"StatTile","sourcePath":"components/data-display/StatTile.jsx"},{"name":"Tag","sourcePath":"components/data-display/Tag.jsx"},{"name":"Badge","sourcePath":"components/feedback/Badge.jsx"},{"name":"StatusPill","sourcePath":"components/feedback/StatusPill.jsx"},{"name":"Toast","sourcePath":"components/feedback/Toast.jsx"},{"name":"Button","sourcePath":"components/forms/Button.jsx"},{"name":"Checkbox","sourcePath":"components/forms/Checkbox.jsx"},{"name":"IconButton","sourcePath":"components/forms/IconButton.jsx"},{"name":"Input","sourcePath":"components/forms/Input.jsx"},{"name":"Select","sourcePath":"components/forms/Select.jsx"},{"name":"Switch","sourcePath":"components/forms/Switch.jsx"},{"name":"AppBar","sourcePath":"components/mobile/AppBar.jsx"},{"name":"BottomNav","sourcePath":"components/mobile/BottomNav.jsx"},{"name":"EmptyState","sourcePath":"components/mobile/EmptyState.jsx"},{"name":"InfoBanner","sourcePath":"components/mobile/InfoBanner.jsx"},{"name":"ListRow","sourcePath":"components/mobile/ListRow.jsx"},{"name":"MissionCard","sourcePath":"components/mobile/MissionCard.jsx"},{"name":"MissionHero","sourcePath":"components/mobile/MissionHero.jsx"},{"name":"OfferCard","sourcePath":"components/mobile/OfferCard.jsx"},{"name":"SectionHeader","sourcePath":"components/mobile/SectionHeader.jsx"},{"name":"Tabs","sourcePath":"components/navigation/Tabs.jsx"}],"sourceHashes":{"components/data-display/Avatar.jsx":"2e076f3d4395","components/data-display/Card.jsx":"325d70cb4f14","components/data-display/StatTile.jsx":"990ab8c13ca4","components/data-display/Tag.jsx":"c34834685c8b","components/feedback/Badge.jsx":"ca23b55baee4","components/feedback/StatusPill.jsx":"77a47f0c3b60","components/feedback/Toast.jsx":"5ec317298672","components/forms/Button.jsx":"f732d6741a2a","components/forms/Checkbox.jsx":"e30fb7edc382","components/forms/IconButton.jsx":"fb00db66e60b","components/forms/Input.jsx":"33466400a9b4","components/forms/Select.jsx":"fb52f766d713","components/forms/Switch.jsx":"0d74b1d5538d","components/mobile/AppBar.jsx":"f0e0f0c8eb7b","components/mobile/BottomNav.jsx":"c66bd109afa7","components/mobile/EmptyState.jsx":"0c86b18b21f8","components/mobile/InfoBanner.jsx":"bb2495044f63","components/mobile/ListRow.jsx":"34b31a11243a","components/mobile/MissionCard.jsx":"7520f361372e","components/mobile/MissionHero.jsx":"cbefebcd142b","components/mobile/OfferCard.jsx":"f09666f9c5bb","components/mobile/SectionHeader.jsx":"348000b36a31","components/navigation/Tabs.jsx":"94ec1e7f96e2","ui_kits/instrumentiste/app.jsx":"6a56e1bd56c2","ui_kits/instrumentiste/canvas.jsx":"4df3405dc306","ui_kits/instrumentiste/data.js":"2aad975c4186","ui_kits/instrumentiste/design-canvas.jsx":"bd8746af6e58","ui_kits/instrumentiste/ios-frame.jsx":"be3343be4b51","ui_kits/instrumentiste/nav.jsx":"7408d28c73c0","ui_kits/instrumentiste/screens.jsx":"b3e16a17a642","ui_kits/instrumentiste/shells.jsx":"341681e25670"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.DesignSystem_d99104 = window.DesignSystem_d99104 || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/data-display/Avatar.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Avatar: person initial/photo with deterministic color. */

const CSS = `
.sh-avatar{
  display:inline-flex; align-items:center; justify-content:center; flex:none;
  width:36px; height:36px; border-radius:50%; overflow:hidden;
  font-family:var(--font-sans); font-weight:600; font-size:var(--text-sm);
  color:#fff; user-select:none; position:relative;
}
.sh-avatar[data-size="xs"]{ width:22px; height:22px; font-size:var(--text-2xs); }
.sh-avatar[data-size="sm"]{ width:28px; height:28px; font-size:var(--text-xs); }
.sh-avatar[data-size="lg"]{ width:48px; height:48px; font-size:var(--text-lg); }
.sh-avatar img{ width:100%; height:100%; object-fit:cover; }
.sh-avatar-ring{ box-shadow:0 0 0 2px var(--surface-card), 0 0 0 3px var(--border-default); }
.sh-avatar-status{ position:absolute; bottom:-1px; right:-1px; width:30%; height:30%; min-width:8px; min-height:8px; border-radius:50%; border:2px solid var(--surface-card); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-avatar-css')) {
  const s = document.createElement('style');
  s.id = 'sh-avatar-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
const PALETTE = ['#338F6E', '#2D7FF9', '#7C3AED', '#B7791F', '#42A882', '#C44D8A', '#0E7C86'];
function pick(name = '') {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = h * 31 + name.charCodeAt(i) | 0;
  return PALETTE[Math.abs(h) % PALETTE.length];
}
function initials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}
function Avatar({
  name = '',
  src,
  size = 'md',
  ring = false,
  status,
  ...rest
}) {
  const bg = pick(name);
  const statusColor = {
    online: 'var(--green-500)',
    busy: 'var(--red-500)',
    away: 'var(--amber-500)',
    offline: 'var(--gray-300)'
  }[status];
  return /*#__PURE__*/React.createElement("span", _extends({
    className: `sh-avatar${ring ? ' sh-avatar-ring' : ''}`,
    "data-size": size,
    style: {
      background: src ? 'var(--gray-200)' : bg
    },
    title: name
  }, rest), src ? /*#__PURE__*/React.createElement("img", {
    src: src,
    alt: name
  }) : initials(name), status && /*#__PURE__*/React.createElement("span", {
    className: "sh-avatar-status",
    style: {
      background: statusColor
    }
  }));
}
Object.assign(__ds_scope, { Avatar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data-display/Avatar.jsx", error: String((e && e.message) || e) }); }

// components/data-display/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Card: the primary content surface. */

const CSS = `
.sh-card{
  display:flex; flex-direction:column;
  background:var(--surface-card); border:1px solid var(--border-subtle);
  border-radius:var(--radius-card); overflow:hidden;
}
.sh-card[data-elevation="sm"]{ box-shadow:var(--shadow-sm); }
.sh-card[data-elevation="md"]{ box-shadow:var(--shadow-md); }
.sh-card[data-interactive="true"]{ cursor:pointer; transition: box-shadow var(--dur-base) var(--ease-out), border-color var(--dur-base) var(--ease-out), transform var(--dur-base) var(--ease-out); }
.sh-card[data-interactive="true"]:hover{ box-shadow:var(--shadow-md); border-color:var(--border-default); transform:translateY(-1px); }
.sh-card[data-accent="true"]{ border-top:3px solid var(--brand); }
.sh-card-header{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 18px; border-bottom:1px solid var(--border-subtle); }
.sh-card-title{ font-family:var(--font-display); font-size:var(--text-lg); font-weight:600; color:var(--text-strong); letter-spacing:var(--tracking-snug); }
.sh-card-eyebrow{ font-size:var(--text-2xs); letter-spacing:var(--tracking-caps); text-transform:uppercase; font-weight:600; color:var(--text-subtle); margin-bottom:3px; }
.sh-card-body{ padding:18px; }
.sh-card-body[data-flush="true"]{ padding:0; }
.sh-card-footer{ padding:14px 18px; border-top:1px solid var(--border-subtle); background:var(--surface-sunken); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-card-css')) {
  const s = document.createElement('style');
  s.id = 'sh-card-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Card({
  title,
  eyebrow,
  actions,
  footer,
  elevation = 'sm',
  interactive = false,
  accent = false,
  flush = false,
  children,
  ...rest
}) {
  const hasHeader = title || eyebrow || actions;
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-card",
    "data-elevation": elevation,
    "data-interactive": interactive ? 'true' : undefined,
    "data-accent": accent ? 'true' : undefined
  }, rest), hasHeader && /*#__PURE__*/React.createElement("div", {
    className: "sh-card-header"
  }, /*#__PURE__*/React.createElement("div", null, eyebrow && /*#__PURE__*/React.createElement("div", {
    className: "sh-card-eyebrow"
  }, eyebrow), title && /*#__PURE__*/React.createElement("div", {
    className: "sh-card-title"
  }, title)), actions), /*#__PURE__*/React.createElement("div", {
    className: "sh-card-body",
    "data-flush": flush ? 'true' : undefined
  }, children), footer && /*#__PURE__*/React.createElement("div", {
    className: "sh-card-footer"
  }, footer));
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data-display/Card.jsx", error: String((e && e.message) || e) }); }

// components/data-display/StatTile.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — StatTile: a single KPI / metric tile for dashboards. */

const CSS = `
.sh-stat{
  display:flex; flex-direction:column; gap:6px;
  background:var(--surface-card); border:1px solid var(--border-subtle);
  border-radius:var(--radius-lg); padding:16px 18px; font-family:var(--font-sans);
}
.sh-stat-top{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.sh-stat-label{ font-size:var(--text-xs); font-weight:600; letter-spacing:var(--tracking-caps); text-transform:uppercase; color:var(--text-subtle); }
.sh-stat-icon{ width:18px; height:18px; color:var(--text-subtle); display:flex; }
.sh-stat-value{ font-family:var(--font-data); font-variant-numeric:tabular-nums; font-size:var(--text-3xl); font-weight:700; color:var(--text-strong); line-height:1; letter-spacing:-0.02em; }
.sh-stat-value .unit{ font-size:var(--text-lg); color:var(--text-muted); margin-left:3px; }
.sh-stat-meta{ display:flex; align-items:center; gap:6px; font-size:var(--text-sm); color:var(--text-muted); }
.sh-stat-delta{ display:inline-flex; align-items:center; gap:3px; font-weight:600; font-variant-numeric:tabular-nums; }
.sh-stat-delta[data-dir="up"]{ color:var(--green-700); }
.sh-stat-delta[data-dir="down"]{ color:var(--red-700); }
.sh-stat-delta[data-dir="flat"]{ color:var(--text-muted); }
.sh-stat-delta svg{ width:13px; height:13px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-stat-css')) {
  const s = document.createElement('style');
  s.id = 'sh-stat-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
const ARROWS = {
  up: /*#__PURE__*/React.createElement("path", {
    d: "M7 11V3M7 3l-3.5 3.5M7 3l3.5 3.5"
  }),
  down: /*#__PURE__*/React.createElement("path", {
    d: "M7 3v8M7 11l-3.5-3.5M7 11l3.5-3.5"
  }),
  flat: /*#__PURE__*/React.createElement("path", {
    d: "M3 7h8"
  })
};
function StatTile({
  label,
  value,
  unit,
  icon,
  delta,
  deltaDir = 'flat',
  meta,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-stat"
  }, rest), /*#__PURE__*/React.createElement("div", {
    className: "sh-stat-top"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-stat-label"
  }, label), icon && /*#__PURE__*/React.createElement("span", {
    className: "sh-stat-icon"
  }, icon)), /*#__PURE__*/React.createElement("div", {
    className: "sh-stat-value"
  }, value, unit && /*#__PURE__*/React.createElement("span", {
    className: "unit"
  }, unit)), (delta != null || meta) && /*#__PURE__*/React.createElement("div", {
    className: "sh-stat-meta"
  }, delta != null && /*#__PURE__*/React.createElement("span", {
    className: "sh-stat-delta",
    "data-dir": deltaDir
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 14 14",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, ARROWS[deltaDir]), delta), meta && /*#__PURE__*/React.createElement("span", null, meta)));
}
Object.assign(__ds_scope, { StatTile });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data-display/StatTile.jsx", error: String((e && e.message) || e) }); }

// components/data-display/Tag.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Tag: a removable filter/attribute chip. */

const CSS = `
.sh-tag{
  display:inline-flex; align-items:center; gap:6px;
  font-family:var(--font-sans); font-size:var(--text-sm); font-weight:500; line-height:1;
  padding:5px 6px 5px 10px; border-radius:var(--radius-sm);
  background:var(--gray-100); color:var(--gray-700); border:1px solid transparent;
}
.sh-tag[data-removable="false"]{ padding-right:10px; }
.sh-tag[data-tone="brand"]{ background:var(--brand-subtle); color:var(--green-700); }
.sh-tag[data-tone="accent"]{ background:var(--blue-50); color:var(--blue-700); }
.sh-tag[data-tone="outline"]{ background:transparent; border-color:var(--border-default); color:var(--text-body); }
.sh-tag-dot{ width:7px; height:7px; border-radius:50%; flex:none; }
.sh-tag-remove{ display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border:none; background:none; cursor:pointer; color:currentColor; opacity:.6; border-radius:var(--radius-xs); padding:0; }
.sh-tag-remove:hover{ opacity:1; background:color-mix(in srgb, currentColor 14%, transparent); }
.sh-tag-remove svg{ width:11px; height:11px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-tag-css')) {
  const s = document.createElement('style');
  s.id = 'sh-tag-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Tag({
  tone = 'neutral',
  color,
  onRemove,
  children,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("span", _extends({
    className: "sh-tag",
    "data-tone": tone,
    "data-removable": onRemove ? 'true' : 'false'
  }, rest), color && /*#__PURE__*/React.createElement("span", {
    className: "sh-tag-dot",
    style: {
      background: color
    }
  }), children, onRemove && /*#__PURE__*/React.createElement("button", {
    className: "sh-tag-remove",
    "aria-label": "Remove",
    onClick: onRemove
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 16 16",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M4 4l8 8M12 4l-8 8"
  }))));
}
Object.assign(__ds_scope, { Tag });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/data-display/Tag.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Badge: compact count/label chip. */

const CSS = `
.sh-badge{
  display:inline-flex; align-items:center; gap:5px;
  font-family:var(--font-sans); font-size:var(--text-xs); font-weight:600; line-height:1;
  padding:4px 9px; border-radius:var(--radius-pill); white-space:nowrap;
}
.sh-badge[data-size="sm"]{ font-size:var(--text-2xs); padding:3px 7px; }
.sh-badge[data-dot="true"]::before{ content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
.sh-badge[data-tone="neutral"]{ background:var(--gray-100); color:var(--gray-700); }
.sh-badge[data-tone="brand"]{ background:var(--brand-subtle); color:var(--green-700); }
.sh-badge[data-tone="accent"]{ background:var(--blue-50); color:var(--blue-700); }
.sh-badge[data-tone="success"]{ background:var(--status-ok-surface); color:var(--status-ok-fg); }
.sh-badge[data-tone="warning"]{ background:var(--status-warn-surface); color:var(--status-warn-fg); }
.sh-badge[data-tone="critical"]{ background:var(--status-crit-surface); color:var(--status-crit-fg); }
.sh-badge[data-solid="true"][data-tone="critical"]{ background:var(--red-600); color:#fff; }
.sh-badge[data-solid="true"][data-tone="brand"]{ background:var(--brand); color:#fff; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-badge-css')) {
  const s = document.createElement('style');
  s.id = 'sh-badge-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Badge({
  tone = 'neutral',
  size = 'md',
  dot = false,
  solid = false,
  children,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("span", _extends({
    className: "sh-badge",
    "data-tone": tone,
    "data-size": size,
    "data-dot": dot ? 'true' : undefined,
    "data-solid": solid ? 'true' : undefined
  }, rest), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Badge.jsx", error: String((e && e.message) || e) }); }

// components/feedback/StatusPill.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — StatusPill: mission lifecycle status with a leading dot.
   Vocabulary follows the instrumentiste flow:
   Proposée → À venir → En cours → À encoder → Terminée, plus Refusée. */

const CSS = `
.sh-statuspill{
  display:inline-flex; align-items:center; gap:7px;
  font-family:var(--font-sans); font-size:var(--text-sm); font-weight:600; line-height:1;
  padding:5px 11px 5px 9px; border-radius:var(--radius-pill);
  border:1px solid transparent; white-space:nowrap;
}
.sh-statuspill .sh-statuspill-dot{ width:8px; height:8px; border-radius:50%; flex:none; }
.sh-statuspill[data-status="proposee"]{ background:var(--green-50); color:var(--green-700); border-color:var(--green-100); }
.sh-statuspill[data-status="proposee"] .sh-statuspill-dot{ background:var(--green-500); }
.sh-statuspill[data-status="avenir"]{ background:var(--blue-50); color:var(--blue-700); border-color:var(--blue-100); }
.sh-statuspill[data-status="avenir"] .sh-statuspill-dot{ background:var(--blue-600); }
.sh-statuspill[data-status="encours"]{ background:var(--green-50); color:var(--green-800); border-color:var(--green-200); }
.sh-statuspill[data-status="encours"] .sh-statuspill-dot{ background:var(--green-500); box-shadow:0 0 0 3px color-mix(in srgb, var(--green-500) 26%, transparent); animation:sh-pulse 1.8s var(--ease-inout) infinite; }
.sh-statuspill[data-status="aencoder"]{ background:var(--amber-50); color:var(--amber-700); border-color:var(--amber-100); }
.sh-statuspill[data-status="aencoder"] .sh-statuspill-dot{ background:var(--amber-500); }
.sh-statuspill[data-status="terminee"]{ background:var(--gray-100); color:var(--gray-700); border-color:var(--gray-150); }
.sh-statuspill[data-status="terminee"] .sh-statuspill-dot{ background:var(--gray-500); }
.sh-statuspill[data-status="refusee"]{ background:var(--red-50); color:var(--red-700); border-color:var(--red-100); }
.sh-statuspill[data-status="refusee"] .sh-statuspill-dot{ background:var(--red-600); }
@media (prefers-reduced-motion: reduce){ .sh-statuspill[data-status="encours"] .sh-statuspill-dot{ animation:none; } }
@keyframes sh-pulse{ 0%,100%{ box-shadow:0 0 0 0 color-mix(in srgb, var(--green-500) 34%, transparent); } 50%{ box-shadow:0 0 0 4px color-mix(in srgb, var(--green-500) 8%, transparent); } }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-statuspill-css')) {
  const s = document.createElement('style');
  s.id = 'sh-statuspill-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
const LABELS = {
  proposee: 'Proposée',
  avenir: 'À venir',
  encours: 'En cours',
  aencoder: 'À encoder',
  terminee: 'Terminée',
  refusee: 'Refusée'
};
function StatusPill({
  status = 'avenir',
  label,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("span", _extends({
    className: "sh-statuspill",
    "data-status": status
  }, rest), /*#__PURE__*/React.createElement("span", {
    className: "sh-statuspill-dot"
  }), label || LABELS[status] || status);
}
Object.assign(__ds_scope, { StatusPill });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/StatusPill.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Toast.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Toast: transient notification surface. */

const CSS = `
.sh-toast{
  display:flex; align-items:flex-start; gap:12px;
  width:360px; max-width:100%; padding:13px 14px;
  background:var(--surface-card); border:1px solid var(--border-subtle);
  border-left:3px solid var(--gray-400);
  border-radius:var(--radius-md); box-shadow:var(--shadow-lg);
  font-family:var(--font-sans);
}
.sh-toast[data-tone="success"]{ border-left-color:var(--green-600); }
.sh-toast[data-tone="warning"]{ border-left-color:var(--amber-500); }
.sh-toast[data-tone="critical"]{ border-left-color:var(--red-600); }
.sh-toast[data-tone="info"]{ border-left-color:var(--blue-500); }
.sh-toast-icon{ flex:none; width:20px; height:20px; display:flex; margin-top:1px; }
.sh-toast[data-tone="success"] .sh-toast-icon{ color:var(--green-600); }
.sh-toast[data-tone="warning"] .sh-toast-icon{ color:var(--amber-600); }
.sh-toast[data-tone="critical"] .sh-toast-icon{ color:var(--red-600); }
.sh-toast[data-tone="info"] .sh-toast-icon{ color:var(--blue-600); }
.sh-toast-body{ flex:1; min-width:0; }
.sh-toast-title{ font-size:var(--text-sm); font-weight:600; color:var(--text-strong); }
.sh-toast-msg{ font-size:var(--text-sm); color:var(--text-muted); margin-top:2px; }
.sh-toast-close{ flex:none; background:none; border:none; cursor:pointer; color:var(--text-subtle); padding:2px; border-radius:var(--radius-xs); display:flex; }
.sh-toast-close:hover{ color:var(--text-body); background:var(--surface-hover); }
.sh-toast-close svg{ width:15px; height:15px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-toast-css')) {
  const s = document.createElement('style');
  s.id = 'sh-toast-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
const ICONS = {
  success: /*#__PURE__*/React.createElement("path", {
    d: "M4 10l4 4 8-9"
  }),
  warning: /*#__PURE__*/React.createElement("path", {
    d: "M10 4v8m0 3v.5"
  }),
  critical: /*#__PURE__*/React.createElement("path", {
    d: "M10 4v8m0 3v.5"
  }),
  info: /*#__PURE__*/React.createElement("path", {
    d: "M10 9v6m0-9.5v.5"
  })
};
function Toast({
  tone = 'info',
  title,
  message,
  onClose,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-toast",
    "data-tone": tone,
    role: "status"
  }, rest), /*#__PURE__*/React.createElement("span", {
    className: "sh-toast-icon"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 20 20",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, ICONS[tone] || ICONS.info)), /*#__PURE__*/React.createElement("div", {
    className: "sh-toast-body"
  }, title && /*#__PURE__*/React.createElement("div", {
    className: "sh-toast-title"
  }, title), message && /*#__PURE__*/React.createElement("div", {
    className: "sh-toast-msg"
  }, message)), onClose && /*#__PURE__*/React.createElement("button", {
    className: "sh-toast-close",
    "aria-label": "Dismiss",
    onClick: onClose
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 16 16",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M4 4l8 8M12 4l-8 8"
  }))));
}
Object.assign(__ds_scope, { Toast });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Toast.jsx", error: String((e && e.message) || e) }); }

// components/forms/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Button
   Self-contained: injects its own CSS (keyed by id) and styles via
   design-system custom properties. No external CSS required beyond tokens. */

const CSS = `
.sh-btn{
  --_h: var(--control-h-md);
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  height:var(--_h); padding:0 16px; border:1px solid transparent;
  font-family:var(--font-sans); font-size:var(--text-base); font-weight:600;
  line-height:1; letter-spacing:var(--tracking-snug);
  border-radius:var(--radius-md); cursor:pointer; white-space:nowrap;
  transition: background var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out), transform var(--dur-fast) var(--ease-out);
  -webkit-tap-highlight-color:transparent; user-select:none;
}
.sh-btn:focus-visible{ outline:none; box-shadow:var(--focus-ring); }
.sh-btn:active{ transform: translateY(0.5px); }
.sh-btn[data-size="sm"]{ --_h:var(--control-h-sm); padding:0 12px; font-size:var(--text-sm); }
.sh-btn[data-size="lg"]{ --_h:var(--control-h-lg); padding:0 22px; font-size:var(--text-md); }
.sh-btn[data-full="true"]{ width:100%; }
.sh-btn:disabled{ opacity:.5; cursor:not-allowed; transform:none; }

.sh-btn[data-variant="primary"]{ background:var(--brand); color:var(--brand-on); }
.sh-btn[data-variant="primary"]:hover:not(:disabled){ background:var(--brand-hover); }
.sh-btn[data-variant="primary"]:active:not(:disabled){ background:var(--brand-active); }

.sh-btn[data-variant="secondary"]{ background:var(--surface-card); color:var(--text-strong); border-color:var(--border-default); box-shadow:var(--shadow-xs); }
.sh-btn[data-variant="secondary"]:hover:not(:disabled){ background:var(--surface-hover); border-color:var(--border-strong); }

.sh-btn[data-variant="ghost"]{ background:transparent; color:var(--text-body); }
.sh-btn[data-variant="ghost"]:hover:not(:disabled){ background:var(--surface-hover); }

.sh-btn[data-variant="accent"]{ background:var(--accent); color:#fff; }
.sh-btn[data-variant="accent"]:hover:not(:disabled){ background:var(--accent-hover); }

.sh-btn[data-variant="danger"]{ background:var(--red-600); color:#fff; }
.sh-btn[data-variant="danger"]:hover:not(:disabled){ background:var(--red-700); }

.sh-btn svg{ width:1.05em; height:1.05em; flex:none; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-btn-css')) {
  const s = document.createElement('style');
  s.id = 'sh-btn-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Button({
  variant = 'primary',
  size = 'md',
  leadingIcon = null,
  trailingIcon = null,
  fullWidth = false,
  disabled = false,
  type = 'button',
  children,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("button", _extends({
    type: type,
    className: "sh-btn",
    "data-variant": variant,
    "data-size": size,
    "data-full": fullWidth ? 'true' : undefined,
    disabled: disabled
  }, rest), leadingIcon, children != null && /*#__PURE__*/React.createElement("span", null, children), trailingIcon);
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Button.jsx", error: String((e && e.message) || e) }); }

// components/forms/Checkbox.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Checkbox: custom-styled checkbox with label. */

const CSS = `
.sh-check{ display:inline-flex; align-items:flex-start; gap:10px; cursor:pointer; font-family:var(--font-sans); font-size:var(--text-base); color:var(--text-body); user-select:none; }
.sh-check input{ position:absolute; opacity:0; width:0; height:0; }
.sh-check-box{
  flex:none; width:18px; height:18px; margin-top:1px; border-radius:var(--radius-xs);
  border:1.5px solid var(--border-strong); background:var(--surface-card);
  display:flex; align-items:center; justify-content:center; color:#fff;
  transition: background var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out);
}
.sh-check:hover input:not(:disabled) ~ .sh-check-box{ border-color:var(--brand); }
.sh-check input:checked ~ .sh-check-box{ background:var(--brand); border-color:var(--brand); }
.sh-check input:indeterminate ~ .sh-check-box{ background:var(--brand); border-color:var(--brand); }
.sh-check input:focus-visible ~ .sh-check-box{ box-shadow:var(--focus-ring); }
.sh-check input:disabled ~ .sh-check-box{ opacity:.5; }
.sh-check[data-disabled="true"]{ cursor:not-allowed; color:var(--text-muted); }
.sh-check-box svg{ width:13px; height:13px; opacity:0; transition:opacity var(--dur-fast); }
.sh-check input:checked ~ .sh-check-box svg.tick,
.sh-check input:indeterminate ~ .sh-check-box svg.dash{ opacity:1; }
.sh-check-label-sub{ display:block; font-size:var(--text-sm); color:var(--text-muted); margin-top:1px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-check-css')) {
  const s = document.createElement('style');
  s.id = 'sh-check-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Checkbox({
  label,
  description,
  indeterminate = false,
  disabled = false,
  id,
  ...rest
}) {
  const ref = React.useRef(null);
  React.useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);
  return /*#__PURE__*/React.createElement("label", {
    className: "sh-check",
    "data-disabled": disabled ? 'true' : undefined
  }, /*#__PURE__*/React.createElement("input", _extends({
    ref: ref,
    type: "checkbox",
    id: id,
    disabled: disabled
  }, rest)), /*#__PURE__*/React.createElement("span", {
    className: "sh-check-box"
  }, /*#__PURE__*/React.createElement("svg", {
    className: "tick",
    viewBox: "0 0 16 16",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2.4",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M3.5 8.5l3 3 6-7"
  })), /*#__PURE__*/React.createElement("svg", {
    className: "dash",
    viewBox: "0 0 16 16",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2.4",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M4 8h8"
  }))), (label || description) && /*#__PURE__*/React.createElement("span", null, label, description && /*#__PURE__*/React.createElement("span", {
    className: "sh-check-label-sub"
  }, description)));
}
Object.assign(__ds_scope, { Checkbox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Checkbox.jsx", error: String((e && e.message) || e) }); }

// components/forms/IconButton.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — IconButton: square, icon-only action. */

const CSS = `
.sh-iconbtn{
  --_s: 38px;
  display:inline-flex; align-items:center; justify-content:center;
  width:var(--_s); height:var(--_s); padding:0; flex:none;
  border:1px solid transparent; border-radius:var(--radius-md);
  background:transparent; color:var(--text-body); cursor:pointer;
  transition: background var(--dur-fast) var(--ease-out), color var(--dur-fast) var(--ease-out), border-color var(--dur-fast) var(--ease-out);
  -webkit-tap-highlight-color:transparent;
}
.sh-iconbtn[data-size="sm"]{ --_s:30px; }
.sh-iconbtn[data-size="lg"]{ --_s:46px; }
.sh-iconbtn:hover:not(:disabled){ background:var(--surface-hover); color:var(--text-strong); }
.sh-iconbtn:focus-visible{ outline:none; box-shadow:var(--focus-ring); }
.sh-iconbtn:disabled{ opacity:.45; cursor:not-allowed; }
.sh-iconbtn[data-variant="outline"]{ border-color:var(--border-default); background:var(--surface-card); box-shadow:var(--shadow-xs); }
.sh-iconbtn[data-variant="solid"]{ background:var(--brand); color:#fff; }
.sh-iconbtn[data-variant="solid"]:hover:not(:disabled){ background:var(--brand-hover); color:#fff; }
.sh-iconbtn svg{ width:1.15em; height:1.15em; }
.sh-iconbtn{ font-size:18px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-iconbtn-css')) {
  const s = document.createElement('style');
  s.id = 'sh-iconbtn-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function IconButton({
  variant = 'ghost',
  size = 'md',
  label,
  disabled = false,
  children,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("button", _extends({
    type: "button",
    className: "sh-iconbtn",
    "data-variant": variant,
    "data-size": size,
    "aria-label": label,
    title: label,
    disabled: disabled
  }, rest), children);
}
Object.assign(__ds_scope, { IconButton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/IconButton.jsx", error: String((e && e.message) || e) }); }

// components/forms/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Input: text field with optional label, icon, hint/error. */

const CSS = `
.sh-field{ display:flex; flex-direction:column; gap:6px; font-family:var(--font-sans); }
.sh-field-label{ font-size:var(--text-sm); font-weight:600; color:var(--text-strong); }
.sh-field-label .req{ color:var(--red-600); margin-left:2px; }
.sh-input-wrap{ position:relative; display:flex; align-items:center; }
.sh-input{
  width:100%; height:var(--control-h-md); padding:0 12px;
  font-family:inherit; font-size:var(--text-base); color:var(--text-strong);
  background:var(--surface-card); border:1px solid var(--border-default);
  border-radius:var(--radius-md); outline:none;
  transition: border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
}
.sh-input::placeholder{ color:var(--text-subtle); }
.sh-input:hover:not(:disabled){ border-color:var(--border-strong); }
.sh-input:focus{ border-color:var(--border-focus); box-shadow:var(--focus-ring); }
.sh-input:disabled{ background:var(--surface-sunken); color:var(--text-muted); cursor:not-allowed; }
.sh-input[data-has-icon="true"]{ padding-left:38px; }
.sh-input-icon{ position:absolute; left:12px; display:flex; color:var(--text-subtle); font-size:16px; pointer-events:none; }
.sh-input-icon svg{ width:16px; height:16px; }
.sh-field[data-invalid="true"] .sh-input{ border-color:var(--red-500); }
.sh-field[data-invalid="true"] .sh-input:focus{ box-shadow:0 0 0 3px color-mix(in srgb, var(--red-500) 30%, transparent); }
.sh-field-hint{ font-size:var(--text-xs); color:var(--text-muted); }
.sh-field[data-invalid="true"] .sh-field-hint{ color:var(--red-700); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-input-css')) {
  const s = document.createElement('style');
  s.id = 'sh-input-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Input({
  label,
  icon = null,
  hint,
  error,
  required = false,
  id,
  ...rest
}) {
  const fieldId = id || (label ? `f-${label.replace(/\s+/g, '-').toLowerCase()}` : undefined);
  const invalid = Boolean(error);
  return /*#__PURE__*/React.createElement("div", {
    className: "sh-field",
    "data-invalid": invalid ? 'true' : undefined
  }, label && /*#__PURE__*/React.createElement("label", {
    className: "sh-field-label",
    htmlFor: fieldId
  }, label, required && /*#__PURE__*/React.createElement("span", {
    className: "req"
  }, "*")), /*#__PURE__*/React.createElement("div", {
    className: "sh-input-wrap"
  }, icon && /*#__PURE__*/React.createElement("span", {
    className: "sh-input-icon"
  }, icon), /*#__PURE__*/React.createElement("input", _extends({
    id: fieldId,
    className: "sh-input",
    "data-has-icon": icon ? 'true' : undefined,
    "aria-invalid": invalid || undefined
  }, rest))), (error || hint) && /*#__PURE__*/React.createElement("span", {
    className: "sh-field-hint"
  }, error || hint));
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Input.jsx", error: String((e && e.message) || e) }); }

// components/forms/Select.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Select: styled native select with chevron + label/hint. */

const CSS = `
.sh-select-field{ display:flex; flex-direction:column; gap:6px; font-family:var(--font-sans); }
.sh-select-label{ font-size:var(--text-sm); font-weight:600; color:var(--text-strong); }
.sh-select-wrap{ position:relative; display:flex; align-items:center; }
.sh-select{
  appearance:none; -webkit-appearance:none; width:100%;
  height:var(--control-h-md); padding:0 36px 0 12px;
  font-family:inherit; font-size:var(--text-base); color:var(--text-strong);
  background:var(--surface-card); border:1px solid var(--border-default);
  border-radius:var(--radius-md); outline:none; cursor:pointer;
  transition: border-color var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
}
.sh-select:hover:not(:disabled){ border-color:var(--border-strong); }
.sh-select:focus{ border-color:var(--border-focus); box-shadow:var(--focus-ring); }
.sh-select:disabled{ background:var(--surface-sunken); color:var(--text-muted); cursor:not-allowed; }
.sh-select-chevron{ position:absolute; right:12px; pointer-events:none; color:var(--text-muted); display:flex; }
.sh-select-chevron svg{ width:16px; height:16px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-select-css')) {
  const s = document.createElement('style');
  s.id = 'sh-select-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Chevron() {
  return /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 16 16",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.6",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M4 6l4 4 4-4"
  }));
}
function Select({
  label,
  hint,
  options = [],
  id,
  children,
  ...rest
}) {
  const fieldId = id || (label ? `s-${label.replace(/\s+/g, '-').toLowerCase()}` : undefined);
  return /*#__PURE__*/React.createElement("div", {
    className: "sh-select-field"
  }, label && /*#__PURE__*/React.createElement("label", {
    className: "sh-select-label",
    htmlFor: fieldId
  }, label), /*#__PURE__*/React.createElement("div", {
    className: "sh-select-wrap"
  }, /*#__PURE__*/React.createElement("select", _extends({
    id: fieldId,
    className: "sh-select"
  }, rest), children || options.map(o => {
    const value = typeof o === 'string' ? o : o.value;
    const text = typeof o === 'string' ? o : o.label;
    return /*#__PURE__*/React.createElement("option", {
      key: value,
      value: value
    }, text);
  })), /*#__PURE__*/React.createElement("span", {
    className: "sh-select-chevron"
  }, /*#__PURE__*/React.createElement(Chevron, null))), hint && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)'
    }
  }, hint));
}
Object.assign(__ds_scope, { Select });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Select.jsx", error: String((e && e.message) || e) }); }

// components/forms/Switch.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Switch: on/off toggle. */

const CSS = `
.sh-switch{ display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-family:var(--font-sans); font-size:var(--text-base); color:var(--text-body); user-select:none; }
.sh-switch input{ position:absolute; opacity:0; width:0; height:0; }
.sh-switch-track{
  position:relative; flex:none; width:38px; height:22px; border-radius:var(--radius-pill);
  background:var(--gray-300); transition: background var(--dur-base) var(--ease-out);
}
.sh-switch-thumb{
  position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%;
  background:#fff; box-shadow:var(--shadow-sm);
  transition: transform var(--dur-base) var(--ease-out);
}
.sh-switch input:checked ~ .sh-switch-track{ background:var(--brand); }
.sh-switch input:checked ~ .sh-switch-track .sh-switch-thumb{ transform:translateX(16px); }
.sh-switch input:focus-visible ~ .sh-switch-track{ box-shadow:var(--focus-ring); }
.sh-switch[data-disabled="true"]{ cursor:not-allowed; opacity:.5; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-switch-css')) {
  const s = document.createElement('style');
  s.id = 'sh-switch-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Switch({
  label,
  disabled = false,
  id,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("label", {
    className: "sh-switch",
    "data-disabled": disabled ? 'true' : undefined
  }, /*#__PURE__*/React.createElement("input", _extends({
    type: "checkbox",
    role: "switch",
    id: id,
    disabled: disabled
  }, rest)), /*#__PURE__*/React.createElement("span", {
    className: "sh-switch-track"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-switch-thumb"
  })), label && /*#__PURE__*/React.createElement("span", null, label));
}
Object.assign(__ds_scope, { Switch });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Switch.jsx", error: String((e && e.message) || e) }); }

// components/mobile/AppBar.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — AppBar: mobile top bar with brand-green title and trailing actions. */

const CSS = `
.sh-appbar{
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  height:var(--appbar-height); padding:0 var(--screen-gutter);
  background:var(--surface-card); border-bottom:1px solid var(--border-subtle);
  font-family:var(--font-sans);
}
.sh-appbar-title{ display:flex; align-items:center; gap:8px; font-size:var(--text-xl); font-weight:700; color:var(--brand); letter-spacing:var(--tracking-tight); }
.sh-appbar-back{ display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; margin-left:-6px; border:none; background:none; color:var(--text-muted); cursor:pointer; border-radius:var(--radius-sm); }
.sh-appbar-back:hover{ background:var(--surface-hover); color:var(--text-strong); }
.sh-appbar-back svg{ width:20px; height:20px; }
.sh-appbar-actions{ display:flex; align-items:center; gap:6px; }
.sh-appbar-icon{ position:relative; display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border:none; background:none; color:var(--text-muted); cursor:pointer; border-radius:var(--radius-pill); }
.sh-appbar-icon:hover{ background:var(--surface-hover); color:var(--text-strong); }
.sh-appbar-icon svg, .sh-appbar-icon i{ width:22px; height:22px; }
.sh-appbar-badge{ position:absolute; top:5px; right:5px; min-width:16px; height:16px; padding:0 4px; border-radius:var(--radius-pill); background:var(--red-600); color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; border:2px solid var(--surface-card); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-appbar-css')) {
  const s = document.createElement('style');
  s.id = 'sh-appbar-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function AppBar({
  title,
  onBack,
  notificationCount,
  onNotifications,
  onProfile,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("header", _extends({
    className: "sh-appbar"
  }, rest), /*#__PURE__*/React.createElement("div", {
    className: "sh-appbar-title"
  }, onBack && /*#__PURE__*/React.createElement("button", {
    className: "sh-appbar-back",
    "aria-label": "Retour",
    onClick: onBack
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M15 18l-6-6 6-6"
  }))), title), /*#__PURE__*/React.createElement("div", {
    className: "sh-appbar-actions"
  }, /*#__PURE__*/React.createElement("button", {
    className: "sh-appbar-icon",
    "aria-label": "Notifications",
    onClick: onNotifications
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"
  })), notificationCount > 0 && /*#__PURE__*/React.createElement("span", {
    className: "sh-appbar-badge"
  }, notificationCount)), /*#__PURE__*/React.createElement("button", {
    className: "sh-appbar-icon",
    "aria-label": "Profil",
    onClick: onProfile
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "8",
    r: "4"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M4 21a8 8 0 0 1 16 0"
  })))));
}
Object.assign(__ds_scope, { AppBar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/AppBar.jsx", error: String((e && e.message) || e) }); }

// components/mobile/BottomNav.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — BottomNav: the primary mobile navigation. */

const CSS = `
.sh-bottomnav{
  display:flex; align-items:stretch; justify-content:space-around;
  height:var(--bottomnav-height); background:var(--surface-card);
  border-top:1px solid var(--border-subtle); box-shadow:var(--shadow-nav);
  padding-bottom:env(safe-area-inset-bottom, 0px);
}
.sh-bottomnav-item{
  position:relative; flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px;
  border:none; background:none; cursor:pointer; color:var(--text-subtle);
  font-family:var(--font-sans); font-size:var(--text-2xs); font-weight:600;
  transition: color var(--dur-fast) var(--ease-out); min-width:var(--tap-min);
}
.sh-bottomnav-item:hover{ color:var(--text-muted); }
.sh-bottomnav-item[data-active="true"]{ color:var(--brand); }
.sh-bottomnav-ic{ position:relative; display:flex; }
.sh-bottomnav-ic svg, .sh-bottomnav-ic i{ width:24px; height:24px; }
.sh-bottomnav-badge{ position:absolute; top:-5px; right:-9px; min-width:16px; height:16px; padding:0 4px; border-radius:var(--radius-pill); background:var(--red-600); color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; border:2px solid var(--surface-card); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-bottomnav-css')) {
  const s = document.createElement('style');
  s.id = 'sh-bottomnav-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function BottomNav({
  items = [],
  value,
  onChange,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("nav", _extends({
    className: "sh-bottomnav"
  }, rest), items.map(item => /*#__PURE__*/React.createElement("button", {
    key: item.id,
    className: "sh-bottomnav-item",
    "data-active": value === item.id ? 'true' : undefined,
    "aria-current": value === item.id ? 'page' : undefined,
    onClick: () => onChange && onChange(item.id)
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-bottomnav-ic"
  }, item.icon, item.badge > 0 && /*#__PURE__*/React.createElement("span", {
    className: "sh-bottomnav-badge"
  }, item.badge)), item.label)));
}
Object.assign(__ds_scope, { BottomNav });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/BottomNav.jsx", error: String((e && e.message) || e) }); }

// components/mobile/EmptyState.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — EmptyState: centered placeholder with optional icon and action. */

const CSS = `
.sh-empty{
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  text-align:center; gap:8px; padding:28px 18px; font-family:var(--font-sans);
}
.sh-empty[data-boxed="true"]{ background:var(--surface-card); border:1px solid var(--border-subtle); border-radius:var(--radius-md); }
.sh-empty-ic{ width:34px; height:34px; color:var(--gray-300); display:flex; margin-bottom:2px; }
.sh-empty-ic svg, .sh-empty-ic i{ width:34px; height:34px; }
.sh-empty-title{ font-size:var(--text-md); font-weight:600; color:var(--text-muted); }
.sh-empty-text{ font-size:var(--text-sm); color:var(--text-subtle); max-width:34ch; }
.sh-empty-action{ margin-top:8px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-empty-css')) {
  const s = document.createElement('style');
  s.id = 'sh-empty-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function EmptyState({
  icon,
  title,
  text,
  action,
  boxed = false,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-empty",
    "data-boxed": boxed ? 'true' : undefined
  }, rest), icon && /*#__PURE__*/React.createElement("span", {
    className: "sh-empty-ic"
  }, icon), title && /*#__PURE__*/React.createElement("span", {
    className: "sh-empty-title"
  }, title), text && /*#__PURE__*/React.createElement("span", {
    className: "sh-empty-text"
  }, text), action && /*#__PURE__*/React.createElement("span", {
    className: "sh-empty-action"
  }, action));
}
Object.assign(__ds_scope, { EmptyState });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/EmptyState.jsx", error: String((e && e.message) || e) }); }

// components/mobile/InfoBanner.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — InfoBanner: a soft tonal banner for guidance / status notes. */

const CSS = `
.sh-infobanner{
  display:flex; align-items:flex-start; gap:10px; padding:12px 14px;
  border-radius:var(--radius-md); font-family:var(--font-sans);
  font-size:var(--text-sm); line-height:1.45;
}
.sh-infobanner-ic{ flex:none; width:18px; height:18px; margin-top:1px; display:flex; }
.sh-infobanner-ic svg{ width:18px; height:18px; }
.sh-infobanner[data-tone="info"]{ background:var(--blue-50); color:var(--blue-700); }
.sh-infobanner[data-tone="info"] .sh-infobanner-ic{ color:var(--blue-600); }
.sh-infobanner[data-tone="warning"]{ background:var(--amber-50); color:var(--amber-700); }
.sh-infobanner[data-tone="warning"] .sh-infobanner-ic{ color:var(--amber-600); }
.sh-infobanner[data-tone="success"]{ background:var(--green-50); color:var(--green-700); }
.sh-infobanner[data-tone="success"] .sh-infobanner-ic{ color:var(--green-600); }
.sh-infobanner-body{ flex:1; min-width:0; }
.sh-infobanner-body b{ font-weight:700; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-infobanner-css')) {
  const s = document.createElement('style');
  s.id = 'sh-infobanner-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
const ICONS = {
  info: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "9"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M12 11v5M12 8h.01"
  })),
  warning: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("path", {
    d: "M12 3l9 16H3z"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M12 10v4M12 17h.01"
  })),
  success: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "9"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M8 12l3 3 5-6"
  }))
};
function InfoBanner({
  tone = 'info',
  children,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-infobanner",
    "data-tone": tone
  }, rest), /*#__PURE__*/React.createElement("span", {
    className: "sh-infobanner-ic"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, ICONS[tone] || ICONS.info)), /*#__PURE__*/React.createElement("div", {
    className: "sh-infobanner-body"
  }, children));
}
Object.assign(__ds_scope, { InfoBanner });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/InfoBanner.jsx", error: String((e && e.message) || e) }); }

// components/mobile/ListRow.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — ListRow: a tappable settings/detail row with leading icon and chevron. */

const CSS = `
.sh-listrow{
  display:flex; align-items:center; gap:13px; width:100%; text-align:left;
  background:var(--surface-card); border:none; cursor:pointer;
  padding:14px var(--screen-gutter); font-family:var(--font-sans);
  transition: background var(--dur-fast) var(--ease-out);
}
.sh-listrow:hover{ background:var(--surface-hover); }
.sh-listrow:active{ background:var(--surface-active); }
.sh-listrow + .sh-listrow{ border-top:1px solid var(--border-subtle); }
.sh-listrow-ic{ flex:none; display:flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:var(--radius-md); background:var(--brand-subtle); color:var(--brand); }
.sh-listrow-ic svg, .sh-listrow-ic i{ width:20px; height:20px; }
.sh-listrow-ic[data-plain="true"]{ background:transparent; color:var(--text-muted); width:24px; height:24px; }
.sh-listrow-body{ flex:1; min-width:0; display:flex; flex-direction:column; gap:1px; }
.sh-listrow-title{ font-size:var(--text-md); font-weight:600; color:var(--text-strong); }
.sh-listrow-sub{ font-size:var(--text-sm); color:var(--text-muted); }
.sh-listrow-value{ font-size:var(--text-sm); color:var(--text-muted); font-weight:500; white-space:nowrap; }
.sh-listrow-chev{ display:flex; color:var(--text-subtle); flex:none; }
.sh-listrow-chev svg{ width:20px; height:20px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-listrow-css')) {
  const s = document.createElement('style');
  s.id = 'sh-listrow-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function ListRow({
  icon,
  plainIcon = false,
  title,
  subtitle,
  value,
  chevron = true,
  onClick,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("button", _extends({
    className: "sh-listrow",
    onClick: onClick
  }, rest), icon && /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-ic",
    "data-plain": plainIcon ? 'true' : undefined
  }, icon), /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-body"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-title"
  }, title), subtitle && /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-sub"
  }, subtitle)), value && /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-value"
  }, value), chevron && /*#__PURE__*/React.createElement("span", {
    className: "sh-listrow-chev"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M9 6l6 6-6 6"
  }))));
}
Object.assign(__ds_scope, { ListRow });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/ListRow.jsx", error: String((e && e.message) || e) }); }

// components/mobile/MissionCard.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — MissionCard: a tappable mission summary card for lists
   (Mes missions, history). Presentational — pass a <StatusPill> as `status`. */

const CSS = `
.sh-missioncard{
  display:flex; align-items:stretch; gap:12px; width:100%; text-align:left; cursor:pointer;
  background:var(--surface-card); border:1px solid var(--border-subtle);
  border-radius:var(--radius-md); padding:14px; font-family:var(--font-sans);
  transition: box-shadow var(--dur-base) var(--ease-out), border-color var(--dur-base) var(--ease-out), transform var(--dur-fast) var(--ease-out);
}
.sh-missioncard:hover{ box-shadow:var(--shadow-sm); border-color:var(--border-default); }
.sh-missioncard:active{ transform:scale(0.995); }
.sh-missioncard-accent{ width:3px; flex:none; border-radius:var(--radius-pill); background:var(--brand); align-self:stretch; }
.sh-missioncard-accent[data-tone="info"]{ background:var(--blue-500); }
.sh-missioncard-accent[data-tone="warn"]{ background:var(--amber-500); }
.sh-missioncard-accent[data-tone="muted"]{ background:var(--gray-300); }
.sh-missioncard-body{ flex:1; min-width:0; display:flex; flex-direction:column; gap:7px; }
.sh-missioncard-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.sh-missioncard-site{ font-size:var(--text-md); font-weight:700; color:var(--text-strong); line-height:1.25; }
.sh-missioncard-when{ display:flex; align-items:center; gap:8px; font-family:var(--font-data); font-variant-numeric:tabular-nums; font-size:var(--text-sm); color:var(--text-muted); }
.sh-missioncard-when b{ color:var(--text-body); font-weight:600; }
.sh-missioncard-tags{ display:flex; flex-wrap:wrap; gap:6px; }
.sh-missioncard-chev{ display:flex; align-items:center; color:var(--text-subtle); flex:none; }
.sh-missioncard-chev svg{ width:20px; height:20px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-missioncard-css')) {
  const s = document.createElement('style');
  s.id = 'sh-missioncard-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function MissionCard({
  site,
  date,
  time,
  status,
  accentTone = 'brand',
  tags,
  onClick,
  chevron = true,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("button", _extends({
    className: "sh-missioncard",
    onClick: onClick
  }, rest), /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-accent",
    "data-tone": accentTone
  }), /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-body"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-top"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-site"
  }, site), status), /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-when"
  }, date && /*#__PURE__*/React.createElement("b", null, date), date && time && /*#__PURE__*/React.createElement("span", {
    "aria-hidden": "true"
  }, "\xB7"), time && /*#__PURE__*/React.createElement("span", null, time)), tags && /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-tags"
  }, tags)), chevron && /*#__PURE__*/React.createElement("span", {
    className: "sh-missioncard-chev"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M9 6l6 6-6 6"
  }))));
}
Object.assign(__ds_scope, { MissionCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/MissionCard.jsx", error: String((e && e.message) || e) }); }

// components/mobile/MissionHero.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — MissionHero: the featured "mission du jour" card.
   Blue gradient surface with a glassy time panel, live status, and CTA. */

const CSS = `
.sh-hero{
  position:relative; overflow:hidden; border-radius:var(--radius-lg);
  background:
    radial-gradient(120% 90% at 100% 0%, color-mix(in srgb, var(--green-400) 34%, transparent), transparent 52%),
    linear-gradient(150deg, var(--blue-600), var(--blue-700) 64%, #143b86);
  color:#fff; padding:18px; font-family:var(--font-sans);
  box-shadow:0 10px 26px color-mix(in srgb, var(--blue-700) 30%, transparent);
}
.sh-hero::after{
  content:""; position:absolute; right:-60px; bottom:-72px; width:200px; height:200px;
  border-radius:50%; background:rgba(255,255,255,0.06); pointer-events:none;
}
.sh-hero > *{ position:relative; z-index:1; }

.sh-hero-top{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px; }
.sh-hero-eyebrow{ display:inline-flex; align-items:center; gap:7px; font-size:var(--text-2xs); letter-spacing:var(--tracking-caps); text-transform:uppercase; font-weight:700; color:rgba(255,255,255,0.86); }
.sh-hero-live{ width:8px; height:8px; border-radius:50%; background:#fff; box-shadow:0 0 0 0 rgba(255,255,255,0.55); animation:sh-hero-pulse 1.9s var(--ease-inout) infinite; }
@keyframes sh-hero-pulse{ 0%{ box-shadow:0 0 0 0 rgba(255,255,255,0.5); } 70%{ box-shadow:0 0 0 7px rgba(255,255,255,0); } 100%{ box-shadow:0 0 0 0 rgba(255,255,255,0); } }
@media (prefers-reduced-motion: reduce){ .sh-hero-live{ animation:none; } }
.sh-hero-badge{ display:inline-flex; align-items:center; gap:6px; font-size:var(--text-2xs); font-weight:700; padding:5px 10px; border-radius:var(--radius-pill); background:rgba(255,255,255,0.18); color:#fff; white-space:nowrap; backdrop-filter:blur(4px); border:1px solid rgba(255,255,255,0.16); }

.sh-hero-site{ font-size:var(--text-xl); font-weight:800; letter-spacing:var(--tracking-snug); line-height:1.2; }
.sh-hero-sub{ font-size:var(--text-sm); font-weight:500; color:rgba(255,255,255,0.78); margin-top:3px; }

.sh-hero-panel{
  display:flex; align-items:center; gap:10px; margin:15px 0;
  padding:13px 15px; border-radius:var(--radius-md);
  background:rgba(255,255,255,0.13); border:1px solid rgba(255,255,255,0.18);
  backdrop-filter:blur(6px);
}
.sh-hero-slot{ display:flex; flex-direction:column; gap:3px; }
.sh-hero-slot[data-align="end"]{ align-items:flex-end; text-align:right; }
.sh-hero-slot-label{ font-size:9px; letter-spacing:var(--tracking-caps); text-transform:uppercase; font-weight:700; color:rgba(255,255,255,0.66); }
.sh-hero-slot-time{ font-family:var(--font-data); font-variant-numeric:tabular-nums; font-size:var(--text-2xl); font-weight:800; letter-spacing:-0.02em; line-height:1; }
.sh-hero-mid{ flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
.sh-hero-dur{ font-size:var(--text-2xs); font-weight:700; color:rgba(255,255,255,0.9); white-space:nowrap; }
.sh-hero-track{ position:relative; width:100%; height:2px; border-radius:2px; background:rgba(255,255,255,0.28); }
.sh-hero-track::before{ content:""; position:absolute; inset:0; right:42%; background:#fff; border-radius:2px; }
.sh-hero-track::after{ content:""; position:absolute; left:58%; top:50%; width:7px; height:7px; border-radius:50%; background:#fff; transform:translate(-50%,-50%); box-shadow:0 0 0 3px color-mix(in srgb, var(--blue-700) 55%, transparent); }

/* fallback when only a single time string is provided */
.sh-hero-time{ font-family:var(--font-data); font-variant-numeric:tabular-nums; font-size:var(--text-3xl); font-weight:800; letter-spacing:-0.02em; line-height:1.05; margin:12px 0; }

.sh-hero-meta{ display:flex; flex-direction:column; gap:9px; margin-bottom:16px; }
.sh-hero-metarow{ display:flex; align-items:center; gap:9px; font-size:var(--text-base); color:rgba(255,255,255,0.95); }
.sh-hero-metarow svg{ width:16px; height:16px; flex:none; opacity:.8; }

.sh-hero-actions{ display:flex; flex-direction:column; gap:9px; }
.sh-hero-cta{
  display:flex; align-items:center; justify-content:center; gap:8px; width:100%;
  height:var(--control-h-md); border:none; border-radius:var(--radius-md); cursor:pointer;
  background:#fff; color:var(--blue-700); font-family:inherit; font-size:var(--text-md); font-weight:700;
  transition: transform var(--dur-fast) var(--ease-out), box-shadow var(--dur-fast) var(--ease-out);
  box-shadow:0 4px 12px rgba(0,0,0,0.14);
}
.sh-hero-cta:hover{ box-shadow:0 6px 16px rgba(0,0,0,0.18); }
.sh-hero-cta:active{ transform:translateY(1px); box-shadow:0 2px 8px rgba(0,0,0,0.14); }
.sh-hero-cta svg{ width:18px; height:18px; }
.sh-hero-ghost{
  display:flex; align-items:center; justify-content:center; gap:6px; width:100%; height:40px;
  border:1px solid rgba(255,255,255,0.34); border-radius:var(--radius-md); cursor:pointer;
  background:transparent; color:#fff; font-family:inherit; font-size:var(--text-sm); font-weight:600;
  transition: background var(--dur-fast) var(--ease-out);
}
.sh-hero-ghost:hover{ background:rgba(255,255,255,0.12); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-hero-css')) {
  const s = document.createElement('style');
  s.id = 'sh-hero-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function splitTime(time) {
  if (!time) return null;
  const m = String(time).split(/→|->|—|-/).map(x => x.trim());
  if (m.length === 2 && m[0] && m[1]) return {
    start: m[0],
    end: m[1]
  };
  return null;
}
function MissionHero({
  eyebrow = 'Mission du jour',
  badge,
  live = true,
  time,
  start,
  end,
  duration,
  site,
  specialty,
  surgeon,
  location,
  ctaLabel = 'Encoder la mission',
  onCta,
  detailLabel = 'Voir le détail',
  onDetail,
  ...rest
}) {
  const parsed = splitTime(time);
  const s = start || parsed && parsed.start;
  const e = end || parsed && parsed.end;
  return /*#__PURE__*/React.createElement("section", _extends({
    className: "sh-hero"
  }, rest), /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-top"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-eyebrow"
  }, live && /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-live"
  }), eyebrow), badge && /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-badge"
  }, badge)), site && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-site"
  }, site), specialty && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-sub"
  }, specialty), s && e ? /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-panel"
  }, /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-slot"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-slot-label"
  }, "D\xE9but"), /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-slot-time"
  }, s)), /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-mid"
  }, duration && /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-dur"
  }, duration), /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-track"
  })), /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-slot",
    "data-align": "end"
  }, /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-slot-label"
  }, "Fin"), /*#__PURE__*/React.createElement("span", {
    className: "sh-hero-slot-time"
  }, e))) : time && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-time"
  }, time), (surgeon || location) && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-meta"
  }, surgeon && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-metarow"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.9",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M19 14V7a3 3 0 0 0-3-3M9 4a3 3 0 0 0-3 3v3a6 6 0 0 0 12 0"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "19",
    cy: "16",
    r: "2"
  })), surgeon), location && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-metarow"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.9",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "10",
    r: "3"
  })), location)), (onCta || onDetail) && /*#__PURE__*/React.createElement("div", {
    className: "sh-hero-actions"
  }, onCta && /*#__PURE__*/React.createElement("button", {
    className: "sh-hero-cta",
    onClick: onCta
  }, ctaLabel, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2.2",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M5 12h14M13 6l6 6-6 6"
  }))), onDetail && /*#__PURE__*/React.createElement("button", {
    className: "sh-hero-ghost",
    onClick: onDetail
  }, detailLabel)));
}
Object.assign(__ds_scope, { MissionHero });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/MissionHero.jsx", error: String((e && e.message) || e) }); }

// components/mobile/OfferCard.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — OfferCard: an available mission offer to accept/decline.
   Site name in brand green, date emphasis, time row, action slot. */

const CSS = `
.sh-offercard{
  display:flex; flex-direction:column; gap:8px;
  background:var(--surface-card); border:1px solid var(--border-subtle);
  border-radius:var(--radius-md); padding:14px 16px; font-family:var(--font-sans);
  box-shadow:var(--shadow-xs);
}
.sh-offercard-site{ display:flex; align-items:center; gap:6px; font-size:var(--text-sm); font-weight:700; color:var(--brand); }
.sh-offercard-site svg{ width:15px; height:15px; }
.sh-offercard-date{ font-size:var(--text-lg); font-weight:700; color:var(--text-strong); letter-spacing:var(--tracking-snug); }
.sh-offercard-row{ display:flex; align-items:center; gap:7px; font-family:var(--font-data); font-variant-numeric:tabular-nums; font-size:var(--text-sm); color:var(--text-muted); }
.sh-offercard-row svg{ width:15px; height:15px; color:var(--text-subtle); }
.sh-offercard-tags{ display:flex; flex-wrap:wrap; gap:6px; margin-top:1px; }
.sh-offercard-actions{ display:flex; gap:8px; margin-top:6px; }
.sh-offercard-actions > *{ flex:1; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-offercard-css')) {
  const s = document.createElement('style');
  s.id = 'sh-offercard-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function OfferCard({
  site,
  date,
  time,
  tags,
  actions,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("article", _extends({
    className: "sh-offercard"
  }, rest), /*#__PURE__*/React.createElement("div", {
    className: "sh-offercard-site"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M3 21h18M6 21V7l6-4 6 4v14M10 9h.01M14 9h.01M10 13h.01M14 13h.01"
  })), site), date && /*#__PURE__*/React.createElement("div", {
    className: "sh-offercard-date"
  }, date), time && /*#__PURE__*/React.createElement("div", {
    className: "sh-offercard-row"
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.9",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, /*#__PURE__*/React.createElement("circle", {
    cx: "12",
    cy: "12",
    r: "9"
  }), /*#__PURE__*/React.createElement("path", {
    d: "M12 7v5l3 2"
  })), time), tags && /*#__PURE__*/React.createElement("div", {
    className: "sh-offercard-tags"
  }, tags), actions && /*#__PURE__*/React.createElement("div", {
    className: "sh-offercard-actions"
  }, actions));
}
Object.assign(__ds_scope, { OfferCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/OfferCard.jsx", error: String((e && e.message) || e) }); }

// components/mobile/SectionHeader.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* Surgery Hub — SectionHeader: a list/section title with an optional trailing action. */

const CSS = `
.sh-sectionheader{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
.sh-sectionheader-title{ font-size:var(--text-lg); font-weight:700; color:var(--text-strong); letter-spacing:var(--tracking-snug); font-family:var(--font-sans); }
.sh-sectionheader-sub{ font-size:var(--text-sm); color:var(--text-muted); font-weight:500; margin-left:8px; }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-sectionheader-css')) {
  const s = document.createElement('style');
  s.id = 'sh-sectionheader-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function SectionHeader({
  title,
  count,
  action,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-sectionheader"
  }, rest), /*#__PURE__*/React.createElement("div", {
    className: "sh-sectionheader-title"
  }, title, count != null && /*#__PURE__*/React.createElement("span", {
    className: "sh-sectionheader-sub"
  }, count)), action);
}
Object.assign(__ds_scope, { SectionHeader });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/mobile/SectionHeader.jsx", error: String((e && e.message) || e) }); }

// components/navigation/Tabs.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/* SurgeryHub — Tabs: underline-style segmented navigation. */

const CSS = `
.sh-tabs{ display:flex; align-items:center; gap:2px; border-bottom:1px solid var(--border-subtle); font-family:var(--font-sans); }
.sh-tab{
  position:relative; appearance:none; background:none; border:none; cursor:pointer;
  padding:10px 14px; font-size:var(--text-base); font-weight:500; color:var(--text-muted);
  display:inline-flex; align-items:center; gap:7px; white-space:nowrap;
  transition: color var(--dur-fast) var(--ease-out);
}
.sh-tab::after{
  content:""; position:absolute; left:10px; right:10px; bottom:-1px; height:2px;
  background:var(--brand); border-radius:2px 2px 0 0; transform:scaleX(0);
  transition: transform var(--dur-base) var(--ease-out);
}
.sh-tab:hover{ color:var(--text-body); }
.sh-tab[data-active="true"]{ color:var(--brand); font-weight:600; }
.sh-tab[data-active="true"]::after{ transform:scaleX(1); }
.sh-tab:focus-visible{ outline:none; box-shadow:var(--focus-ring); border-radius:var(--radius-xs); }
.sh-tab-count{ font-family:var(--font-data); font-size:var(--text-2xs); font-variant-numeric:tabular-nums; background:var(--gray-100); color:var(--text-muted); padding:1px 6px; border-radius:var(--radius-pill); }
.sh-tab[data-active="true"] .sh-tab-count{ background:var(--brand-subtle); color:var(--green-700); }
`;
if (typeof document !== 'undefined' && !document.getElementById('sh-tabs-css')) {
  const s = document.createElement('style');
  s.id = 'sh-tabs-css';
  s.textContent = CSS;
  document.head.appendChild(s);
}
function Tabs({
  tabs = [],
  value,
  defaultValue,
  onChange,
  ...rest
}) {
  const isControlled = value !== undefined;
  const [internal, setInternal] = React.useState(defaultValue ?? (tabs[0] && tabs[0].id));
  const active = isControlled ? value : internal;
  const select = id => {
    if (!isControlled) setInternal(id);
    onChange && onChange(id);
  };
  return /*#__PURE__*/React.createElement("div", _extends({
    className: "sh-tabs",
    role: "tablist"
  }, rest), tabs.map(t => /*#__PURE__*/React.createElement("button", {
    key: t.id,
    role: "tab",
    "aria-selected": active === t.id,
    className: "sh-tab",
    "data-active": active === t.id ? 'true' : undefined,
    onClick: () => select(t.id)
  }, t.icon, t.label, t.count != null && /*#__PURE__*/React.createElement("span", {
    className: "sh-tab-count"
  }, t.count))));
}
Object.assign(__ds_scope, { Tabs });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/Tabs.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/app.jsx
try { (() => {
/* Surgery Hub — instrumentiste — mobile entry.
   Screens live in screens.jsx, the custom nav in nav.jsx, the shell in
   shells.jsx (all exported to window). This file just mounts the phone.
   Wrapped in an IIFE: classic <script> top-level const are global and would
   otherwise collide with the same names declared in the other scripts. */
(() => {
  const {
    MobileShell
  } = window;
  ReactDOM.createRoot(document.getElementById('root')).render(/*#__PURE__*/React.createElement(MobileShell, null));
  window.SH_refreshIcons && window.SH_refreshIcons();
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/app.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/canvas.jsx
try { (() => {
/* Surgery Hub — instrumentiste — nav-bar exploration canvas.
   Mounts the SAME app twice: the phone (custom floating dock) and the
   desktop (left rail) side-by-side, so the menu identity can be compared. */

(() => {
  const {
    DesignCanvas,
    DCSection,
    DCArtboard
  } = window;
  const {
    MobileShell,
    DesktopShell
  } = window;
  function NavCanvas() {
    return /*#__PURE__*/React.createElement(DesignCanvas, null, /*#__PURE__*/React.createElement(DCSection, {
      id: "navbar",
      title: "Barre de menu \xB7 instrumentiste",
      subtitle: "Une seule identit\xE9 de navigation, deux appareils. Onglet Absences ajout\xE9, Pr\xE9f\xE9rences log\xE9es dans le menu, th\xE8me clair/sombre r\xE9glable par \xE9cran."
    }, /*#__PURE__*/React.createElement(DCArtboard, {
      id: "mobile",
      label: "Mobile \xB7 dock flottant \u2014 l'onglet actif devient une pilule verte",
      width: 462,
      height: 930,
      style: {
        background: 'radial-gradient(circle at 50% 0%, #E9EEF2, #DCE3E9)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement(MobileShell, null)), /*#__PURE__*/React.createElement(DCArtboard, {
      id: "desktop",
      label: "Desktop \xB7 rail lat\xE9ral \u2014 m\xEAmes destinations, Pr\xE9f\xE9rences + carte profil en pied",
      width: 1360,
      height: 840
    }, /*#__PURE__*/React.createElement(DesktopShell, {
      initialTab: "today"
    }))));
  }
  ReactDOM.createRoot(document.getElementById('root')).render(/*#__PURE__*/React.createElement(NavCanvas, null));
  window.SH_refreshIcons && window.SH_refreshIcons();
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/canvas.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/data.js
try { (() => {
/* Surgery Hub — instrumentiste app — mock data (UI kit, not production). */
window.SH_DATA = {
  profile: {
    name: 'Thomas Mercier',
    role: 'Instrumentiste indépendant',
    city: 'Bruxelles',
    specialties: ['Bloc opératoire', 'Orthopédie', 'Stérilisation'],
    docsToUpdate: 2,
    monthHours: 96,
    monthMissions: 12
  },
  todayMission: {
    id: 216,
    date: "Aujourd'hui",
    time: '10h00 → 20h00',
    site: 'CHIREC — Hôpital Delta',
    address: 'Boulevard du Triomphe 201, 1160 Auderghem',
    surgeon: 'Dr. Pierre Dubois',
    type: 'Bloc opératoire',
    specialty: 'Orthopédie',
    status: 'aencoder'
  },
  offers: [{
    id: 301,
    site: 'CHIREC — Hôpital Delta',
    date: 'Mer. 17 juin',
    time: '10h00 → 20h00',
    type: 'Bloc opératoire',
    specialty: 'Orthopédie',
    city: 'Auderghem',
    surgeon: 'Dr. P. Dubois'
  }, {
    id: 302,
    site: 'Clinique Saint-Jean',
    date: 'Jeu. 18 juin',
    time: '07h30 → 13h00',
    type: 'Bloc opératoire',
    specialty: 'Viscérale',
    city: 'Bruxelles',
    surgeon: 'Dr. A. Lefèvre'
  }, {
    id: 303,
    site: 'CHU Brugmann',
    date: 'Sam. 20 juin',
    time: '08h00 → 16h00',
    type: 'Stérilisation',
    specialty: 'Stérilisation',
    city: 'Laeken',
    surgeon: '—'
  }, {
    id: 304,
    site: 'Clinique du Parc Léopold',
    date: 'Lun. 22 juin',
    time: '09h00 → 17h00',
    type: 'Bloc opératoire',
    specialty: 'Cardiologie',
    city: 'Etterbeek',
    surgeon: 'Dr. M. Janssens'
  }],
  missions: [{
    id: 216,
    site: 'CHIREC — Hôpital Delta',
    date: "Aujourd'hui",
    time: '10h00 → 20h00',
    type: 'Bloc opératoire',
    specialty: 'Orthopédie',
    status: 'aencoder',
    when: 'avenir'
  }, {
    id: 210,
    site: 'CHU Brugmann',
    date: 'Demain',
    time: '07h00 → 15h00',
    type: 'Bloc opératoire',
    specialty: 'Neurochirurgie',
    status: 'avenir',
    when: 'avenir'
  }, {
    id: 198,
    site: 'Clinique Saint-Jean',
    date: 'Lun. 9 juin',
    time: '08h00 → 14h00',
    type: 'Stérilisation',
    specialty: 'Stérilisation',
    status: 'terminee',
    when: 'historique'
  }, {
    id: 192,
    site: 'CHIREC — Hôpital Delta',
    date: 'Jeu. 5 juin',
    time: '10h00 → 18h00',
    type: 'Bloc opératoire',
    specialty: 'Orthopédie',
    status: 'terminee',
    when: 'historique'
  }, {
    id: 188,
    site: 'Clinique du Parc Léopold',
    date: 'Mar. 3 juin',
    time: '09h00 → 16h00',
    type: 'Bloc opératoire',
    specialty: 'Cardiologie',
    status: 'aencoder',
    when: 'aencoder'
  }, {
    id: 180,
    site: 'CHU Saint-Pierre',
    date: 'Ven. 30 mai',
    time: '07h30 → 12h00',
    type: 'Bloc opératoire',
    specialty: 'Viscérale',
    status: 'refusee',
    when: 'historique'
  }],
  notifications: [{
    id: 1,
    kind: 'attribuee',
    title: 'Mission attribuée',
    text: 'CHIREC — Hôpital Delta, mer. 17 juin. Vous êtes confirmé(e).',
    time: 'Il y a 2 h',
    unread: true
  }, {
    id: 2,
    kind: 'encodage',
    title: 'Encodage à compléter',
    text: 'La mission du 3 juin (Parc Léopold) attend votre encodage.',
    time: 'Hier',
    unread: false
  }, {
    id: 3,
    kind: 'offre',
    title: 'Nouvelle offre près de chez vous',
    text: 'Clinique Saint-Jean, jeu. 18 juin · Bloc opératoire.',
    time: 'Hier',
    unread: false
  }, {
    id: 4,
    kind: 'rappel',
    title: 'Rappel de mission',
    text: 'Votre mission de demain démarre à 07h00 au CHU Brugmann.',
    time: '2 juin',
    unread: false
  }],
  // declared absences (instrumentiste unavailable) — relative to "today" 2026-06-10
  absences: [{
    id: 'a1',
    start: '2026-06-24',
    end: '2026-06-28',
    comment: 'Congés annuels — hors Belgique.'
  }, {
    id: 'a2',
    start: '2026-07-14',
    end: '2026-07-14',
    comment: ''
  }, {
    id: 'a3',
    start: '2026-06-02',
    end: '2026-06-03',
    comment: 'Indisponible (rendez-vous personnel).'
  }, {
    id: 'a4',
    start: '2026-05-12',
    end: '2026-05-16',
    comment: 'Formation — instrumentation robotique.'
  }],
  sites: ['CHIREC — Hôpital Delta', 'Clinique Saint-Jean', 'CHU Brugmann', 'Clinique du Parc Léopold', 'CHU Saint-Pierre'],
  surgeons: ['Dr. Pierre Dubois', 'Dr. Anne Lefèvre', 'Dr. Marc Janssens', 'Autre / non précisé'],
  types: ['Bloc opératoire', 'Stérilisation', 'Consultation', 'Garde'],
  // mission calendar dots for June (day -> status)
  planning: {
    3: 'aencoder',
    5: 'terminee',
    9: 'terminee',
    10: 'encours',
    11: 'avenir',
    17: 'avenir',
    18: 'proposee',
    20: 'proposee'
  },
  interventionCatalog: ['Prothèse totale de hanche', 'Prothèse totale de genou', 'Arthroscopie', 'Ostéosynthèse', 'Cholécystectomie', 'Appendicectomie']
};
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/data.js", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/design-canvas.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// DesignCanvas.jsx — Figma-ish design canvas wrapper
// Warm gray grid bg + Sections + Artboards + PostIt notes.
// Exports (to window): DesignCanvas, DCSection, DCArtboard, DCPostIt.
// Artboards are reorderable (grip-drag), deletable, labels/titles are
// inline-editable, and any artboard can be opened in a fullscreen focus
// overlay (←/→/Esc). State persists to a .design-canvas.state.json sidecar
// via the host bridge. No assets, no deps.
//
// Usage:
//   <DesignCanvas>
//     <DCSection id="onboarding" title="Onboarding" subtitle="First-run variants">
//       <DCArtboard id="a" label="A · Dusk" width={260} height={480}>…</DCArtboard>
//       <DCArtboard id="b" label="B · Minimal" width={260} height={480}>…</DCArtboard>
//     </DCSection>
//   </DesignCanvas>
//
// Artboards are static design frames, not scroll regions — never use
// height: 100% + overflow: auto/scroll on inner elements; size each artboard
// to fit its content (explicit pixel height, or let it grow).
/* END USAGE */

const DC = {
  bg: '#f0eee9',
  grid: 'rgba(0,0,0,0.06)',
  label: 'rgba(60,50,40,0.7)',
  title: 'rgba(40,30,20,0.85)',
  subtitle: 'rgba(60,50,40,0.6)',
  postitBg: '#fef4a8',
  postitText: '#5a4a2a',
  font: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif'
};

// One-time CSS injection (classes are dc-prefixed so they don't collide with
// the hosted design's own styles).
if (typeof document !== 'undefined' && !document.getElementById('dc-styles')) {
  const s = document.createElement('style');
  s.id = 'dc-styles';
  s.textContent = ['.dc-editable{cursor:text;outline:none;white-space:nowrap;border-radius:3px;padding:0 2px;margin:0 -2px}', '.dc-editable:focus{background:#fff;box-shadow:0 0 0 1.5px #c96442}', '[data-dc-slot]{transition:transform .18s cubic-bezier(.2,.7,.3,1)}', '[data-dc-slot].dc-dragging{transition:none;z-index:10;pointer-events:none}', '[data-dc-slot].dc-dragging .dc-card{box-shadow:0 12px 40px rgba(0,0,0,.25),0 0 0 2px #c96442;transform:scale(1.02)}',
  // isolation:isolate contains artboard content's z-indexes so a
  // z-indexed child (sticky navbar etc.) can't paint over .dc-header or
  // the .dc-menu popover that drops into the top of the card.
  '.dc-card{isolation:isolate;transition:box-shadow .15s,transform .15s}', '.dc-card *{scrollbar-width:none}', '.dc-card *::-webkit-scrollbar{display:none}',
  // Per-artboard header: grip + label on the left, delete/expand on the
  // right. Single flex row; when the artboard's on-screen width is too
  // narrow for both the label yields (ellipsis, then hidden entirely below
  // ~4ch via the container query) and the buttons stay on the row.
  '.dc-header{position:absolute;bottom:100%;left:-4px;margin-bottom:calc(4px * var(--dc-inv-zoom,1));z-index:2;', '  display:flex;align-items:center;container-type:inline-size}', '.dc-labelrow{display:flex;align-items:center;gap:4px;height:24px;flex:1 1 auto;min-width:0}', '.dc-grip{flex:0 0 auto;cursor:grab;display:flex;align-items:center;padding:5px 4px;border-radius:4px;transition:background .12s,opacity .12s}', '.dc-grip:hover{background:rgba(0,0,0,.08)}', '.dc-grip:active{cursor:grabbing}', '.dc-labeltext{flex:1 1 auto;min-width:0;cursor:pointer;border-radius:4px;padding:3px 6px;', '  display:flex;align-items:center;transition:background .12s;overflow:hidden}',
  // Below ~4ch of label room: hide the label entirely, and drop the grip to
  // hover-only (same reveal rule as .dc-btns) so a narrow header is clean
  // until the card is moused.
  '@container (max-width: 110px){', '  .dc-labeltext{display:none}', '  .dc-grip{opacity:0}', '  [data-dc-slot]:hover .dc-grip{opacity:1}', '}', '.dc-labeltext:hover{background:rgba(0,0,0,.05)}', '.dc-labeltext .dc-editable{overflow:hidden;text-overflow:ellipsis;max-width:100%}', '.dc-labeltext .dc-editable:focus{overflow:visible;text-overflow:clip}', '.dc-btns{flex:0 0 auto;margin-left:auto;display:flex;gap:2px;opacity:0;transition:opacity .12s}', '[data-dc-slot]:hover .dc-btns,.dc-btns:has(.dc-menu){opacity:1}', '.dc-expand,.dc-kebab{width:22px;height:22px;border-radius:5px;border:none;cursor:pointer;padding:0;', '  background:transparent;color:rgba(60,50,40,.7);display:flex;align-items:center;justify-content:center;', '  font:inherit;transition:background .12s,color .12s}', '.dc-expand:hover,.dc-kebab:hover{background:rgba(0,0,0,.06);color:#2a251f}',
  // Slot hosting an open menu floats above later siblings (which otherwise
  // paint on top — same z-index:auto, later DOM order) so the popup isn't
  // clipped by the next card.
  '[data-dc-slot]:has(.dc-menu){z-index:10}', '.dc-menu{position:absolute;top:100%;right:0;margin-top:4px;background:#fff;border-radius:8px;', '  box-shadow:0 8px 28px rgba(0,0,0,.18),0 0 0 1px rgba(0,0,0,.05);padding:4px;min-width:160px;z-index:10}', '.dc-menu button{display:block;width:100%;padding:7px 10px;border:0;background:transparent;', '  border-radius:5px;font-family:inherit;font-size:13px;font-weight:500;line-height:1.2;', '  color:#29261b;cursor:pointer;text-align:left;transition:background .12s;white-space:nowrap}', '.dc-menu button:hover{background:rgba(0,0,0,.05)}', '.dc-menu hr{border:0;border-top:1px solid rgba(0,0,0,.08);margin:4px 2px}', '.dc-menu .dc-danger{color:#c96442}', '.dc-menu .dc-danger:hover{background:rgba(201,100,66,.1)}',
  // Chrome (titles / labels / buttons) counter-scales against the viewport
  // zoom so it stays a constant on-screen size. --dc-inv-zoom is set by
  // DCViewport on every transform update and inherits to all descendants —
  // any overlay inside the world (e.g. a TweaksPanel on an artboard) can use
  // it the same way.
  //
  // The header uses transform:scale (out-of-flow, so layout impact doesn't
  // matter) with its world-space width set to card-width / inv-zoom so that
  // after counter-scaling its on-screen width exactly matches the card's —
  // that's what lets the container query + text-overflow behave against the
  // card's visible edge at every zoom level.
  //
  // The section head uses CSS zoom instead of transform so its layout box
  // grows with the counter-scale, pushing the card row down — otherwise the
  // constant-screen-size title would overflow into the (shrinking) world-
  // space gap and overlap the artboard headers at low zoom.
  '.dc-header{width:calc((100% + 4px) / var(--dc-inv-zoom,1));', '  transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom left}', '.dc-sectionhead{zoom:var(--dc-inv-zoom,1)}'].join('\n');
  document.head.appendChild(s);
}
const DCCtx = React.createContext(null);

// Recursively unwrap React.Fragment so <>…</> grouping doesn't hide
// DCSection/DCArtboard children from the type-based walks below.
function dcFlatten(children) {
  const out = [];
  React.Children.forEach(children, c => {
    if (c && c.type === React.Fragment) out.push(...dcFlatten(c.props.children));else out.push(c);
  });
  return out;
}

// ─────────────────────────────────────────────────────────────
// DesignCanvas — stateful wrapper around the pan/zoom viewport.
// Owns runtime state (per-section order, renamed titles/labels, hidden
// artboards, focused artboard). Order/titles/labels/hidden persist to a
// .design-canvas.state.json
// sidecar next to the HTML. Reads go via plain fetch() so the saved
// arrangement is visible anywhere the HTML + sidecar are served together
// (omelette preview, direct link, downloaded zip). Writes go through the
// host's window.omelette bridge — editing requires the omelette runtime.
// Focus is ephemeral.
// ─────────────────────────────────────────────────────────────
const DC_STATE_FILE = '.design-canvas.state.json';
function DesignCanvas({
  children,
  minScale,
  maxScale,
  style
}) {
  const [state, setState] = React.useState({
    sections: {},
    focus: null
  });
  // Hold rendering until the sidecar read settles so the saved order/titles
  // appear on first paint (no source-order flash). didRead gates writes until
  // the read settles so the empty initial state can't clobber a slow read;
  // skipNextWrite suppresses the one echo-write that would otherwise follow
  // hydration.
  const [ready, setReady] = React.useState(false);
  const didRead = React.useRef(false);
  const skipNextWrite = React.useRef(false);
  React.useEffect(() => {
    let off = false;
    fetch('./' + DC_STATE_FILE).then(r => r.ok ? r.json() : null).then(saved => {
      if (off || !saved || !saved.sections) return;
      skipNextWrite.current = true;
      setState(s => ({
        ...s,
        sections: saved.sections
      }));
    }).catch(() => {}).finally(() => {
      didRead.current = true;
      if (!off) setReady(true);
    });
    const t = setTimeout(() => {
      if (!off) setReady(true);
    }, 150);
    return () => {
      off = true;
      clearTimeout(t);
    };
  }, []);
  React.useEffect(() => {
    if (!didRead.current) return;
    if (skipNextWrite.current) {
      skipNextWrite.current = false;
      return;
    }
    const t = setTimeout(() => {
      window.omelette?.writeFile(DC_STATE_FILE, JSON.stringify({
        sections: state.sections
      })).catch(() => {});
    }, 250);
    return () => clearTimeout(t);
  }, [state.sections]);

  // Build registries synchronously from children so FocusOverlay can read
  // them in the same render. Fragments are flattened; wrapping in other
  // elements still opts out of focus/reorder.
  const registry = {}; // slotId -> { sectionId, artboard }
  const sectionMeta = {}; // sectionId -> { title, subtitle, slotIds[] }
  const sectionOrder = [];
  dcFlatten(children).forEach(sec => {
    if (!sec || sec.type !== DCSection) return;
    const sid = sec.props.id ?? sec.props.title;
    if (!sid) return;
    sectionOrder.push(sid);
    const persisted = state.sections[sid] || {};
    const abs = [];
    dcFlatten(sec.props.children).forEach(ab => {
      if (!ab || ab.type !== DCArtboard) return;
      const aid = ab.props.id ?? ab.props.label;
      if (aid) abs.push([aid, ab]);
    });
    // hidden is scoped to one source revision — when the agent regenerates
    // (artboard-ID set changes), prior deletes don't apply to new content.
    const srcKey = abs.map(([k]) => k).join('\x1f');
    const hidden = persisted.srcKey === srcKey ? persisted.hidden || [] : [];
    const srcIds = [];
    abs.forEach(([aid, ab]) => {
      if (hidden.includes(aid)) return;
      registry[`${sid}/${aid}`] = {
        sectionId: sid,
        artboard: ab
      };
      srcIds.push(aid);
    });
    const kept = (persisted.order || []).filter(k => srcIds.includes(k));
    sectionMeta[sid] = {
      title: persisted.title ?? sec.props.title,
      subtitle: sec.props.subtitle,
      slotIds: [...kept, ...srcIds.filter(k => !kept.includes(k))]
    };
  });
  const api = React.useMemo(() => ({
    state,
    section: id => state.sections[id] || {},
    patchSection: (id, p) => setState(s => ({
      ...s,
      sections: {
        ...s.sections,
        [id]: {
          ...s.sections[id],
          ...(typeof p === 'function' ? p(s.sections[id] || {}) : p)
        }
      }
    })),
    setFocus: slotId => setState(s => ({
      ...s,
      focus: slotId
    }))
  }), [state]);

  // Esc exits focus; any outside pointerdown commits an in-progress rename.
  React.useEffect(() => {
    const onKey = e => {
      if (e.key === 'Escape') api.setFocus(null);
    };
    const onPd = e => {
      const ae = document.activeElement;
      if (ae && ae.isContentEditable && !ae.contains(e.target)) ae.blur();
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPd, true);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPd, true);
    };
  }, [api]);
  return /*#__PURE__*/React.createElement(DCCtx.Provider, {
    value: api
  }, /*#__PURE__*/React.createElement(DCViewport, {
    minScale: minScale,
    maxScale: maxScale,
    style: style
  }, ready && children), state.focus && registry[state.focus] && /*#__PURE__*/React.createElement(DCFocusOverlay, {
    entry: registry[state.focus],
    sectionMeta: sectionMeta,
    sectionOrder: sectionOrder
  }));
}

// ─────────────────────────────────────────────────────────────
// DCViewport — transform-based pan/zoom (internal)
//
// Input mapping (Figma-style):
//   • trackpad pinch  → zoom   (ctrlKey wheel; Safari gesture* events)
//   • trackpad scroll → pan    (two-finger)
//   • mouse wheel     → zoom   (notched; distinguished from trackpad scroll)
//   • middle-drag / primary-drag-on-bg → pan
//
// Transform state lives in a ref and is written straight to the DOM
// (translate3d + will-change) so wheel ticks don't go through React —
// keeps pans at 60fps on dense canvases.
// ─────────────────────────────────────────────────────────────
function DCViewport({
  children,
  minScale = 0.1,
  maxScale = 8,
  style = {}
}) {
  const vpRef = React.useRef(null);
  const worldRef = React.useRef(null);
  const tf = React.useRef({
    x: 0,
    y: 0,
    scale: 1
  });
  // Persist viewport across reloads so the user lands back where they were
  // after an agent edit or browser refresh. The sandbox origin is already
  // per-project; pathname keeps multiple canvas files in one project apart.
  const tfKey = 'dc-viewport:' + location.pathname;
  const saveT = React.useRef(0);
  const lastPostedScale = React.useRef();
  const apply = React.useCallback(() => {
    const {
      x,
      y,
      scale
    } = tf.current;
    const el = worldRef.current;
    if (!el) return;
    el.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scale})`;
    // Exposed for zoom-invariant chrome (labels, buttons, TweaksPanel).
    el.style.setProperty('--dc-inv-zoom', String(1 / scale));
    // Keep the host toolbar's % readout in sync with the canvas scale. Pan
    // ticks leave scale unchanged — skip the cross-frame post for those.
    if (lastPostedScale.current !== scale) {
      lastPostedScale.current = scale;
      window.parent.postMessage({
        type: '__dc_zoom',
        scale
      }, '*');
    }
    clearTimeout(saveT.current);
    saveT.current = setTimeout(() => {
      try {
        localStorage.setItem(tfKey, JSON.stringify(tf.current));
      } catch {}
    }, 200);
  }, [tfKey]);
  React.useLayoutEffect(() => {
    const flush = () => {
      clearTimeout(saveT.current);
      try {
        localStorage.setItem(tfKey, JSON.stringify(tf.current));
      } catch {}
    };
    try {
      const s = JSON.parse(localStorage.getItem(tfKey) || 'null');
      if (s && Number.isFinite(s.x) && Number.isFinite(s.y) && Number.isFinite(s.scale)) {
        tf.current = {
          x: s.x,
          y: s.y,
          scale: Math.min(maxScale, Math.max(minScale, s.scale))
        };
        apply();
      }
    } catch {}
    // Flush on pagehide and unmount so a reload within the 200ms debounce
    // window doesn't drop the last pan/zoom.
    window.addEventListener('pagehide', flush);
    return () => {
      window.removeEventListener('pagehide', flush);
      flush();
    };
  }, []);
  React.useEffect(() => {
    const vp = vpRef.current;
    if (!vp) return;
    const zoomAt = (cx, cy, factor) => {
      const r = vp.getBoundingClientRect();
      const px = cx - r.left,
        py = cy - r.top;
      const t = tf.current;
      const next = Math.min(maxScale, Math.max(minScale, t.scale * factor));
      const k = next / t.scale;
      // --dc-inv-zoom consumers (.dc-sectionhead's CSS zoom, each section's
      // marginBottom) reflow on every scale change, vertically shifting the
      // world layout — so a world point mathematically pinned under the cursor
      // drifts as you zoom (content creeps up on zoom-in, down on zoom-out).
      // Anchor the DOM element under the cursor instead: record its screen Y,
      // apply the transform + --dc-inv-zoom, then cancel whatever vertical
      // drift the reflow introduced so it stays put on screen.
      let marker = null,
        markerY0 = 0;
      if (k !== 1) {
        const hit = document.elementFromPoint(cx, cy);
        marker = hit && hit.closest ? hit.closest('[data-dc-slot],[data-dc-section]') : null;
        if (marker) markerY0 = marker.getBoundingClientRect().top;
      }
      // keep the world point under the cursor fixed
      t.x = px - (px - t.x) * k;
      t.y = py - (py - t.y) * k;
      t.scale = next;
      apply();
      if (marker) {
        // A pure zoom around (cx, cy) maps screen Y → cy + (Y - cy) * k. Any
        // departure after the --dc-inv-zoom reflow is the layout drift.
        const drift = marker.getBoundingClientRect().top - (cy + (markerY0 - cy) * k);
        if (Math.abs(drift) > 0.1) {
          t.y -= drift;
          apply();
        }
      }
    };

    // Mouse-wheel vs trackpad-scroll heuristic. A physical wheel sends
    // line-mode deltas (Firefox) or large integer pixel deltas with no X
    // component (Chrome/Safari, typically multiples of 100/120). Trackpad
    // two-finger scroll sends small/fractional pixel deltas, often with
    // non-zero deltaX. ctrlKey is set by the browser for trackpad pinch.
    const isMouseWheel = e => e.deltaMode !== 0 || e.deltaX === 0 && Number.isInteger(e.deltaY) && Math.abs(e.deltaY) >= 40;
    const onWheel = e => {
      e.preventDefault();
      if (isGesturing) return; // Safari: gesture* owns the pinch — discard concurrent wheels
      if ((e.ctrlKey || e.metaKey) && !isMouseWheel(e)) {
        // trackpad pinch, or ctrl/cmd + smooth-scroll mouse. Notched
        // wheels fall through to the fixed-step branch below.
        zoomAt(e.clientX, e.clientY, Math.exp(-e.deltaY * 0.01));
      } else if (isMouseWheel(e)) {
        // notched mouse wheel — fixed-ratio step per click
        zoomAt(e.clientX, e.clientY, Math.exp(-Math.sign(e.deltaY) * 0.18));
      } else {
        // trackpad two-finger scroll — pan
        tf.current.x -= e.deltaX;
        tf.current.y -= e.deltaY;
        apply();
      }
    };

    // Safari sends native gesture* events for trackpad pinch with a smooth
    // e.scale; preferring these over the ctrl+wheel fallback gives a much
    // better feel there. No-ops on other browsers. Safari also fires
    // ctrlKey wheel events during the same pinch — isGesturing makes
    // onWheel drop those entirely so they neither zoom nor pan.
    let gsBase = 1;
    let isGesturing = false;
    const onGestureStart = e => {
      e.preventDefault();
      isGesturing = true;
      gsBase = tf.current.scale;
    };
    const onGestureChange = e => {
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, gsBase * e.scale / tf.current.scale);
    };
    const onGestureEnd = e => {
      e.preventDefault();
      isGesturing = false;
    };

    // Drag-pan: middle button anywhere, or primary button on canvas
    // background (anything that isn't an artboard or an inline editor).
    let drag = null;
    const onPointerDown = e => {
      const onBg = !e.target.closest('[data-dc-slot], .dc-editable');
      if (!(e.button === 1 || e.button === 0 && onBg)) return;
      e.preventDefault();
      vp.setPointerCapture(e.pointerId);
      drag = {
        id: e.pointerId,
        lx: e.clientX,
        ly: e.clientY
      };
      vp.style.cursor = 'grabbing';
    };
    const onPointerMove = e => {
      if (!drag || e.pointerId !== drag.id) return;
      tf.current.x += e.clientX - drag.lx;
      tf.current.y += e.clientY - drag.ly;
      drag.lx = e.clientX;
      drag.ly = e.clientY;
      apply();
    };
    const onPointerUp = e => {
      if (!drag || e.pointerId !== drag.id) return;
      vp.releasePointerCapture(e.pointerId);
      drag = null;
      vp.style.cursor = '';
    };

    // Host-driven zoom (toolbar % menu). Zooms around viewport centre so the
    // visible midpoint stays fixed — matching the host's iframe-zoom feel.
    const onHostMsg = e => {
      const d = e.data;
      if (d && d.type === '__dc_set_zoom' && typeof d.scale === 'number') {
        const r = vp.getBoundingClientRect();
        zoomAt(r.left + r.width / 2, r.top + r.height / 2, d.scale / tf.current.scale);
      } else if (d && d.type === '__dc_probe') {
        // Host's [readyGen] reset asks whether a canvas is present; it
        // fires on the iframe's native 'load', which for canvases with
        // images/fonts is after our mount-time announce, so re-announce.
        // Clear the pan-tick guard so apply() re-posts the current scale
        // even if it's unchanged — the host just reset dcScale to 1.
        window.parent.postMessage({
          type: '__dc_present'
        }, '*');
        lastPostedScale.current = undefined;
        apply();
      }
    };
    window.addEventListener('message', onHostMsg);
    // Announce canvas mode so the host toolbar proxies its % control here
    // instead of scaling the iframe element (which would just shrink the
    // viewport window of an infinite canvas). The apply() that follows emits
    // the initial __dc_zoom so the toolbar % is correct before first pinch.
    // lastPostedScale reset mirrors the __dc_probe handler: the layout
    // effect's restore-path apply() may already have posted the restored
    // scale (before __dc_present), so clear the guard to re-post it in order.
    window.parent.postMessage({
      type: '__dc_present'
    }, '*');
    lastPostedScale.current = undefined;
    apply();
    vp.addEventListener('wheel', onWheel, {
      passive: false
    });
    vp.addEventListener('gesturestart', onGestureStart, {
      passive: false
    });
    vp.addEventListener('gesturechange', onGestureChange, {
      passive: false
    });
    vp.addEventListener('gestureend', onGestureEnd, {
      passive: false
    });
    vp.addEventListener('pointerdown', onPointerDown);
    vp.addEventListener('pointermove', onPointerMove);
    vp.addEventListener('pointerup', onPointerUp);
    vp.addEventListener('pointercancel', onPointerUp);
    return () => {
      window.removeEventListener('message', onHostMsg);
      vp.removeEventListener('wheel', onWheel);
      vp.removeEventListener('gesturestart', onGestureStart);
      vp.removeEventListener('gesturechange', onGestureChange);
      vp.removeEventListener('gestureend', onGestureEnd);
      vp.removeEventListener('pointerdown', onPointerDown);
      vp.removeEventListener('pointermove', onPointerMove);
      vp.removeEventListener('pointerup', onPointerUp);
      vp.removeEventListener('pointercancel', onPointerUp);
    };
  }, [apply, minScale, maxScale]);
  const gridSvg = `url("data:image/svg+xml,%3Csvg width='120' height='120' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M120 0H0v120' fill='none' stroke='${encodeURIComponent(DC.grid)}' stroke-width='1'/%3E%3C/svg%3E")`;
  return /*#__PURE__*/React.createElement("div", {
    ref: vpRef,
    className: "design-canvas",
    style: {
      height: '100vh',
      width: '100vw',
      background: DC.bg,
      overflow: 'hidden',
      overscrollBehavior: 'none',
      touchAction: 'none',
      position: 'relative',
      fontFamily: DC.font,
      boxSizing: 'border-box',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    ref: worldRef,
    style: {
      position: 'absolute',
      top: 0,
      left: 0,
      transformOrigin: '0 0',
      willChange: 'transform',
      width: 'max-content',
      minWidth: '100%',
      minHeight: '100%',
      padding: '60px 0 80px'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: -6000,
      backgroundImage: gridSvg,
      backgroundSize: '120px 120px',
      pointerEvents: 'none',
      zIndex: -1
    }
  }), children));
}

// ─────────────────────────────────────────────────────────────
// DCSection — editable title + h-row of artboards in persisted order
// ─────────────────────────────────────────────────────────────
function DCSection({
  id,
  title,
  subtitle,
  children,
  gap = 48
}) {
  const ctx = React.useContext(DCCtx);
  const sid = id ?? title;
  const all = React.Children.toArray(dcFlatten(children));
  const artboards = all.filter(c => c && c.type === DCArtboard);
  const rest = all.filter(c => !(c && c.type === DCArtboard));
  const sec = ctx && sid && ctx.section(sid) || {};
  // Must match DesignCanvas's srcKey computation exactly (it filters falsy
  // IDs), or onDelete persists a srcKey that DesignCanvas never recognizes.
  const allIds = artboards.map(a => a.props.id ?? a.props.label).filter(Boolean);
  const srcKey = allIds.join('\x1f');
  const hidden = sec.srcKey === srcKey ? sec.hidden || [] : [];
  const srcOrder = allIds.filter(k => !hidden.includes(k));
  const order = React.useMemo(() => {
    const kept = (sec.order || []).filter(k => srcOrder.includes(k));
    return [...kept, ...srcOrder.filter(k => !kept.includes(k))];
  }, [sec.order, srcOrder.join('|')]);
  const byId = Object.fromEntries(artboards.map(a => [a.props.id ?? a.props.label, a]));

  // marginBottom counter-scales so the on-screen gap between sections stays
  // constant — otherwise at low zoom the (world-space) gap collapses while
  // the screen-constant sectionhead below it doesn't, and the title reads as
  // belonging to the section above. paddingBottom below is just enough for
  // the 24px artboard-header (abs-positioned above each card) plus ~8px, so
  // the title sits tight against its own row at every zoom.
  return /*#__PURE__*/React.createElement("div", {
    "data-dc-section": sid,
    style: {
      marginBottom: 'calc(80px * var(--dc-inv-zoom, 1))',
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '0 60px'
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "dc-sectionhead",
    style: {
      paddingBottom: 36
    }
  }, /*#__PURE__*/React.createElement(DCEditable, {
    tag: "div",
    value: sec.title ?? title,
    onChange: v => ctx && sid && ctx.patchSection(sid, {
      title: v
    }),
    style: {
      fontSize: 28,
      fontWeight: 600,
      color: DC.title,
      letterSpacing: -0.4,
      marginBottom: 6,
      display: 'inline-block'
    }
  }), subtitle && /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 16,
      color: DC.subtitle
    }
  }, subtitle))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap,
      padding: '0 60px',
      alignItems: 'flex-start',
      width: 'max-content'
    }
  }, order.map(k => /*#__PURE__*/React.createElement(DCArtboardFrame, {
    key: k,
    sectionId: sid,
    artboard: byId[k],
    order: order,
    label: (sec.labels || {})[k] ?? byId[k].props.label,
    onRename: v => ctx && ctx.patchSection(sid, x => ({
      labels: {
        ...x.labels,
        [k]: v
      }
    })),
    onReorder: next => ctx && ctx.patchSection(sid, {
      order: next
    }),
    onDelete: () => ctx && ctx.patchSection(sid, x => ({
      hidden: [...(x.srcKey === srcKey ? x.hidden || [] : []), k],
      srcKey
    })),
    onFocus: () => ctx && ctx.setFocus(`${sid}/${k}`)
  }))), rest);
}

// DCArtboard — marker; rendered by DCArtboardFrame via DCSection.
function DCArtboard() {
  return null;
}

// Per-artboard export (kind: 'png' | 'html'). Both paths share the same
// self-contained clone: computed styles baked in, @font-face / <img> /
// inline-style background-image urls inlined as data URIs. PNG wraps the
// clone in foreignObject→canvas at 3× the artboard's natural width×height
// (same pipeline the host uses for page captures); HTML wraps it in a
// minimal standalone document. Both are independent of viewport zoom.
async function dcExport(node, w, h, name, kind) {
  try {
    await document.fonts.ready;
  } catch {}
  const toDataURL = url => fetch(url).then(r => r.blob()).then(b => new Promise(res => {
    const fr = new FileReader();
    fr.onload = () => res(fr.result);
    fr.onerror = () => res(url);
    fr.readAsDataURL(b);
  })).catch(() => url);

  // Collect @font-face rules. ss.cssRules throws SecurityError on
  // cross-origin sheets (e.g. fonts.googleapis.com) — in that case fetch
  // the CSS text directly (those endpoints send ACAO:*) and regex-extract
  // the blocks. @import and @media/@supports are walked so nested
  // @font-face rules aren't missed.
  const fontRules = [],
    pending = [],
    seen = new Set();
  const scrapeCss = href => {
    if (seen.has(href)) return;
    seen.add(href);
    pending.push(fetch(href).then(r => r.text()).then(css => {
      for (const m of css.match(/@font-face\s*{[^}]*}/g) || []) fontRules.push({
        css: m,
        base: href
      });
      for (const m of css.matchAll(/@import\s+(?:url\()?['"]?([^'")\s;]+)/g)) scrapeCss(new URL(m[1], href).href);
    }).catch(() => {}));
  };
  const walk = (rules, base) => {
    for (const r of rules) {
      if (r.type === CSSRule.FONT_FACE_RULE) fontRules.push({
        css: r.cssText,
        base
      });else if (r.type === CSSRule.IMPORT_RULE && r.styleSheet) {
        const ibase = r.styleSheet.href || base;
        try {
          walk(r.styleSheet.cssRules, ibase);
        } catch {
          scrapeCss(ibase);
        }
      } else if (r.cssRules) walk(r.cssRules, base);
    }
  };
  for (const ss of document.styleSheets) {
    const base = ss.href || location.href;
    try {
      walk(ss.cssRules, base);
    } catch {
      if (ss.href) scrapeCss(ss.href);
    }
  }
  while (pending.length) await pending.shift();
  const fontCss = (await Promise.all(fontRules.map(async rule => {
    let out = rule.css,
      m;
    const re = /url\((['"]?)([^'")]+)\1\)/g;
    while (m = re.exec(rule.css)) {
      if (m[2].indexOf('data:') === 0) continue;
      let abs;
      try {
        abs = new URL(m[2], rule.base).href;
      } catch {
        continue;
      }
      out = out.split(m[0]).join('url("' + (await toDataURL(abs)) + '")');
    }
    return out;
  }))).join('\n');
  const cloneStyled = src => {
    if (src.nodeType === 8 || src.nodeType === 1 && src.tagName === 'SCRIPT') return document.createTextNode('');
    const dst = src.cloneNode(false);
    if (src.nodeType === 1) {
      const cs = getComputedStyle(src);
      let txt = '';
      for (let i = 0; i < cs.length; i++) txt += cs[i] + ':' + cs.getPropertyValue(cs[i]) + ';';
      dst.setAttribute('style', txt + 'animation:none;transition:none;');
      if (src.tagName === 'CANVAS') try {
        const im = document.createElement('img');
        im.src = src.toDataURL();
        im.setAttribute('style', txt);
        return im;
      } catch {}
    }
    for (let c = src.firstChild; c; c = c.nextSibling) dst.appendChild(cloneStyled(c));
    return dst;
  };
  const clone = cloneStyled(node);
  clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
  // Drop the card's own shadow/radius so the export is a flush w×h rect;
  // the artboard's own background (if any) is already in the computed style.
  clone.style.boxShadow = 'none';
  clone.style.borderRadius = '0';
  const jobs = [];
  clone.querySelectorAll('img').forEach(el => {
    const s = el.getAttribute('src');
    if (s && s.indexOf('data:') !== 0) jobs.push(toDataURL(el.src).then(d => el.setAttribute('src', d)));
  });
  [clone, ...clone.querySelectorAll('*')].forEach(el => {
    const bg = el.style.backgroundImage;
    if (!bg) return;
    let m;
    const re = /url\(["']?([^"')]+)["']?\)/g;
    while (m = re.exec(bg)) {
      const tok = m[0],
        url = m[1];
      if (url.indexOf('data:') === 0) continue;
      jobs.push(toDataURL(url).then(d => {
        el.style.backgroundImage = el.style.backgroundImage.split(tok).join('url("' + d + '")');
      }));
    }
  });
  await Promise.all(jobs);
  const xml = new XMLSerializer().serializeToString(clone);
  const save = (blob, ext) => {
    if (!blob) return;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name + '.' + ext;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  if (kind === 'html') {
    const html = '<!doctype html><html><head><meta charset="utf-8"><title>' + name + '</title>' + (fontCss ? '<style>' + fontCss + '</style>' : '') + '</head><body style="margin:0">' + xml + '</body></html>';
    return save(new Blob([html], {
      type: 'text/html'
    }), 'html');
  }

  // PNG: the SVG's own width/height must be the output resolution — an
  // <img>-loaded SVG rasterizes at its intrinsic size, so sizing it at 1×
  // and ctx.scale()-ing up would just upscale a 1× bitmap. viewBox maps the
  // w×h foreignObject onto the px·w × px·h SVG canvas so the browser renders
  // the HTML at full resolution.
  const px = 3;
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w * px + '" height="' + h * px + '" viewBox="0 0 ' + w + ' ' + h + '"><foreignObject width="' + w + '" height="' + h + '">' + (fontCss ? '<style><![CDATA[' + fontCss + ']]></style>' : '') + xml + '</foreignObject></svg>';
  const img = new Image();
  await new Promise((res, rej) => {
    img.onload = res;
    img.onerror = () => rej(new Error('svg load failed'));
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  });
  const cv = document.createElement('canvas');
  cv.width = w * px;
  cv.height = h * px;
  cv.getContext('2d').drawImage(img, 0, 0);
  cv.toBlob(blob => save(blob, 'png'), 'image/png');
}
function DCArtboardFrame({
  sectionId,
  artboard,
  label,
  order,
  onRename,
  onReorder,
  onFocus,
  onDelete
}) {
  const {
    id: rawId,
    label: rawLabel,
    width = 260,
    height = 480,
    children,
    style = {}
  } = artboard.props;
  const id = rawId ?? rawLabel;
  const ref = React.useRef(null);
  const cardRef = React.useRef(null);
  const menuRef = React.useRef(null);
  const [menuOpen, setMenuOpen] = React.useState(false);
  const [confirming, setConfirming] = React.useState(false);

  // ⋯ menu: close on any outside pointerdown. Two-click delete lives inside
  // the menu — first click arms the row, second commits; closing disarms.
  React.useEffect(() => {
    if (!menuOpen) {
      setConfirming(false);
      return;
    }
    const off = e => {
      if (!menuRef.current || !menuRef.current.contains(e.target)) setMenuOpen(false);
    };
    document.addEventListener('pointerdown', off, true);
    return () => document.removeEventListener('pointerdown', off, true);
  }, [menuOpen]);
  const doExport = kind => {
    setMenuOpen(false);
    if (!cardRef.current) return;
    const name = String(label || id || 'artboard').replace(/[^\w\s.-]+/g, '_');
    dcExport(cardRef.current, width, height, name, kind).catch(e => console.error('[design-canvas] export failed:', e));
  };

  // Live drag-reorder: dragged card sticks to cursor; siblings slide into
  // their would-be slots in real time via transforms. DOM order only
  // changes on drop.
  const onGripDown = e => {
    e.preventDefault();
    e.stopPropagation();
    const me = ref.current;
    // translateX is applied in local (pre-scale) space but pointer deltas and
    // getBoundingClientRect().left are screen-space — divide by the viewport's
    // current scale so the dragged card tracks the cursor at any zoom level.
    const scale = me.getBoundingClientRect().width / me.offsetWidth || 1;
    const peers = Array.from(document.querySelectorAll(`[data-dc-section="${sectionId}"] [data-dc-slot]`));
    const homes = peers.map(el => ({
      el,
      id: el.dataset.dcSlot,
      x: el.getBoundingClientRect().left
    }));
    const slotXs = homes.map(h => h.x);
    const startIdx = order.indexOf(id);
    const startX = e.clientX;
    let liveOrder = order.slice();
    me.classList.add('dc-dragging');
    const layout = () => {
      for (const h of homes) {
        if (h.id === id) continue;
        const slot = liveOrder.indexOf(h.id);
        h.el.style.transform = `translateX(${(slotXs[slot] - h.x) / scale}px)`;
      }
    };
    const move = ev => {
      const dx = ev.clientX - startX;
      me.style.transform = `translateX(${dx / scale}px)`;
      const cur = homes[startIdx].x + dx;
      let nearest = 0,
        best = Infinity;
      for (let i = 0; i < slotXs.length; i++) {
        const d = Math.abs(slotXs[i] - cur);
        if (d < best) {
          best = d;
          nearest = i;
        }
      }
      if (liveOrder.indexOf(id) !== nearest) {
        liveOrder = order.filter(k => k !== id);
        liveOrder.splice(nearest, 0, id);
        layout();
      }
    };
    const up = () => {
      document.removeEventListener('pointermove', move);
      document.removeEventListener('pointerup', up);
      const finalSlot = liveOrder.indexOf(id);
      me.classList.remove('dc-dragging');
      me.style.transform = `translateX(${(slotXs[finalSlot] - homes[startIdx].x) / scale}px)`;
      // After the settle transition, kill transitions + clear transforms +
      // commit the reorder in the same frame so there's no visual snap-back.
      setTimeout(() => {
        for (const h of homes) {
          h.el.style.transition = 'none';
          h.el.style.transform = '';
        }
        if (liveOrder.join('|') !== order.join('|')) onReorder(liveOrder);
        requestAnimationFrame(() => requestAnimationFrame(() => {
          for (const h of homes) h.el.style.transition = '';
        }));
      }, 180);
    };
    document.addEventListener('pointermove', move);
    document.addEventListener('pointerup', up);
  };
  return /*#__PURE__*/React.createElement("div", {
    ref: ref,
    "data-dc-slot": id,
    style: {
      position: 'relative',
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("div", {
    className: "dc-header",
    "data-omelette-chrome": "",
    style: {
      color: DC.label
    },
    onPointerDown: e => e.stopPropagation()
  }, /*#__PURE__*/React.createElement("div", {
    className: "dc-labelrow"
  }, /*#__PURE__*/React.createElement("div", {
    className: "dc-grip",
    onPointerDown: onGripDown,
    title: "Drag to reorder"
  }, /*#__PURE__*/React.createElement("svg", {
    width: "9",
    height: "13",
    viewBox: "0 0 9 13",
    fill: "currentColor"
  }, /*#__PURE__*/React.createElement("circle", {
    cx: "2",
    cy: "2",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "7",
    cy: "2",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "2",
    cy: "6.5",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "7",
    cy: "6.5",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "2",
    cy: "11",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "7",
    cy: "11",
    r: "1.1"
  }))), /*#__PURE__*/React.createElement("div", {
    className: "dc-labeltext",
    onClick: onFocus,
    title: "Click to focus"
  }, /*#__PURE__*/React.createElement(DCEditable, {
    value: label,
    onChange: onRename,
    onClick: e => e.stopPropagation(),
    style: {
      fontSize: 15,
      fontWeight: 500,
      color: DC.label,
      lineHeight: 1
    }
  }))), /*#__PURE__*/React.createElement("div", {
    className: "dc-btns"
  }, /*#__PURE__*/React.createElement("div", {
    ref: menuRef,
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("button", {
    className: "dc-kebab",
    title: "More",
    onClick: () => setMenuOpen(o => !o)
  }, /*#__PURE__*/React.createElement("svg", {
    width: "12",
    height: "12",
    viewBox: "0 0 12 12",
    fill: "currentColor"
  }, /*#__PURE__*/React.createElement("circle", {
    cx: "2.5",
    cy: "6",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "6",
    cy: "6",
    r: "1.1"
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "9.5",
    cy: "6",
    r: "1.1"
  }))), menuOpen && /*#__PURE__*/React.createElement("div", {
    className: "dc-menu",
    onPointerDown: e => e.stopPropagation()
  }, /*#__PURE__*/React.createElement("button", {
    onClick: () => doExport('png')
  }, "Download PNG"), /*#__PURE__*/React.createElement("button", {
    onClick: () => doExport('html')
  }, "Download HTML"), /*#__PURE__*/React.createElement("hr", null), /*#__PURE__*/React.createElement("button", {
    className: "dc-danger",
    onClick: () => {
      if (confirming) {
        setMenuOpen(false);
        onDelete();
      } else setConfirming(true);
    }
  }, confirming ? 'Click again to delete' : 'Delete'))), /*#__PURE__*/React.createElement("button", {
    className: "dc-expand",
    onClick: onFocus,
    title: "Focus"
  }, /*#__PURE__*/React.createElement("svg", {
    width: "12",
    height: "12",
    viewBox: "0 0 12 12",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.6",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M7 1h4v4M5 11H1V7M11 1L7.5 4.5M1 11l3.5-3.5"
  }))))), /*#__PURE__*/React.createElement("div", {
    ref: cardRef,
    className: "dc-card",
    style: {
      borderRadius: 2,
      boxShadow: '0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06)',
      overflow: 'hidden',
      width,
      height,
      background: '#fff',
      ...style
    }
  }, children || /*#__PURE__*/React.createElement("div", {
    style: {
      height: '100%',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      color: '#bbb',
      fontSize: 13,
      fontFamily: DC.font
    }
  }, id)));
}

// Inline rename — commits on blur or Enter.
function DCEditable({
  value,
  onChange,
  style,
  tag = 'span',
  onClick
}) {
  const T = tag;
  return /*#__PURE__*/React.createElement(T, {
    className: "dc-editable",
    contentEditable: true,
    suppressContentEditableWarning: true,
    onClick: onClick,
    onPointerDown: e => e.stopPropagation(),
    onBlur: e => onChange && onChange(e.currentTarget.textContent),
    onKeyDown: e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.currentTarget.blur();
      }
    },
    style: style
  }, value);
}

// ─────────────────────────────────────────────────────────────
// Focus mode — overlay one artboard; ←/→ within section, ↑/↓ across
// sections, Esc or backdrop click to exit.
// ─────────────────────────────────────────────────────────────
function DCFocusOverlay({
  entry,
  sectionMeta,
  sectionOrder
}) {
  const ctx = React.useContext(DCCtx);
  const {
    sectionId,
    artboard
  } = entry;
  const sec = ctx.section(sectionId);
  const meta = sectionMeta[sectionId];
  const peers = meta.slotIds;
  const aid = artboard.props.id ?? artboard.props.label;
  const idx = peers.indexOf(aid);
  const secIdx = sectionOrder.indexOf(sectionId);
  const go = d => {
    const n = peers[(idx + d + peers.length) % peers.length];
    if (n) ctx.setFocus(`${sectionId}/${n}`);
  };
  const goSection = d => {
    // Sections whose artboards are all deleted have slotIds:[] — step past
    // them to the next non-empty section so ↑/↓ doesn't dead-end.
    const n = sectionOrder.length;
    for (let i = 1; i < n; i++) {
      const ns = sectionOrder[((secIdx + d * i) % n + n) % n];
      const first = sectionMeta[ns] && sectionMeta[ns].slotIds[0];
      if (first) {
        ctx.setFocus(`${ns}/${first}`);
        return;
      }
    }
  };
  React.useEffect(() => {
    const k = e => {
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        go(-1);
      }
      if (e.key === 'ArrowRight') {
        e.preventDefault();
        go(1);
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        goSection(-1);
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        goSection(1);
      }
    };
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  });
  const {
    width = 260,
    height = 480,
    children
  } = artboard.props;
  const [vp, setVp] = React.useState({
    w: window.innerWidth,
    h: window.innerHeight
  });
  React.useEffect(() => {
    const r = () => setVp({
      w: window.innerWidth,
      h: window.innerHeight
    });
    window.addEventListener('resize', r);
    return () => window.removeEventListener('resize', r);
  }, []);
  const scale = Math.max(0.1, Math.min((vp.w - 200) / width, (vp.h - 260) / height, 2));
  const [ddOpen, setDd] = React.useState(false);
  const Arrow = ({
    dir,
    onClick
  }) => /*#__PURE__*/React.createElement("button", {
    onClick: e => {
      e.stopPropagation();
      onClick();
    },
    style: {
      position: 'absolute',
      top: '50%',
      [dir]: 28,
      transform: 'translateY(-50%)',
      border: 'none',
      background: 'rgba(255,255,255,.08)',
      color: 'rgba(255,255,255,.9)',
      width: 44,
      height: 44,
      borderRadius: 22,
      fontSize: 18,
      cursor: 'pointer',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      transition: 'background .15s'
    },
    onMouseEnter: e => e.currentTarget.style.background = 'rgba(255,255,255,.18)',
    onMouseLeave: e => e.currentTarget.style.background = 'rgba(255,255,255,.08)'
  }, /*#__PURE__*/React.createElement("svg", {
    width: "18",
    height: "18",
    viewBox: "0 0 18 18",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "2",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: dir === 'left' ? 'M11 3L5 9l6 6' : 'M7 3l6 6-6 6'
  })));

  // Portal to body so position:fixed is the real viewport regardless of any
  // transform on DesignCanvas's ancestors (including the canvas zoom itself).
  return ReactDOM.createPortal(/*#__PURE__*/React.createElement("div", {
    onClick: () => ctx.setFocus(null),
    onWheel: e => e.preventDefault(),
    style: {
      position: 'fixed',
      inset: 0,
      zIndex: 100,
      background: 'rgba(24,20,16,.6)',
      backdropFilter: 'blur(14px)',
      fontFamily: DC.font,
      color: '#fff'
    }
  }, /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      position: 'absolute',
      top: 0,
      left: 0,
      right: 0,
      height: 72,
      display: 'flex',
      alignItems: 'flex-start',
      padding: '16px 20px 0',
      gap: 16
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("button", {
    onClick: () => setDd(o => !o),
    style: {
      border: 'none',
      background: 'transparent',
      color: '#fff',
      cursor: 'pointer',
      padding: '6px 8px',
      borderRadius: 6,
      textAlign: 'left',
      fontFamily: 'inherit'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 18,
      fontWeight: 600,
      letterSpacing: -0.3
    }
  }, meta.title), /*#__PURE__*/React.createElement("svg", {
    width: "11",
    height: "11",
    viewBox: "0 0 11 11",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "1.8",
    strokeLinecap: "round",
    style: {
      opacity: .7
    }
  }, /*#__PURE__*/React.createElement("path", {
    d: "M2 4l3.5 3.5L9 4"
  }))), meta.subtitle && /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'block',
      fontSize: 13,
      opacity: .6,
      fontWeight: 400,
      marginTop: 2
    }
  }, meta.subtitle)), ddOpen && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: '100%',
      left: 0,
      marginTop: 4,
      background: '#2a251f',
      borderRadius: 8,
      boxShadow: '0 8px 32px rgba(0,0,0,.4)',
      padding: 4,
      minWidth: 200,
      zIndex: 10
    }
  }, sectionOrder.filter(sid => sectionMeta[sid].slotIds.length).map(sid => /*#__PURE__*/React.createElement("button", {
    key: sid,
    onClick: () => {
      setDd(false);
      const f = sectionMeta[sid].slotIds[0];
      if (f) ctx.setFocus(`${sid}/${f}`);
    },
    style: {
      display: 'block',
      width: '100%',
      textAlign: 'left',
      border: 'none',
      cursor: 'pointer',
      background: sid === sectionId ? 'rgba(255,255,255,.1)' : 'transparent',
      color: '#fff',
      padding: '8px 12px',
      borderRadius: 5,
      fontSize: 14,
      fontWeight: sid === sectionId ? 600 : 400,
      fontFamily: 'inherit'
    }
  }, sectionMeta[sid].title)))), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }), /*#__PURE__*/React.createElement("button", {
    onClick: () => ctx.setFocus(null),
    onMouseEnter: e => e.currentTarget.style.background = 'rgba(255,255,255,.12)',
    onMouseLeave: e => e.currentTarget.style.background = 'transparent',
    style: {
      border: 'none',
      background: 'transparent',
      color: 'rgba(255,255,255,.7)',
      width: 32,
      height: 32,
      borderRadius: 16,
      fontSize: 20,
      cursor: 'pointer',
      lineHeight: 1,
      transition: 'background .12s'
    }
  }, "\xD7")), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: 64,
      bottom: 56,
      left: 100,
      right: 100,
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 16
    }
  }, /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      width: width * scale,
      height: height * scale,
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width,
      height,
      transform: `scale(${scale})`,
      transformOrigin: 'top left',
      background: '#fff',
      borderRadius: 2,
      overflow: 'hidden',
      boxShadow: '0 20px 80px rgba(0,0,0,.4)'
    }
  }, children || /*#__PURE__*/React.createElement("div", {
    style: {
      height: '100%',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      color: '#bbb'
    }
  }, aid))), /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      fontSize: 14,
      fontWeight: 500,
      opacity: .85,
      textAlign: 'center'
    }
  }, (sec.labels || {})[aid] ?? artboard.props.label, /*#__PURE__*/React.createElement("span", {
    style: {
      opacity: .5,
      marginLeft: 10,
      fontVariantNumeric: 'tabular-nums'
    }
  }, idx + 1, " / ", peers.length))), /*#__PURE__*/React.createElement(Arrow, {
    dir: "left",
    onClick: () => go(-1)
  }), /*#__PURE__*/React.createElement(Arrow, {
    dir: "right",
    onClick: () => go(1)
  }), /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      position: 'absolute',
      bottom: 20,
      left: '50%',
      transform: 'translateX(-50%)',
      display: 'flex',
      gap: 8
    }
  }, peers.map((p, i) => /*#__PURE__*/React.createElement("button", {
    key: p,
    onClick: () => ctx.setFocus(`${sectionId}/${p}`),
    style: {
      border: 'none',
      padding: 0,
      cursor: 'pointer',
      width: 6,
      height: 6,
      borderRadius: 3,
      background: i === idx ? '#fff' : 'rgba(255,255,255,.3)'
    }
  })))), document.body);
}

// ─────────────────────────────────────────────────────────────
// Post-it — absolute-positioned sticky note
// ─────────────────────────────────────────────────────────────
function DCPostIt({
  children,
  top,
  left,
  right,
  bottom,
  rotate = -2,
  width = 180
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top,
      left,
      right,
      bottom,
      width,
      background: DC.postitBg,
      padding: '14px 16px',
      fontFamily: '"Comic Sans MS", "Marker Felt", "Segoe Print", cursive',
      fontSize: 14,
      lineHeight: 1.4,
      color: DC.postitText,
      boxShadow: '0 2px 8px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08)',
      transform: `rotate(${rotate}deg)`,
      zIndex: 5
    }
  }, children);
}
Object.assign(window, {
  DesignCanvas,
  DCSection,
  DCArtboard,
  DCPostIt
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/design-canvas.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/ios-frame.jsx
try { (() => {
// @ds-adherence-ignore -- omelette starter scaffold (raw elements/hex/px by design)

/* BEGIN USAGE */
// iOS.jsx — Simplified iOS 26 (Liquid Glass) device frame
// Based on the iOS 26 UI Kit + Figma status bar spec. No assets, no deps.
// Exports (to window): IOSDevice, IOSStatusBar, IOSNavBar, IOSGlassPill, IOSList, IOSListRow, IOSKeyboard
//
// Usage — wrap your screen content in <IOSDevice> to get the bezel, status bar
// and home indicator (props: title, dark, keyboard):
//
//   <IOSDevice title="Settings">
//     ...your screen content...
//   </IOSDevice>
//   <IOSDevice dark title="Search" keyboard>…</IOSDevice>
/* END USAGE */

// ─────────────────────────────────────────────────────────────
// Status bar
// ─────────────────────────────────────────────────────────────
function IOSStatusBar({
  dark = false,
  time = '9:41'
}) {
  const c = dark ? '#fff' : '#000';
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 154,
      alignItems: 'center',
      justifyContent: 'center',
      padding: '21px 24px 19px',
      boxSizing: 'border-box',
      position: 'relative',
      zIndex: 20,
      width: '100%'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      height: 22,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      paddingTop: 1.5
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: '-apple-system, "SF Pro", system-ui',
      fontWeight: 590,
      fontSize: 17,
      lineHeight: '22px',
      color: c
    }
  }, time)), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      height: 22,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 7,
      paddingTop: 1,
      paddingRight: 1
    }
  }, /*#__PURE__*/React.createElement("svg", {
    width: "19",
    height: "12",
    viewBox: "0 0 19 12"
  }, /*#__PURE__*/React.createElement("rect", {
    x: "0",
    y: "7.5",
    width: "3.2",
    height: "4.5",
    rx: "0.7",
    fill: c
  }), /*#__PURE__*/React.createElement("rect", {
    x: "4.8",
    y: "5",
    width: "3.2",
    height: "7",
    rx: "0.7",
    fill: c
  }), /*#__PURE__*/React.createElement("rect", {
    x: "9.6",
    y: "2.5",
    width: "3.2",
    height: "9.5",
    rx: "0.7",
    fill: c
  }), /*#__PURE__*/React.createElement("rect", {
    x: "14.4",
    y: "0",
    width: "3.2",
    height: "12",
    rx: "0.7",
    fill: c
  })), /*#__PURE__*/React.createElement("svg", {
    width: "17",
    height: "12",
    viewBox: "0 0 17 12"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M8.5 3.2C10.8 3.2 12.9 4.1 14.4 5.6L15.5 4.5C13.7 2.7 11.2 1.5 8.5 1.5C5.8 1.5 3.3 2.7 1.5 4.5L2.6 5.6C4.1 4.1 6.2 3.2 8.5 3.2Z",
    fill: c
  }), /*#__PURE__*/React.createElement("path", {
    d: "M8.5 6.8C9.9 6.8 11.1 7.3 12 8.2L13.1 7.1C11.8 5.9 10.2 5.1 8.5 5.1C6.8 5.1 5.2 5.9 3.9 7.1L5 8.2C5.9 7.3 7.1 6.8 8.5 6.8Z",
    fill: c
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "8.5",
    cy: "10.5",
    r: "1.5",
    fill: c
  })), /*#__PURE__*/React.createElement("svg", {
    width: "27",
    height: "13",
    viewBox: "0 0 27 13"
  }, /*#__PURE__*/React.createElement("rect", {
    x: "0.5",
    y: "0.5",
    width: "23",
    height: "12",
    rx: "3.5",
    stroke: c,
    strokeOpacity: "0.35",
    fill: "none"
  }), /*#__PURE__*/React.createElement("rect", {
    x: "2",
    y: "2",
    width: "20",
    height: "9",
    rx: "2",
    fill: c
  }), /*#__PURE__*/React.createElement("path", {
    d: "M25 4.5V8.5C25.8 8.2 26.5 7.2 26.5 6.5C26.5 5.8 25.8 4.8 25 4.5Z",
    fill: c,
    fillOpacity: "0.4"
  }))));
}

// ─────────────────────────────────────────────────────────────
// Liquid glass pill — blur + tint + shine
// ─────────────────────────────────────────────────────────────
function IOSGlassPill({
  children,
  dark = false,
  style = {}
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      height: 44,
      minWidth: 44,
      borderRadius: 9999,
      position: 'relative',
      overflow: 'hidden',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      boxShadow: dark ? '0 2px 6px rgba(0,0,0,0.35), 0 6px 16px rgba(0,0,0,0.2)' : '0 1px 3px rgba(0,0,0,0.07), 0 3px 10px rgba(0,0,0,0.06)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      borderRadius: 9999,
      backdropFilter: 'blur(12px) saturate(180%)',
      WebkitBackdropFilter: 'blur(12px) saturate(180%)',
      background: dark ? 'rgba(120,120,128,0.28)' : 'rgba(255,255,255,0.5)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      borderRadius: 9999,
      boxShadow: dark ? 'inset 1.5px 1.5px 1px rgba(255,255,255,0.15), inset -1px -1px 1px rgba(255,255,255,0.08)' : 'inset 1.5px 1.5px 1px rgba(255,255,255,0.7), inset -1px -1px 1px rgba(255,255,255,0.4)',
      border: dark ? '0.5px solid rgba(255,255,255,0.15)' : '0.5px solid rgba(0,0,0,0.06)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      zIndex: 1,
      display: 'flex',
      alignItems: 'center',
      padding: '0 4px'
    }
  }, children));
}

// ─────────────────────────────────────────────────────────────
// Navigation bar — glass pills + large title
// ─────────────────────────────────────────────────────────────
function IOSNavBar({
  title = 'Title',
  dark = false,
  trailingIcon = true
}) {
  const muted = dark ? 'rgba(255,255,255,0.6)' : '#404040';
  const text = dark ? '#fff' : '#000';
  const pillIcon = content => /*#__PURE__*/React.createElement(IOSGlassPill, {
    dark: dark
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 36,
      height: 36,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center'
    }
  }, content));
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 10,
      paddingTop: 62,
      paddingBottom: 10,
      position: 'relative',
      zIndex: 5
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '0 16px'
    }
  }, pillIcon(/*#__PURE__*/React.createElement("svg", {
    width: "12",
    height: "20",
    viewBox: "0 0 12 20",
    fill: "none",
    style: {
      marginLeft: -1
    }
  }, /*#__PURE__*/React.createElement("path", {
    d: "M10 2L2 10l8 8",
    stroke: muted,
    strokeWidth: "2.5",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }))), trailingIcon && pillIcon(/*#__PURE__*/React.createElement("svg", {
    width: "22",
    height: "6",
    viewBox: "0 0 22 6"
  }, /*#__PURE__*/React.createElement("circle", {
    cx: "3",
    cy: "3",
    r: "2.5",
    fill: muted
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "11",
    cy: "3",
    r: "2.5",
    fill: muted
  }), /*#__PURE__*/React.createElement("circle", {
    cx: "19",
    cy: "3",
    r: "2.5",
    fill: muted
  })))), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '0 16px',
      fontFamily: '-apple-system, system-ui',
      fontSize: 34,
      fontWeight: 700,
      lineHeight: '41px',
      color: text,
      letterSpacing: 0.4
    }
  }, title));
}

// ─────────────────────────────────────────────────────────────
// Grouped list (inset card, r:26) + row (52px)
// ─────────────────────────────────────────────────────────────
function IOSListRow({
  title,
  detail,
  icon,
  chevron = true,
  isLast = false,
  dark = false
}) {
  const text = dark ? '#fff' : '#000';
  const sec = dark ? 'rgba(235,235,245,0.6)' : 'rgba(60,60,67,0.6)';
  const ter = dark ? 'rgba(235,235,245,0.3)' : 'rgba(60,60,67,0.3)';
  const sep = dark ? 'rgba(84,84,88,0.65)' : 'rgba(60,60,67,0.12)';
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      minHeight: 52,
      padding: '0 16px',
      position: 'relative',
      fontFamily: '-apple-system, system-ui',
      fontSize: 17,
      letterSpacing: -0.43
    }
  }, icon && /*#__PURE__*/React.createElement("div", {
    style: {
      width: 30,
      height: 30,
      borderRadius: 7,
      background: icon,
      marginRight: 12,
      flexShrink: 0
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      color: text
    }
  }, title), detail && /*#__PURE__*/React.createElement("span", {
    style: {
      color: sec,
      marginRight: 6
    }
  }, detail), chevron && /*#__PURE__*/React.createElement("svg", {
    width: "8",
    height: "14",
    viewBox: "0 0 8 14",
    style: {
      flexShrink: 0
    }
  }, /*#__PURE__*/React.createElement("path", {
    d: "M1 1l6 6-6 6",
    stroke: ter,
    strokeWidth: "2",
    fill: "none",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  })), !isLast && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      bottom: 0,
      right: 0,
      left: icon ? 58 : 16,
      height: 0.5,
      background: sep
    }
  }));
}
function IOSList({
  header,
  children,
  dark = false
}) {
  const hc = dark ? 'rgba(235,235,245,0.6)' : 'rgba(60,60,67,0.6)';
  const bg = dark ? '#1C1C1E' : '#fff';
  return /*#__PURE__*/React.createElement("div", null, header && /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: '-apple-system, system-ui',
      fontSize: 13,
      color: hc,
      textTransform: 'uppercase',
      padding: '8px 36px 6px',
      letterSpacing: -0.08
    }
  }, header), /*#__PURE__*/React.createElement("div", {
    style: {
      background: bg,
      borderRadius: 26,
      margin: '0 16px',
      overflow: 'hidden'
    }
  }, children));
}

// ─────────────────────────────────────────────────────────────
// Device frame
// ─────────────────────────────────────────────────────────────
function IOSDevice({
  children,
  width = 402,
  height = 874,
  dark = false,
  title,
  keyboard = false
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      width,
      height,
      borderRadius: 48,
      overflow: 'hidden',
      position: 'relative',
      background: dark ? '#000' : '#F2F2F7',
      boxShadow: '0 40px 80px rgba(0,0,0,0.18), 0 0 0 1px rgba(0,0,0,0.12)',
      fontFamily: '-apple-system, system-ui, sans-serif',
      WebkitFontSmoothing: 'antialiased'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: 11,
      left: '50%',
      transform: 'translateX(-50%)',
      width: 126,
      height: 37,
      borderRadius: 24,
      background: '#000',
      zIndex: 50
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: 0,
      left: 0,
      right: 0,
      zIndex: 10
    }
  }, /*#__PURE__*/React.createElement(IOSStatusBar, {
    dark: dark
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      height: '100%',
      display: 'flex',
      flexDirection: 'column'
    }
  }, title !== undefined && /*#__PURE__*/React.createElement(IOSNavBar, {
    title: title,
    dark: dark
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      overflow: 'auto'
    }
  }, children), keyboard && /*#__PURE__*/React.createElement(IOSKeyboard, {
    dark: dark
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      bottom: 0,
      left: 0,
      right: 0,
      zIndex: 60,
      height: 34,
      display: 'flex',
      justifyContent: 'center',
      alignItems: 'flex-end',
      paddingBottom: 8,
      pointerEvents: 'none'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 139,
      height: 5,
      borderRadius: 100,
      background: dark ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.25)'
    }
  })));
}

// ─────────────────────────────────────────────────────────────
// Keyboard — iOS 26 liquid glass
// ─────────────────────────────────────────────────────────────
function IOSKeyboard({
  dark = false
}) {
  const glyph = dark ? 'rgba(255,255,255,0.7)' : '#595959';
  const sugg = dark ? 'rgba(255,255,255,0.6)' : '#333';
  const keyBg = dark ? 'rgba(255,255,255,0.22)' : 'rgba(255,255,255,0.85)';

  // special-key icons
  const icons = {
    shift: /*#__PURE__*/React.createElement("svg", {
      width: "19",
      height: "17",
      viewBox: "0 0 19 17"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M9.5 1L1 9.5h4.5V16h8V9.5H18L9.5 1z",
      fill: glyph
    })),
    del: /*#__PURE__*/React.createElement("svg", {
      width: "23",
      height: "17",
      viewBox: "0 0 23 17"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M7 1h13a2 2 0 012 2v11a2 2 0 01-2 2H7l-6-7.5L7 1z",
      fill: "none",
      stroke: glyph,
      strokeWidth: "1.6",
      strokeLinejoin: "round"
    }), /*#__PURE__*/React.createElement("path", {
      d: "M10 5l7 7M17 5l-7 7",
      stroke: glyph,
      strokeWidth: "1.6",
      strokeLinecap: "round"
    })),
    ret: /*#__PURE__*/React.createElement("svg", {
      width: "20",
      height: "14",
      viewBox: "0 0 20 14"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M18 1v6H4m0 0l4-4M4 7l4 4",
      fill: "none",
      stroke: "#fff",
      strokeWidth: "1.8",
      strokeLinecap: "round",
      strokeLinejoin: "round"
    }))
  };
  const key = (content, {
    w,
    flex,
    ret,
    fs = 25,
    k
  } = {}) => /*#__PURE__*/React.createElement("div", {
    key: k,
    style: {
      height: 42,
      borderRadius: 8.5,
      flex: flex ? 1 : undefined,
      width: w,
      minWidth: 0,
      background: ret ? '#08f' : keyBg,
      boxShadow: '0 1px 0 rgba(0,0,0,0.075)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: '-apple-system, "SF Compact", system-ui',
      fontSize: fs,
      fontWeight: 458,
      color: ret ? '#fff' : glyph
    }
  }, content);
  const row = (keys, pad = 0) => /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 6.5,
      justifyContent: 'center',
      padding: `0 ${pad}px`
    }
  }, keys.map(l => key(l, {
    flex: true,
    k: l
  })));
  return /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      zIndex: 15,
      borderRadius: 27,
      overflow: 'hidden',
      padding: '11px 0 2px',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      boxShadow: dark ? '0 -2px 20px rgba(0,0,0,0.09)' : '0 -1px 6px rgba(0,0,0,0.018), 0 -3px 20px rgba(0,0,0,0.012)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      borderRadius: 27,
      backdropFilter: 'blur(12px) saturate(180%)',
      WebkitBackdropFilter: 'blur(12px) saturate(180%)',
      background: dark ? 'rgba(120,120,128,0.14)' : 'rgba(255,255,255,0.25)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      borderRadius: 27,
      boxShadow: dark ? 'inset 1.5px 1.5px 1px rgba(255,255,255,0.15)' : 'inset 1.5px 1.5px 1px rgba(255,255,255,0.7), inset -1px -1px 1px rgba(255,255,255,0.4)',
      border: dark ? '0.5px solid rgba(255,255,255,0.15)' : '0.5px solid rgba(0,0,0,0.06)',
      pointerEvents: 'none'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 20,
      alignItems: 'center',
      padding: '8px 22px 13px',
      width: '100%',
      boxSizing: 'border-box',
      position: 'relative'
    }
  }, ['"The"', 'the', 'to'].map((w, i) => /*#__PURE__*/React.createElement(React.Fragment, {
    key: i
  }, i > 0 && /*#__PURE__*/React.createElement("div", {
    style: {
      width: 1,
      height: 25,
      background: '#ccc',
      opacity: 0.3
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      textAlign: 'center',
      fontFamily: '-apple-system, system-ui',
      fontSize: 17,
      color: sugg,
      letterSpacing: -0.43,
      lineHeight: '22px'
    }
  }, w)))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 13,
      padding: '0 6.5px',
      width: '100%',
      boxSizing: 'border-box',
      position: 'relative'
    }
  }, row(['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p']), row(['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'], 20), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 14.25,
      alignItems: 'center'
    }
  }, key(icons.shift, {
    w: 45,
    k: 'shift'
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 6.5,
      flex: 1
    }
  }, ['z', 'x', 'c', 'v', 'b', 'n', 'm'].map(l => key(l, {
    flex: true,
    k: l
  }))), key(icons.del, {
    w: 45,
    k: 'del'
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 6,
      alignItems: 'center'
    }
  }, key('ABC', {
    w: 92.25,
    fs: 18,
    k: 'abc'
  }), key('', {
    flex: true,
    k: 'space'
  }), key(icons.ret, {
    w: 92.25,
    ret: true,
    k: 'ret'
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      height: 56,
      width: '100%',
      position: 'relative'
    }
  }));
}
Object.assign(window, {
  IOSDevice,
  IOSStatusBar,
  IOSNavBar,
  IOSGlassPill,
  IOSList,
  IOSListRow,
  IOSKeyboard
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/ios-frame.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/nav.jsx
try { (() => {
/* Surgery Hub — instrumentiste — the custom "menu bar".
   Two expressions of ONE navigation identity:
   • MobileDock    — a floating capsule dock; the active tab grows into a
                     brand-green pill that reveals its label (icon-only when idle).
   • DesktopSidebar— the same identity widened into a left rail for desktop,
                     with brand mark, grouped nav, preferences + a user card.
   Function is unchanged from the stock bottom nav; only the styling is bespoke. */

(() => {
  const NIc = ({
    n
  }) => /*#__PURE__*/React.createElement("i", {
    "data-lucide": n
  });
  const ND = window.SH_DATA;

  /* ---------------- Mobile: floating capsule dock ---------------- */
  function MobileDock({
    value,
    onChange,
    items
  }) {
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-dock"
    }, /*#__PURE__*/React.createElement("nav", {
      className: "sh-dock-bar",
      "aria-label": "Navigation principale"
    }, items.map(it => /*#__PURE__*/React.createElement("button", {
      key: it.id,
      className: "sh-dock-item",
      "data-active": value === it.id ? 'true' : undefined,
      "aria-current": value === it.id ? 'page' : undefined,
      "aria-label": it.label,
      onClick: () => onChange(it.id)
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-dock-ic"
    }, it.icon, it.badge > 0 && /*#__PURE__*/React.createElement("span", {
      className: "sh-dock-badge"
    }, it.badge)), /*#__PURE__*/React.createElement("span", {
      className: "sh-dock-label"
    }, it.label)))));
  }

  /* ---------------- Desktop: left navigation rail ---------------- */
  function DesktopSidebar({
    tab,
    activeName,
    onNav,
    onProfile,
    onPreferences,
    theme,
    setTheme
  }) {
    const p = ND.profile;
    const primary = [{
      id: 'today',
      label: "Aujourd'hui",
      icon: 'house'
    }, {
      id: 'planning',
      label: 'Planning',
      icon: 'calendar-days'
    }, {
      id: 'offers',
      label: 'Offres',
      icon: 'tag',
      badge: ND.offers.length
    }, {
      id: 'mesmissions',
      label: 'Mes missions',
      icon: 'briefcase-medical'
    }, {
      id: 'absences',
      label: 'Absences',
      icon: 'plane'
    }];
    const initials = p.name.split(' ').map(w => w[0]).slice(0, 2).join('');
    return /*#__PURE__*/React.createElement("aside", {
      className: "sh-side"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-side-brand"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-side-mark",
      "aria-hidden": "true"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: "plus"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-side-word"
    }, /*#__PURE__*/React.createElement("b", null, "Surgery Hub"), /*#__PURE__*/React.createElement("small", null, "Espace instrumentiste"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-side-sect"
    }, "Navigation"), /*#__PURE__*/React.createElement("nav", {
      className: "sh-side-nav",
      "aria-label": "Navigation principale"
    }, primary.map(it => /*#__PURE__*/React.createElement("button", {
      key: it.id,
      className: "sh-side-item",
      "data-active": activeName === it.id ? 'true' : undefined,
      "aria-current": activeName === it.id ? 'page' : undefined,
      onClick: () => onNav(it.id)
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-side-ic"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: it.icon
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-side-lbl"
    }, it.label), it.badge > 0 && /*#__PURE__*/React.createElement("span", {
      className: "sh-side-count"
    }, it.badge)))), /*#__PURE__*/React.createElement("div", {
      className: "sh-side-foot"
    }, /*#__PURE__*/React.createElement("button", {
      className: "sh-side-item",
      "data-active": activeName === 'preferences' ? 'true' : undefined,
      onClick: onPreferences
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-side-ic"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: "sliders-horizontal"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-side-lbl"
    }, "Pr\xE9f\xE9rences")), /*#__PURE__*/React.createElement("div", {
      className: "sh-side-theme",
      role: "group",
      "aria-label": "Th\xE8me"
    }, /*#__PURE__*/React.createElement("button", {
      type: "button",
      className: "sh-side-theme-opt",
      "data-active": theme === 'light' ? 'true' : undefined,
      onClick: () => setTheme('light'),
      "aria-label": "Th\xE8me clair"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: "sun"
    })), /*#__PURE__*/React.createElement("button", {
      type: "button",
      className: "sh-side-theme-opt",
      "data-active": theme === 'dark' ? 'true' : undefined,
      onClick: () => setTheme('dark'),
      "aria-label": "Th\xE8me sombre"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: "moon"
    }))), /*#__PURE__*/React.createElement("button", {
      className: "sh-side-user",
      onClick: onProfile,
      "aria-label": "Profil"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-side-ava"
    }, initials), /*#__PURE__*/React.createElement("span", {
      className: "sh-side-userinfo"
    }, /*#__PURE__*/React.createElement("b", null, p.name), /*#__PURE__*/React.createElement("small", null, p.role)), /*#__PURE__*/React.createElement("span", {
      className: "sh-side-userchev"
    }, /*#__PURE__*/React.createElement(NIc, {
      n: "chevron-right"
    })))));
  }
  Object.assign(window, {
    MobileDock,
    DesktopSidebar
  });
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/nav.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/screens.jsx
try { (() => {
/* Surgery Hub — instrumentiste — ALL screen components.
   Defined here once, exported to window so both the mobile shell and the
   desktop shell (shells.jsx) can render the very same screens. Keeping them
   in one script avoids cross-<script> top-level const collisions. */

/* IIFE so these top-level consts stay out of the shared global lexical scope
   (classic <script> top-level let/const are otherwise global and collide). */
(() => {
  const SHDS = window.DesignSystem_d99104;
  const {
    Button,
    IconButton,
    Input,
    Select,
    Switch,
    Checkbox,
    Toast,
    StatusPill,
    Tag,
    Avatar,
    AppBar,
    BottomNav,
    MissionHero,
    MissionCard,
    OfferCard,
    SectionHeader,
    InfoBanner,
    EmptyState,
    ListRow
  } = SHDS;
  const Ic = ({
    n
  }) => /*#__PURE__*/React.createElement("i", {
    "data-lucide": n
  });
  const D = window.SH_DATA;
  function refreshIcons() {
    setTimeout(() => window.lucide && window.lucide.createIcons(), 30);
  }
  function accentFor(status) {
    return {
      avenir: 'info',
      aencoder: 'warn',
      terminee: 'muted',
      refusee: 'muted',
      encours: 'brand',
      proposee: 'brand'
    }[status] || 'brand';
  }

  /* ---------- date helpers (FR) ---------- */
  const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const DOWS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  const TODAY_ISO = '2026-06-10';
  function parseISO(s) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  function fmtDay(s) {
    const d = parseISO(s);
    return `${DOWS[d.getDay()]} ${d.getDate()} ${MONTHS[d.getMonth()]}`;
  }
  function fmtRange(start, end) {
    const a = parseISO(start),
      b = parseISO(end);
    if (start === end) return `${fmtDay(start)} ${a.getFullYear()}`;
    if (a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear()) return `${a.getDate()} → ${b.getDate()} ${MONTHS[a.getMonth()]} ${a.getFullYear()}`;
    return `${a.getDate()} ${MONTHS[a.getMonth()]} → ${b.getDate()} ${MONTHS[b.getMonth()]} ${b.getFullYear()}`;
  }
  function daysBetween(start, end) {
    return Math.round((parseISO(end) - parseISO(start)) / 86400000) + 1;
  }
  function isPast(end) {
    return parseISO(end) < parseISO(TODAY_ISO);
  }

  /* ---------- Today ---------- */
  function TodayScreen({
    nav
  }) {
    const m = D.todayMission;
    const toEncode = D.missions.filter(x => x.status === 'aencoder' && x.id !== m.id);
    const next = D.missions.find(x => x.when === 'avenir' && x.id !== m.id);
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-greeting-row"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-greeting"
    }, /*#__PURE__*/React.createElement("h1", null, "Bonjour, ", D.profile.name.split(' ')[0], " !"), /*#__PURE__*/React.createElement("span", {
      className: "date"
    }, "Mercredi 10 juin \xB7 ", m ? '1 mission' : 'aucune mission')), /*#__PURE__*/React.createElement("button", {
      className: "sh-avatar-btn",
      onClick: () => nav.go('profile'),
      "aria-label": "Profil"
    }, /*#__PURE__*/React.createElement(Avatar, {
      name: D.profile.name,
      size: "md"
    }))), m ? /*#__PURE__*/React.createElement(MissionHero, {
      badge: "En cours",
      time: m.time,
      site: m.site,
      surgeon: m.surgeon,
      ctaLabel: "Encoder la mission",
      onCta: () => nav.go('encoding', {
        id: m.id
      })
    }) : /*#__PURE__*/React.createElement("div", {
      className: "sh-nomission"
    }, /*#__PURE__*/React.createElement("h3", null, "Aucune mission aujourd'hui"), /*#__PURE__*/React.createElement("p", null, "Consultez les offres disponibles ci-dessous.")), toEncode.length > 0 && /*#__PURE__*/React.createElement("button", {
      className: "sh-alert",
      onClick: () => nav.go('detail', {
        id: toEncode[0].id
      })
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-alert-ic"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "file-pen-line"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-alert-body"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-alert-title"
    }, toEncode.length, " mission \xE0 encoder"), /*#__PURE__*/React.createElement("span", {
      className: "sh-alert-text"
    }, "Finalisez l'encodage pour comptabiliser vos heures.")), /*#__PURE__*/React.createElement("span", {
      className: "sh-alert-chev"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "chevron-right"
    }))), /*#__PURE__*/React.createElement("div", {
      className: "sh-quickrow"
    }, /*#__PURE__*/React.createElement("button", {
      className: "sh-quick",
      onClick: () => nav.go('declare')
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-quick-ic"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "plus-circle"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-quick-label"
    }, "D\xE9clarer une mission")), /*#__PURE__*/React.createElement("button", {
      className: "sh-quick",
      onClick: () => nav.go('absences')
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-quick-ic",
      "data-tone": "blue"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "plane"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-quick-label"
    }, "Signaler une absence"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Offres disponibles",
      count: D.offers.length,
      action: /*#__PURE__*/React.createElement("button", {
        className: "sh-link",
        onClick: () => nav.setTab('offers')
      }, "Tout voir")
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, D.offers.slice(0, 2).map(o => /*#__PURE__*/React.createElement(OfferCard, {
      key: o.id,
      site: o.site,
      date: o.date,
      time: o.time,
      tags: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Tag, {
        tone: "accent"
      }, o.type), /*#__PURE__*/React.createElement(Tag, {
        tone: "outline"
      }, o.specialty)),
      actions: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Button, {
        variant: "ghost",
        onClick: () => nav.toast('Offre refusée')
      }, "Refuser"), /*#__PURE__*/React.createElement(Button, {
        variant: "primary",
        onClick: () => nav.toast('Mission acceptée · ' + o.site)
      }, "Prendre"))
    })))), next && /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Prochaine mission",
      action: /*#__PURE__*/React.createElement("button", {
        className: "sh-link",
        onClick: () => nav.go('mesmissions')
      }, "Tout voir")
    }), /*#__PURE__*/React.createElement(MissionCard, {
      site: next.site,
      date: next.date,
      time: next.time,
      accentTone: accentFor(next.status),
      status: /*#__PURE__*/React.createElement(StatusPill, {
        status: next.status
      }),
      tags: /*#__PURE__*/React.createElement(Tag, {
        tone: "outline"
      }, next.specialty),
      onClick: () => nav.go('detail', {
        id: next.id
      })
    })));
  }

  /* ---------- Offers ---------- */
  function OffersScreen({
    nav
  }) {
    const [filter, setFilter] = React.useState('Tous');
    const chips = ['Tous', 'Bloc opératoire', 'Stérilisation', 'Cette semaine', 'Bruxelles'];
    const list = filter === 'Tous' ? D.offers : D.offers.filter(o => o.type === filter || o.city.includes(filter) || filter === 'Cette semaine');
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-chips"
    }, chips.map(c => /*#__PURE__*/React.createElement("button", {
      key: c,
      className: "sh-chip",
      "data-active": filter === c ? 'true' : undefined,
      onClick: () => setFilter(c)
    }, c))), /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, list.map(o => /*#__PURE__*/React.createElement(OfferCard, {
      key: o.id,
      site: o.site,
      date: o.date,
      time: o.time,
      tags: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Tag, {
        tone: "accent"
      }, o.type), /*#__PURE__*/React.createElement(Tag, {
        tone: "outline"
      }, o.specialty), /*#__PURE__*/React.createElement(Tag, null, o.city)),
      actions: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Button, {
        variant: "ghost",
        onClick: () => nav.toast('Offre refusée')
      }, "Refuser"), /*#__PURE__*/React.createElement(Button, {
        variant: "primary",
        onClick: () => nav.toast('Mission acceptée · ' + o.site)
      }, "Prendre"))
    })), list.length === 0 && /*#__PURE__*/React.createElement(EmptyState, {
      boxed: true,
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "search-x"
      }),
      title: "Aucune offre",
      text: "Aucune offre ne correspond \xE0 ce filtre pour le moment."
    })));
  }

  /* ---------- Mes missions ---------- */
  function MesMissionsScreen({
    nav
  }) {
    const [tab, setTab] = React.useState('avenir');
    const tabs = [{
      id: 'avenir',
      label: 'À venir'
    }, {
      id: 'aencoder',
      label: 'À encoder'
    }, {
      id: 'historique',
      label: 'Historique'
    }];
    const list = D.missions.filter(m => m.when === tab);
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-chips"
    }, tabs.map(t => {
      const count = D.missions.filter(m => m.when === t.id).length;
      return /*#__PURE__*/React.createElement("button", {
        key: t.id,
        className: "sh-chip",
        "data-active": tab === t.id ? 'true' : undefined,
        onClick: () => setTab(t.id)
      }, t.label, count ? ' · ' + count : '');
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, list.map(m => /*#__PURE__*/React.createElement(MissionCard, {
      key: m.id,
      site: m.site,
      date: m.date,
      time: m.time,
      accentTone: accentFor(m.status),
      status: /*#__PURE__*/React.createElement(StatusPill, {
        status: m.status
      }),
      tags: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Tag, {
        tone: "outline"
      }, m.type), /*#__PURE__*/React.createElement(Tag, null, m.specialty)),
      onClick: () => nav.go('detail', {
        id: m.id
      })
    })), list.length === 0 && /*#__PURE__*/React.createElement(EmptyState, {
      boxed: true,
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "calendar-x"
      }),
      title: "Rien ici",
      text: "Vous n'avez aucune mission dans cette cat\xE9gorie."
    })));
  }

  /* ---------- Planning ---------- */
  function PlanningScreen() {
    const first = new Date(2026, 5, 1);
    const offset = (first.getDay() + 6) % 7; // Monday-first
    const days = 30;
    const dow = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
    const dotColor = {
      avenir: 'var(--blue-500)',
      aencoder: 'var(--amber-500)',
      terminee: 'var(--gray-400)',
      encours: 'var(--green-500)',
      proposee: 'var(--green-300)'
    };
    const cells = [];
    for (let i = 0; i < offset; i++) cells.push(null);
    for (let d = 1; d <= days; d++) cells.push(d);
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-cal"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-cal-head"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-cal-month"
    }, "Juin 2026"), /*#__PURE__*/React.createElement("div", {
      className: "sh-cal-nav"
    }, /*#__PURE__*/React.createElement("button", {
      "aria-label": "Mois pr\xE9c\xE9dent"
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "2",
      strokeLinecap: "round",
      strokeLinejoin: "round"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M15 18l-6-6 6-6"
    }))), /*#__PURE__*/React.createElement("button", {
      "aria-label": "Mois suivant"
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "2",
      strokeLinecap: "round",
      strokeLinejoin: "round"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M9 6l6 6-6 6"
    }))))), /*#__PURE__*/React.createElement("div", {
      className: "sh-cal-grid"
    }, dow.map((d, i) => /*#__PURE__*/React.createElement("div", {
      className: "sh-cal-dow",
      key: i
    }, d)), cells.map((d, i) => /*#__PURE__*/React.createElement("div", {
      key: i,
      className: "sh-cal-day",
      "data-empty": d == null ? 'true' : undefined,
      "data-today": d === 10 ? 'true' : undefined
    }, d, d && D.planning[d] && /*#__PURE__*/React.createElement("span", {
      className: "sh-cal-dot",
      style: {
        background: dotColor[D.planning[d]]
      }
    })))), /*#__PURE__*/React.createElement("div", {
      className: "sh-divider"
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-legend"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-item"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-dot",
      style: {
        background: 'var(--blue-500)'
      }
    }), "\xC0 venir"), /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-item"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-dot",
      style: {
        background: 'var(--green-500)'
      }
    }), "En cours"), /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-item"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-dot",
      style: {
        background: 'var(--amber-500)'
      }
    }), "\xC0 encoder"), /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-item"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-dot",
      style: {
        background: 'var(--gray-400)'
      }
    }), "Termin\xE9e"), /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-item"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-legend-dot",
      style: {
        background: 'var(--green-300)'
      }
    }), "Propos\xE9e"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Cette semaine"
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, D.missions.filter(m => m.when === 'avenir').map(m => /*#__PURE__*/React.createElement(MissionCard, {
      key: m.id,
      site: m.site,
      date: m.date,
      time: m.time,
      accentTone: accentFor(m.status),
      status: /*#__PURE__*/React.createElement(StatusPill, {
        status: m.status
      }),
      chevron: false
    })))));
  }

  /* ---------- Notifications ---------- */
  function NotificationsScreen() {
    const iconFor = {
      attribuee: 'badge-check',
      encodage: 'file-text',
      offre: 'tag',
      rappel: 'bell'
    };
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, D.notifications.map(n => /*#__PURE__*/React.createElement("div", {
      key: n.id,
      className: "sh-notif",
      "data-unread": n.unread ? 'true' : undefined
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-notif-ic"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: iconFor[n.kind] || 'bell'
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-notif-body"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-notif-title"
    }, n.title, n.unread && /*#__PURE__*/React.createElement("span", {
      className: "sh-notif-unread"
    })), /*#__PURE__*/React.createElement("span", {
      className: "sh-notif-text"
    }, n.text), /*#__PURE__*/React.createElement("span", {
      className: "sh-notif-time"
    }, n.time))))));
  }

  /* ---------- Déclarer une mission ---------- */
  function DeclareScreen({
    nav
  }) {
    const [start, setStart] = React.useState('2026-06-10T19:00');
    const [end, setEnd] = React.useState('2026-06-10T20:00');
    const dur = computeDuration(start, end);
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-greeting"
    }, /*#__PURE__*/React.createElement("h1", {
      style: {
        fontSize: 'var(--text-xl)'
      }
    }, "D\xE9clarer une mission")), /*#__PURE__*/React.createElement(InfoBanner, {
      tone: "info"
    }, "D\xE9clarez une disponibilit\xE9 ou une mission r\xE9alis\xE9e hors plateforme. Elle sera v\xE9rifi\xE9e par Surgery Hub."), /*#__PURE__*/React.createElement("div", {
      className: "sh-form"
    }, /*#__PURE__*/React.createElement(Select, {
      label: "Site",
      options: ['Sélectionner…', ...D.sites]
    }), /*#__PURE__*/React.createElement(Select, {
      label: "Chirurgien",
      options: ['Sélectionner…', ...D.surgeons]
    }), /*#__PURE__*/React.createElement(Select, {
      label: "Type",
      options: D.types
    }), /*#__PURE__*/React.createElement(Input, {
      label: "D\xE9but",
      type: "datetime-local",
      value: start,
      onChange: e => setStart(e.target.value)
    }), /*#__PURE__*/React.createElement(Input, {
      label: "Fin",
      type: "datetime-local",
      value: end,
      onChange: e => setEnd(e.target.value)
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-form-duration"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "hourglass"
    }), " Dur\xE9e : ", /*#__PURE__*/React.createElement("span", {
      className: "data"
    }, dur)), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      style: {
        fontSize: 'var(--text-sm)',
        fontWeight: 600,
        color: 'var(--text-strong)',
        display: 'block',
        marginBottom: 6
      }
    }, "Commentaire (optionnel)"), /*#__PURE__*/React.createElement("textarea", {
      className: "sh-textarea",
      placeholder: "Pr\xE9cisions sur la mission\u2026"
    }))), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        gap: 10
      }
    }, /*#__PURE__*/React.createElement(Button, {
      variant: "ghost",
      fullWidth: true,
      onClick: () => nav.back()
    }, "Annuler"), /*#__PURE__*/React.createElement(Button, {
      variant: "primary",
      fullWidth: true,
      onClick: () => {
        nav.toast('Mission déclarée');
        nav.back();
      }
    }, "D\xE9clarer")));
  }
  function computeDuration(start, end) {
    try {
      const a = new Date(start),
        b = new Date(end);
      let mins = Math.max(0, (b - a) / 60000);
      const h = Math.floor(mins / 60),
        m = Math.round(mins % 60);
      return h + 'h' + String(m).padStart(2, '0');
    } catch (e) {
      return '—';
    }
  }

  /* ---------- Absences ---------- */
  function AbsencesScreen({
    nav
  }) {
    const [list, setList] = React.useState(() => [...D.absences].sort((a, b) => a.start < b.start ? 1 : -1));
    const [start, setStart] = React.useState('');
    const [end, setEnd] = React.useState('');
    const [comment, setComment] = React.useState('');
    const [filter, setFilter] = React.useState('avenir');
    const valid = start && end && end >= start;
    const dur = valid ? daysBetween(start, end) : null;
    const submit = () => {
      if (!valid) {
        nav.toast('Choisissez une période valide');
        return;
      }
      const item = {
        id: 'a' + Date.now(),
        start,
        end,
        comment: comment.trim()
      };
      setList(prev => [item, ...prev].sort((a, b) => a.start < b.start ? 1 : -1));
      setStart('');
      setEnd('');
      setComment('');
      setFilter(isPast(end) ? 'passees' : 'avenir');
      nav.toast('Absence enregistrée');
      refreshIcons();
    };
    const remove = id => {
      setList(prev => prev.filter(x => x.id !== id));
      nav.toast('Absence supprimée');
      refreshIcons();
    };
    const upcoming = list.filter(a => !isPast(a.end));
    const past = list.filter(a => isPast(a.end));
    const shown = filter === 'avenir' ? upcoming : past;
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement(InfoBanner, {
      tone: "info"
    }, "Indiquez les p\xE9riodes o\xF9 vous n'\xEAtes pas disponible. Aucune offre ne vous sera propos\xE9e sur ces dates."), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "calendar-off"
    }), " Nouvelle absence"), /*#__PURE__*/React.createElement("div", {
      className: "sh-abs-form"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-abs-dates"
    }, /*#__PURE__*/React.createElement(Input, {
      label: "Du",
      type: "date",
      value: start,
      max: end || undefined,
      onChange: e => setStart(e.target.value)
    }), /*#__PURE__*/React.createElement("span", {
      className: "sh-abs-arrow"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "arrow-right"
    })), /*#__PURE__*/React.createElement(Input, {
      label: "Au",
      type: "date",
      value: end,
      min: start || undefined,
      onChange: e => setEnd(e.target.value)
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-abs-durline",
      "data-on": valid ? 'true' : undefined
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "hourglass"
    }), valid ? /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("span", {
      className: "data"
    }, dur), " jour", dur > 1 ? 's' : '', " d'indisponibilit\xE9") : /*#__PURE__*/React.createElement("span", null, "S\xE9lectionnez une date de d\xE9but et de fin")), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Commentaire (optionnel)"), /*#__PURE__*/React.createElement("textarea", {
      className: "sh-textarea",
      placeholder: "ex. cong\xE9s, formation, indisponible\u2026",
      value: comment,
      onChange: e => setComment(e.target.value)
    })), /*#__PURE__*/React.createElement(Button, {
      variant: "primary",
      fullWidth: true,
      size: "lg",
      leadingIcon: /*#__PURE__*/React.createElement(Ic, {
        n: "check"
      }),
      onClick: submit
    }, "Enregistrer l'absence"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-chips"
    }, /*#__PURE__*/React.createElement("button", {
      className: "sh-chip",
      "data-active": filter === 'avenir' ? 'true' : undefined,
      onClick: () => setFilter('avenir')
    }, "\xC0 venir", upcoming.length ? ' · ' + upcoming.length : ''), /*#__PURE__*/React.createElement("button", {
      className: "sh-chip",
      "data-active": filter === 'passees' ? 'true' : undefined,
      onClick: () => setFilter('passees')
    }, "Pass\xE9es", past.length ? ' · ' + past.length : '')), /*#__PURE__*/React.createElement("div", {
      className: "sh-list"
    }, shown.map(a => {
      const past = isPast(a.end);
      const d = parseISO(a.start);
      return /*#__PURE__*/React.createElement("div", {
        className: "sh-abs-row",
        key: a.id,
        "data-past": past ? 'true' : undefined
      }, /*#__PURE__*/React.createElement("span", {
        className: "sh-abs-chip"
      }, /*#__PURE__*/React.createElement("b", null, d.getDate()), /*#__PURE__*/React.createElement("span", null, MONTHS[d.getMonth()].replace('.', ''))), /*#__PURE__*/React.createElement("div", {
        className: "sh-abs-body"
      }, /*#__PURE__*/React.createElement("div", {
        className: "sh-abs-range"
      }, fmtRange(a.start, a.end)), /*#__PURE__*/React.createElement("div", {
        className: "sh-abs-meta"
      }, daysBetween(a.start, a.end), " jour", daysBetween(a.start, a.end) > 1 ? 's' : '', a.comment ? ' · ' + a.comment : '')), past ? /*#__PURE__*/React.createElement(Tag, {
        tone: "outline"
      }, "Pass\xE9e") : /*#__PURE__*/React.createElement(IconButton, {
        label: "Supprimer l'absence",
        onClick: () => remove(a.id)
      }, /*#__PURE__*/React.createElement(Ic, {
        n: "trash-2"
      })));
    }), shown.length === 0 && /*#__PURE__*/React.createElement(EmptyState, {
      boxed: true,
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "calendar-check"
      }),
      title: filter === 'avenir' ? 'Aucune absence à venir' : 'Aucune absence passée',
      text: filter === 'avenir' ? 'Vous êtes disponible pour toutes les missions à venir.' : 'Vos absences passées apparaîtront ici.'
    }))));
  }

  /* ---------- Mission detail ---------- */
  function DetailScreen({
    nav,
    id
  }) {
    const m = D.missions.find(x => x.id === id) || D.todayMission;
    const addr = m.address || 'Boulevard du Triomphe 201, 1160 Auderghem';
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-detail-head"
    }, /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: 12
      }
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-detail-site"
    }, m.site), /*#__PURE__*/React.createElement(StatusPill, {
      status: m.status
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-tagwrap"
    }, /*#__PURE__*/React.createElement(Tag, {
      tone: "accent"
    }, m.type), /*#__PURE__*/React.createElement(Tag, {
      tone: "outline"
    }, m.specialty))), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-detail-kv"
    }, /*#__PURE__*/React.createElement(KV, {
      icon: "calendar-days",
      label: "Date",
      value: m.date,
      data: true
    }), /*#__PURE__*/React.createElement(KV, {
      icon: "clock",
      label: "Horaire",
      value: m.time,
      data: true
    }), /*#__PURE__*/React.createElement(KV, {
      icon: "stethoscope",
      label: "Chirurgien",
      value: m.surgeon || 'Dr. Pierre Dubois'
    }), /*#__PURE__*/React.createElement(KV, {
      icon: "map-pin",
      label: "Adresse",
      value: addr
    }))), m.status === 'aencoder' && /*#__PURE__*/React.createElement(InfoBanner, {
      tone: "warning"
    }, "Cette mission est termin\xE9e. Pensez \xE0 l'encoder pour comptabiliser vos heures."), /*#__PURE__*/React.createElement("div", {
      style: {
        display: 'flex',
        flexDirection: 'column',
        gap: 10
      }
    }, m.status === 'aencoder' && /*#__PURE__*/React.createElement(Button, {
      variant: "primary",
      fullWidth: true,
      size: "lg",
      leadingIcon: /*#__PURE__*/React.createElement(Ic, {
        n: "file-pen-line"
      }),
      onClick: () => nav.go('encoding', {
        id: m.id
      })
    }, "Encoder la mission"), m.status === 'avenir' && /*#__PURE__*/React.createElement(Button, {
      variant: "secondary",
      fullWidth: true,
      size: "lg",
      leadingIcon: /*#__PURE__*/React.createElement(Ic, {
        n: "navigation"
      }),
      onClick: () => nav.toast('Itinéraire ouvert')
    }, "Itin\xE9raire"), /*#__PURE__*/React.createElement(Button, {
      variant: "ghost",
      fullWidth: true,
      onClick: () => nav.toast('Contact envoyé')
    }, "Contacter Surgery Hub")));
  }
  function KV({
    icon,
    label,
    value,
    data
  }) {
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-kvrow"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-kvrow-ic"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: icon
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-kvrow-body"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-kvrow-label"
    }, label), /*#__PURE__*/React.createElement("span", {
      className: 'sh-kvrow-value' + (data ? ' data' : '')
    }, value)));
  }

  /* ---------- Encodage ---------- */
  function EncodingScreen({
    nav,
    id
  }) {
    const [hours, setHours] = React.useState('');
    const [items, setItems] = React.useState([]);
    const add = () => {
      const next = D.interventionCatalog[items.length % D.interventionCatalog.length];
      setItems(prev => [...prev, {
        id: Date.now(),
        name: next,
        count: 1
      }]);
      refreshIcons();
    };
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-subback"
    }, /*#__PURE__*/React.createElement("button", {
      onClick: () => nav.back()
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "2",
      strokeLinecap: "round",
      strokeLinejoin: "round"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M15 18l-6-6 6-6"
    })), "Mission"), /*#__PURE__*/React.createElement("span", {
      className: "cur"
    }, "\xB7 Encodage #", id || 216)), /*#__PURE__*/React.createElement(InfoBanner, {
      tone: "info"
    }, "Pour que les heures soient comptabilis\xE9es, l'encodage de la mission doit \xEAtre termin\xE9."), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "clock"
    }), " Heures prest\xE9es"), /*#__PURE__*/React.createElement("div", {
      style: {
        padding: '14px 16px'
      }
    }, /*#__PURE__*/React.createElement(Input, {
      type: "text",
      inputMode: "numeric",
      placeholder: "ex. 10h00 \u2192 20h00",
      value: hours,
      onChange: e => setHours(e.target.value)
    }))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Interventions",
      count: items.length || null,
      action: /*#__PURE__*/React.createElement(Button, {
        variant: "ghost",
        size: "sm",
        leadingIcon: /*#__PURE__*/React.createElement(Ic, {
          n: "plus"
        }),
        onClick: add
      }, "Ajouter")
    }), items.length === 0 ? /*#__PURE__*/React.createElement(EmptyState, {
      boxed: true,
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "clipboard-list"
      }),
      title: "Aucune intervention encod\xE9e",
      action: /*#__PURE__*/React.createElement(Button, {
        variant: "primary",
        leadingIcon: /*#__PURE__*/React.createElement(Ic, {
          n: "plus"
        }),
        onClick: add
      }, "Ajouter une intervention")
    }) : /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, items.map(it => /*#__PURE__*/React.createElement("div", {
      className: "sh-iv",
      key: it.id
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-listrow-ic",
      style: {
        flex: 'none',
        width: 38,
        height: 38,
        borderRadius: 'var(--radius-md)',
        background: 'var(--brand-subtle)',
        color: 'var(--brand)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      }
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "scissors"
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-iv-body"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-iv-title"
    }, it.name), /*#__PURE__*/React.createElement("div", {
      className: "sh-iv-meta"
    }, "Quantit\xE9 : ", it.count)), /*#__PURE__*/React.createElement(IconButton, {
      label: "Retirer",
      onClick: () => setItems(prev => prev.filter(x => x.id !== it.id))
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "trash-2"
    })))))), /*#__PURE__*/React.createElement(Button, {
      variant: "primary",
      fullWidth: true,
      size: "lg",
      onClick: () => {
        nav.toast('Encodage terminé');
        nav.back();
      }
    }, "Terminer l'encodage"));
  }

  /* ---------- Profil ---------- */
  function ProfileScreen({
    nav
  }) {
    const p = D.profile;
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-profile-head"
    }, /*#__PURE__*/React.createElement(Avatar, {
      name: p.name,
      size: "lg"
    }), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
      className: "sh-profile-name"
    }, p.name), /*#__PURE__*/React.createElement("div", {
      className: "sh-profile-role"
    }, p.role, " \xB7 ", p.city))), /*#__PURE__*/React.createElement("div", {
      className: "sh-profile-stats"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-num"
    }, p.monthMissions), /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-label"
    }, "Missions / mois")), /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-num"
    }, p.monthHours, "h"), /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-label"
    }, "Heures / mois")), /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-num"
    }, p.specialties.length), /*#__PURE__*/React.createElement("div", {
      className: "sh-pstat-label"
    }, "Sp\xE9cialit\xE9s"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Sp\xE9cialit\xE9s"
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-tagwrap"
    }, p.specialties.map(s => /*#__PURE__*/React.createElement(Tag, {
      key: s,
      tone: "brand"
    }, s)), /*#__PURE__*/React.createElement(Tag, {
      tone: "outline"
    }, "+ Ajouter"))), /*#__PURE__*/React.createElement("div", {
      className: "sh-rows"
    }, /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "sliders-horizontal"
      }),
      title: "Pr\xE9f\xE9rences",
      subtitle: "Notifications, disponibilit\xE9s, th\xE8me",
      onClick: () => nav.go('preferences')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "plane"
      }),
      title: "Mes absences",
      onClick: () => nav.go('absences')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "user-round"
      }),
      title: "Informations personnelles",
      subtitle: p.name,
      onClick: () => nav.toast('Informations')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "file-text"
      }),
      title: "Documents",
      value: p.docsToUpdate + ' à jour',
      onClick: () => nav.toast('Documents')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "bell"
      }),
      title: "Notifications",
      onClick: () => nav.go('notifications')
    })), /*#__PURE__*/React.createElement(Button, {
      variant: "ghost",
      fullWidth: true,
      leadingIcon: /*#__PURE__*/React.createElement(Ic, {
        n: "log-out"
      }),
      onClick: () => nav.toast('Déconnecté')
    }, "Se d\xE9connecter"));
  }

  /* ---------- Préférences ---------- */
  function PrefRow({
    icon,
    title,
    sub,
    control
  }) {
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-row"
    }, icon && /*#__PURE__*/React.createElement("span", {
      className: "sh-pref-ic"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: icon
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-body"
    }, /*#__PURE__*/React.createElement("span", {
      className: "sh-pref-title"
    }, title), sub && /*#__PURE__*/React.createElement("span", {
      className: "sh-pref-sub"
    }, sub)), /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-control"
    }, control));
  }
  function PreferencesScreen({
    nav
  }) {
    const p = D.profile;
    const [notif, setNotif] = React.useState({
      push: true,
      email: false,
      rappels: true
    });
    const [radius, setRadius] = React.useState('25');
    const [days, setDays] = React.useState(['lun', 'mar', 'mer', 'jeu', 'ven']);
    const [types, setTypes] = React.useState(['Bloc opératoire', 'Stérilisation']);
    const theme = nav.theme || 'light';
    const DAYS = [['lun', 'L'], ['mar', 'M'], ['mer', 'M'], ['jeu', 'J'], ['ven', 'V'], ['sam', 'S'], ['dim', 'D']];
    const TYPES = ['Bloc opératoire', 'Stérilisation', 'Garde', 'Consultation'];
    const toggle = (arr, set, v) => set(arr.includes(v) ? arr.filter(x => x !== v) : [...arr, v]);
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-screen-inner"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "bell"
    }), " Notifications"), /*#__PURE__*/React.createElement(PrefRow, {
      title: "Notifications push",
      sub: "Offres, attributions et rappels",
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: notif.push,
        onChange: e => setNotif({
          ...notif,
          push: e.target.checked
        })
      })
    }), /*#__PURE__*/React.createElement(PrefRow, {
      title: "E-mail",
      sub: "R\xE9capitulatifs et confirmations",
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: notif.email,
        onChange: e => setNotif({
          ...notif,
          email: e.target.checked
        })
      })
    }), /*#__PURE__*/React.createElement(PrefRow, {
      title: "Rappels de mission",
      sub: "La veille et 1 h avant",
      control: /*#__PURE__*/React.createElement(Switch, {
        checked: notif.rappels,
        onChange: e => setNotif({
          ...notif,
          rappels: e.target.checked
        })
      })
    })), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "map-pin"
    }), " Disponibilit\xE9s par d\xE9faut"), /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-stack"
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Rayon g\xE9ographique"), /*#__PURE__*/React.createElement(Select, {
      value: radius,
      onChange: e => setRadius(e.target.value),
      options: [{
        value: '10',
        label: '10 km autour de ' + p.city
      }, {
        value: '25',
        label: '25 km autour de ' + p.city
      }, {
        value: '50',
        label: '50 km autour de ' + p.city
      }, {
        value: '100',
        label: '100 km — toute la région'
      }]
    })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Jours disponibles"), /*#__PURE__*/React.createElement("div", {
      className: "sh-daypick"
    }, DAYS.map(([id, lab]) => /*#__PURE__*/React.createElement("button", {
      key: id,
      type: "button",
      className: "sh-day",
      "data-active": days.includes(id) ? 'true' : undefined,
      onClick: () => toggle(days, setDays, id)
    }, lab)))))), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "stethoscope"
    }), " Missions pr\xE9f\xE9r\xE9es"), /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-stack"
    }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Sp\xE9cialit\xE9s"), /*#__PURE__*/React.createElement("div", {
      className: "sh-tagwrap"
    }, p.specialties.map(s => /*#__PURE__*/React.createElement(Tag, {
      key: s,
      tone: "brand"
    }, s)), /*#__PURE__*/React.createElement(Tag, {
      tone: "outline"
    }, "+ Ajouter"))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Types de mission"), /*#__PURE__*/React.createElement("div", {
      className: "sh-chips",
      style: {
        flexWrap: 'wrap',
        overflow: 'visible'
      }
    }, TYPES.map(t => /*#__PURE__*/React.createElement("button", {
      key: t,
      type: "button",
      className: "sh-chip",
      "data-active": types.includes(t) ? 'true' : undefined,
      onClick: () => toggle(types, setTypes, t)
    }, t)))))), /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-card-block-head"
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "palette"
    }), " Apparence"), /*#__PURE__*/React.createElement("div", {
      className: "sh-pref-stack"
    }, /*#__PURE__*/React.createElement("label", {
      className: "sh-field-label"
    }, "Th\xE8me de l'application"), /*#__PURE__*/React.createElement("div", {
      className: "sh-themeseg",
      role: "group",
      "aria-label": "Th\xE8me"
    }, /*#__PURE__*/React.createElement("button", {
      type: "button",
      className: "sh-themeseg-opt",
      "data-active": theme === 'light' ? 'true' : undefined,
      onClick: () => nav.setTheme && nav.setTheme('light')
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "sun"
    }), " Clair"), /*#__PURE__*/React.createElement("button", {
      type: "button",
      className: "sh-themeseg-opt",
      "data-active": theme === 'dark' ? 'true' : undefined,
      onClick: () => nav.setTheme && nav.setTheme('dark')
    }, /*#__PURE__*/React.createElement(Ic, {
      n: "moon"
    }), " Sombre")))), /*#__PURE__*/React.createElement("div", {
      className: "sh-group"
    }, /*#__PURE__*/React.createElement(SectionHeader, {
      title: "Compte"
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-rows"
    }, /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "briefcase-medical"
      }),
      title: "Mes prestations",
      subtitle: "Heures & missions du mois",
      value: p.monthHours + ' h · ' + p.monthMissions,
      onClick: () => nav.toast('Mes prestations')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "file-text"
      }),
      title: "Documents",
      value: p.docsToUpdate + ' à jour',
      onClick: () => nav.toast('Documents')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "user-round"
      }),
      title: "Informations personnelles",
      onClick: () => nav.toast('Informations')
    }), /*#__PURE__*/React.createElement(ListRow, {
      icon: /*#__PURE__*/React.createElement(Ic, {
        n: "shield-check"
      }),
      title: "Confidentialit\xE9 & s\xE9curit\xE9",
      onClick: () => nav.toast('Sécurité')
    }))));
  }

  /* screen title map (shared by both shells) */
  const SH_TITLES = {
    today: "Aujourd'hui",
    planning: 'Planning',
    offers: 'Offres',
    mesmissions: 'Mes missions',
    absences: 'Absences',
    notifications: 'Notifications',
    preferences: 'Préférences',
    profile: 'Profil',
    detail: 'Mission',
    encoding: 'Mission',
    declare: 'Mission'
  };

  /* render any screen by descriptor — used by both shells */
  function renderScreen(current, nav) {
    const props = {
      nav,
      ...current.props
    };
    switch (current.name) {
      case 'today':
        return /*#__PURE__*/React.createElement(TodayScreen, props);
      case 'offers':
        return /*#__PURE__*/React.createElement(OffersScreen, props);
      case 'planning':
        return /*#__PURE__*/React.createElement(PlanningScreen, props);
      case 'mesmissions':
        return /*#__PURE__*/React.createElement(MesMissionsScreen, props);
      case 'absences':
        return /*#__PURE__*/React.createElement(AbsencesScreen, props);
      case 'notifications':
        return /*#__PURE__*/React.createElement(NotificationsScreen, props);
      case 'preferences':
        return /*#__PURE__*/React.createElement(PreferencesScreen, props);
      case 'declare':
        return /*#__PURE__*/React.createElement(DeclareScreen, props);
      case 'detail':
        return /*#__PURE__*/React.createElement(DetailScreen, props);
      case 'encoding':
        return /*#__PURE__*/React.createElement(EncodingScreen, props);
      case 'profile':
        return /*#__PURE__*/React.createElement(ProfileScreen, props);
      default:
        return /*#__PURE__*/React.createElement(TodayScreen, props);
    }
  }
  Object.assign(window, {
    SH_refreshIcons: refreshIcons,
    SH_TITLES,
    SH_renderScreen: renderScreen,
    TodayScreen,
    OffersScreen,
    PlanningScreen,
    MesMissionsScreen,
    AbsencesScreen,
    NotificationsScreen,
    PreferencesScreen,
    DeclareScreen,
    DetailScreen,
    EncodingScreen,
    ProfileScreen
  });
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/screens.jsx", error: String((e && e.message) || e) }); }

// ui_kits/instrumentiste/shells.jsx
try { (() => {
/* Surgery Hub — instrumentiste — the two app shells.
   Both consume the SAME screens (window, from screens.jsx) and the SAME
   custom navigation identity (MobileDock / DesktopSidebar from nav.jsx).
   Each shell owns its own light/dark theme state so the canvas can show
   both side-by-side independently. */

(() => {
  const {
    AppBar,
    Toast
  } = window.DesignSystem_d99104;
  const {
    IOSDevice
  } = window;
  const {
    MobileDock,
    DesktopSidebar
  } = window;
  const SH = window; // SH_renderScreen, SH_TITLES, SH_refreshIcons
  const SIc = ({
    n
  }) => /*#__PURE__*/React.createElement("i", {
    "data-lucide": n
  });
  const SDATA = window.SH_DATA;

  /* shared navigation/stack state hook */
  function useShellNav(initialTab) {
    const [tab, setTab] = React.useState(initialTab || 'today');
    const [stack, setStack] = React.useState([]);
    const [toast, setToast] = React.useState(null);
    const [theme, setTheme] = React.useState('light');
    const timer = React.useRef(null);
    React.useEffect(() => {
      SH.SH_refreshIcons();
    });
    const showToast = msg => {
      setToast(msg);
      clearTimeout(timer.current);
      timer.current = setTimeout(() => setToast(null), 2600);
    };
    const nav = {
      go: (name, props = {}) => {
        setStack(s => [...s, {
          name,
          props
        }]);
        SH.SH_refreshIcons();
      },
      back: () => {
        setStack(s => s.slice(0, -1));
        SH.SH_refreshIcons();
      },
      setTab: t => {
        setTab(t);
        setStack([]);
        SH.SH_refreshIcons();
      },
      toast: showToast,
      theme,
      setTheme
    };
    const current = stack.length ? stack[stack.length - 1] : {
      name: tab,
      props: {}
    };
    return {
      tab,
      setTab,
      stack,
      setStack,
      toast,
      setToast,
      theme,
      setTheme,
      nav,
      current
    };
  }

  /* ---------------- Mobile shell ---------------- */
  function MobileShell({
    framed = true
  }) {
    const s = useShellNav('today');
    const {
      nav,
      tab,
      stack,
      current,
      toast,
      theme
    } = s;
    const title = SH.SH_TITLES[current.name] || '';
    const unread = SDATA.notifications.filter(n => n.unread).length;
    const inner = /*#__PURE__*/React.createElement("div", {
      className: "sh-shell",
      "data-theme": theme
    }, /*#__PURE__*/React.createElement(AppBar, {
      title: current.name === 'today' ? /*#__PURE__*/React.createElement("span", {
        style: {
          color: 'var(--brand)'
        }
      }, "Aujourd'hui") : title,
      onBack: stack.length ? nav.back : undefined,
      notificationCount: unread,
      onNotifications: () => nav.go('notifications'),
      onProfile: () => nav.go('profile')
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-screen"
    }, SH.SH_renderScreen(current, nav)), /*#__PURE__*/React.createElement(MobileDock, {
      value: tab,
      onChange: nav.setTab,
      items: [{
        id: 'today',
        label: "Aujourd'hui",
        icon: /*#__PURE__*/React.createElement(SIc, {
          n: "house"
        })
      }, {
        id: 'planning',
        label: 'Planning',
        icon: /*#__PURE__*/React.createElement(SIc, {
          n: "calendar-days"
        })
      }, {
        id: 'offers',
        label: 'Offres',
        icon: /*#__PURE__*/React.createElement(SIc, {
          n: "tag"
        }),
        badge: SDATA.offers.length
      }, {
        id: 'absences',
        label: 'Absences',
        icon: /*#__PURE__*/React.createElement(SIc, {
          n: "plane"
        })
      }]
    }), toast && /*#__PURE__*/React.createElement("div", {
      className: "sh-shell-toast"
    }, /*#__PURE__*/React.createElement(Toast, {
      tone: "success",
      message: toast,
      onClose: () => nav.toast(null)
    })));
    return framed ? /*#__PURE__*/React.createElement(IOSDevice, {
      width: 402,
      height: 874,
      dark: theme === 'dark'
    }, inner) : inner;
  }

  /* ---------------- Desktop shell ---------------- */
  function DesktopShell({
    initialTab = 'today'
  }) {
    const s = useShellNav(initialTab);
    const {
      nav,
      tab,
      stack,
      current,
      toast,
      theme,
      setTheme
    } = s;
    const title = SH.SH_TITLES[current.name] || '';
    const unread = SDATA.notifications.filter(n => n.unread).length;
    return /*#__PURE__*/React.createElement("div", {
      className: "sh-desktop",
      "data-theme": theme
    }, /*#__PURE__*/React.createElement(DesktopSidebar, {
      tab: tab,
      activeName: current.name,
      onNav: t => nav.setTab(t),
      onProfile: () => nav.go('profile'),
      onPreferences: () => nav.go('preferences'),
      theme: theme,
      setTheme: setTheme
    }), /*#__PURE__*/React.createElement("div", {
      className: "sh-main"
    }, /*#__PURE__*/React.createElement("header", {
      className: "sh-topbar"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-topbar-lead"
    }, stack.length > 0 && /*#__PURE__*/React.createElement("button", {
      className: "sh-topbar-back",
      "aria-label": "Retour",
      onClick: nav.back
    }, /*#__PURE__*/React.createElement("svg", {
      viewBox: "0 0 24 24",
      fill: "none",
      stroke: "currentColor",
      strokeWidth: "2",
      strokeLinecap: "round",
      strokeLinejoin: "round"
    }, /*#__PURE__*/React.createElement("path", {
      d: "M15 18l-6-6 6-6"
    }))), /*#__PURE__*/React.createElement("h1", {
      className: "sh-topbar-title"
    }, title)), /*#__PURE__*/React.createElement("div", {
      className: "sh-topbar-actions"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-topbar-search"
    }, /*#__PURE__*/React.createElement(SIc, {
      n: "search"
    }), /*#__PURE__*/React.createElement("input", {
      placeholder: "Rechercher une mission, un site\u2026",
      "aria-label": "Rechercher"
    })), /*#__PURE__*/React.createElement("button", {
      className: "sh-topbar-icon",
      "aria-label": "Notifications",
      onClick: () => nav.go('notifications')
    }, /*#__PURE__*/React.createElement(SIc, {
      n: "bell"
    }), unread > 0 && /*#__PURE__*/React.createElement("span", {
      className: "sh-topbar-badge"
    }, unread)))), /*#__PURE__*/React.createElement("div", {
      className: "sh-content"
    }, /*#__PURE__*/React.createElement("div", {
      className: "sh-content-inner"
    }, SH.SH_renderScreen(current, nav)))), toast && /*#__PURE__*/React.createElement("div", {
      className: "sh-desktop-toast"
    }, /*#__PURE__*/React.createElement(Toast, {
      tone: "success",
      message: toast,
      onClose: () => nav.toast(null)
    })));
  }
  Object.assign(window, {
    MobileShell,
    DesktopShell,
    useShellNav
  });
})();
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/instrumentiste/shells.jsx", error: String((e && e.message) || e) }); }

__ds_ns.Avatar = __ds_scope.Avatar;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.StatTile = __ds_scope.StatTile;

__ds_ns.Tag = __ds_scope.Tag;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.StatusPill = __ds_scope.StatusPill;

__ds_ns.Toast = __ds_scope.Toast;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Checkbox = __ds_scope.Checkbox;

__ds_ns.IconButton = __ds_scope.IconButton;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.Select = __ds_scope.Select;

__ds_ns.Switch = __ds_scope.Switch;

__ds_ns.AppBar = __ds_scope.AppBar;

__ds_ns.BottomNav = __ds_scope.BottomNav;

__ds_ns.EmptyState = __ds_scope.EmptyState;

__ds_ns.InfoBanner = __ds_scope.InfoBanner;

__ds_ns.ListRow = __ds_scope.ListRow;

__ds_ns.MissionCard = __ds_scope.MissionCard;

__ds_ns.MissionHero = __ds_scope.MissionHero;

__ds_ns.OfferCard = __ds_scope.OfferCard;

__ds_ns.SectionHeader = __ds_scope.SectionHeader;

__ds_ns.Tabs = __ds_scope.Tabs;

})();
