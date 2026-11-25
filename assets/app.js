'use strict';

// Day and 7-day views load individual sessions; month and year views load server-side
// aggregates (api/summary.php). Both are turned into the same "model" before rendering.
const REFRESH_MS = 60 * 1000;
const SUMMARY_REFRESH_MS = 5 * 60 * 1000;
// App colors avoid the green/red used for categories
const PALETTE = ['#5b8cff', '#f0ad4e', '#b07cff', '#2ec4c4', '#ff8a65', '#f06292',
                 '#ffd54f', '#4dd0e1', '#a1887f', '#7986cb', '#aed581', '#ce93d8'];

const CATEGORIES = {
  productive: { label: 'Productive', color: '#33d17a' },
  neutral: { label: 'Neutral', color: '#7b8aa8' },
  distracting: { label: 'Distracting', color: '#ef5350' },
  none: { label: 'Uncategorized', color: '#3a4560' },
};
const CATEGORY_ORDER = ['productive', 'neutral', 'distracting', 'none'];
const MODES = ['day', 'week', 'month', 'year'];

// Friendly names for common app IDs (shared with the weekly email); anything else is derived from the ID
let APP_NAMES = {};

const state = {
  mode: 'day',
  colorBy: 'apps',
  date: todayString(),
  host: '',
  sessions: [], // allSessions, or only their parts within work hours when workOnly is on
  allSessions: [],
  workOnly: false,
  settings: null,
  summary: null,
  model: null,
  rules: [],
  usedApps: [],
  usedSites: [],
  showAllAppRules: false,
  goals: [],
  expanded: new Set(),
  chart: null,
  colorMap: new Map(),
  lastLoad: 0,
};

const $ = (id) => document.getElementById(id);

function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === 'style') Object.assign(node.style, v);
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (k in node) node[k] = v;
    else node.setAttribute(k, v);
  }
  node.append(...children);
  return node;
}

// ---- Dates ----

function fmtDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function todayString() {
  return fmtDate(new Date());
}

function parseDate(str) {
  const [y, m, d] = str.split('-').map(Number);
  return new Date(y, m - 1, d);
}

function shiftDate(str, days) {
  const d = parseDate(str);
  d.setDate(d.getDate() + days);
  return fmtDate(d);
}

function shiftMonths(str, months) {
  const d = parseDate(str);
  return fmtDate(new Date(d.getFullYear(), d.getMonth() + months, 1));
}

function ts(str) {
  return parseDate(str).getTime() / 1000;
}

function isSummaryMode() {
  return state.mode === 'month' || state.mode === 'year';
}

// First day and the day after the last day of the current view, as YYYY-MM-DD
function periodBounds() {
  const d = parseDate(state.date);
  switch (state.mode) {
    case 'day': return { first: state.date, end: shiftDate(state.date, 1) };
    case 'week': return { first: shiftDate(state.date, -6), end: shiftDate(state.date, 1) };
    case 'month': return {
      first: fmtDate(new Date(d.getFullYear(), d.getMonth(), 1)),
      end: fmtDate(new Date(d.getFullYear(), d.getMonth() + 1, 1)),
    };
    default: return {
      first: fmtDate(new Date(d.getFullYear(), 0, 1)),
      end: fmtDate(new Date(d.getFullYear() + 1, 0, 1)),
    };
  }
}

// Local-time range for the current view, as unix seconds
function currentRange() {
  const { first, end } = periodBounds();
  return { from: ts(first), to: ts(end) };
}

// Every day in the view, up to and including today
function daysInView() {
  const { first, end } = periodBounds();
  const today = todayString();
  const days = [];
  for (let d = first; d < end && d <= today; d = shiftDate(d, 1)) days.push(d);
  return days;
}

function periodLabel() {
  const label = basePeriodLabel();
  return state.workOnly ? `${label}${label ? ' · ' : ''}work hours` : label;
}

function basePeriodLabel() {
  const d = parseDate(state.date);
  if (state.mode === 'month') return d.toLocaleDateString([], { month: 'long', year: 'numeric' });
  if (state.mode === 'year') return String(d.getFullYear());
  if (state.mode === 'week') {
    const { first } = periodBounds();
    return `${parseDate(first).toLocaleDateString([], { month: 'short', day: 'numeric' })} – ${d.toLocaleDateString([], { month: 'short', day: 'numeric' })}`;
  }
  return '';
}

// ---- Formatting ----

function appName(id) {
  const key = id.toLowerCase();
  if (APP_NAMES[key]) return APP_NAMES[key];
  const last = key.includes('.') ? id.split('.').pop() : id;
  return last.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// Colors are handed out in order of first use (busiest apps first), so the top apps never share one
function appColor(id) {
  if (!state.colorMap.has(id)) {
    state.colorMap.set(id, PALETTE[state.colorMap.size % PALETTE.length]);
  }
  return state.colorMap.get(id);
}

function fmtDuration(sec) {
  sec = Math.round(sec);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m`;
  if (m > 0) return `${m}m`;
  return `${sec}s`;
}

function fmtClock(t) {
  return new Date(t * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// ---- Categories ----

function appRule(app) {
  return state.rules.find((r) => r.match_type === 'app' && r.pattern.toLowerCase() === app.toLowerCase());
}

// A site rule for "google.com" also covers "mail.google.com"; the most specific domain wins
function siteRule(domain) {
  const parts = (domain || '').toLowerCase().split('.').filter(Boolean);
  for (; parts.length; parts.shift()) {
    const d = parts.join('.');
    const rule = state.rules.find((r) => r.match_type === 'site' && r.pattern.toLowerCase() === d);
    if (rule) return rule;
  }
  return null;
}

// Title rules win over site rules, which win over app rules; among title rules the longest
// (most specific) match wins
function categorize(app, title, domain) {
  const t = (title || '').toLowerCase();
  let best = null;
  for (const r of state.rules) {
    if (r.match_type === 'title' && t.includes(r.pattern.toLowerCase())) {
      if (!best || r.pattern.length > best.pattern.length) best = r;
    }
  }
  if (best) return best.category;
  const sr = siteRule(domain);
  if (sr) return sr.category;
  const ar = appRule(app);
  return ar ? ar.category : 'none';
}

// Score like RescueTime's pulse: productive = 100, neutral = 50, distracting = 0; uncategorized ignored
function productivityScore(cats) {
  const rated = cats.productive + cats.neutral + cats.distracting;
  if (rated <= 0) return null;
  return Math.round((cats.productive * 100 + cats.neutral * 50) / rated);
}

// ---- Model ----

// Duration of a session that falls inside [from, to), scaled for partially overlapping sessions
function clippedDuration(s, from, to) {
  const span = s.end_ts - s.start_ts;
  if (span <= 0) return 0;
  const overlap = Math.min(s.end_ts, to) - Math.max(s.start_ts, from);
  if (overlap <= 0) return 0;
  return s.duration_sec * Math.min(1, overlap / span);
}

function emptyCats() {
  return { productive: 0, neutral: 0, distracting: 0, none: 0 };
}

function goalMatches(goal, s) {
  if (goal.target_type === 'category') return s.category === goal.target;
  if (goal.target_type === 'app') return s.app.toLowerCase() === goal.target.toLowerCase();
  return (s.title || '').toLowerCase().includes(goal.target.toLowerCase());
}

// model: { total, cats, apps: [{ id, total, cats, titles: Map(title -> { sec, category }) }], sites (same shape as apps),
//          days: { 'YYYY-MM-DD': { total, cats, apps: { id: sec } } }, goalValue(goal, day) }
function modelFromSessions() {
  const { from, to } = currentRange();
  const apps = new Map();
  const sites = new Map();
  const cats = emptyCats();
  const days = {};
  let total = 0;

  for (const s of state.sessions) s.category = categorize(s.app, s.title, s.domain);

  // Sessions cover the 7 days ending on the selected date; split them per day
  const dayList = [];
  for (let i = -6; i <= 0; i++) dayList.push(shiftDate(state.date, i));
  for (const day of dayList) {
    const dFrom = ts(day);
    const dTo = ts(shiftDate(day, 1));
    const bucket = { total: 0, cats: emptyCats(), apps: {} };
    for (const s of state.sessions) {
      const sec = clippedDuration(s, dFrom, dTo);
      if (sec <= 0) continue;
      bucket.total += sec;
      bucket.cats[s.category] += sec;
      bucket.apps[s.app] = (bucket.apps[s.app] || 0) + sec;
    }
    if (bucket.total > 0) days[day] = bucket;
  }

  for (const s of state.sessions) {
    const sec = clippedDuration(s, from, to);
    if (sec <= 0) continue;
    total += sec;
    cats[s.category] += sec;
    addToItem(apps, s.app, s, sec);
    if (s.domain) addToItem(sites, s.domain, s, sec);
  }

  return {
    total,
    cats,
    days,
    apps: [...apps.values()].sort((x, y) => y.total - x.total),
    sites: [...sites.values()].sort((x, y) => y.total - x.total),
    goalValue(goal, day) {
      const dFrom = ts(day);
      const dTo = ts(shiftDate(day, 1));
      let v = 0;
      for (const s of state.sessions) if (goalMatches(goal, s)) v += clippedDuration(s, dFrom, dTo);
      return v;
    },
  };
}

// Adds a session's time to an app or site entry, with its window title
function addToItem(items, id, s, sec) {
  let a = items.get(id);
  if (!a) {
    a = { id, total: 0, cats: emptyCats(), titles: new Map() };
    items.set(id, a);
  }
  a.total += sec;
  a.cats[s.category] += sec;
  const title = s.title || '(no title)';
  const entry = a.titles.get(title) || { sec: 0, category: s.category };
  entry.sec += sec;
  a.titles.set(title, entry);
}

function modelFromSummary() {
  const sum = state.summary;
  const items = (list) => (list || []).map((a) => ({
    id: a.id,
    total: a.total,
    cats: a.cats,
    titles: new Map(a.titles.map(([title, sec, category]) => [title, { sec, category }])),
  }));
  return {
    total: sum.total,
    cats: sum.cats,
    days: sum.days,
    apps: items(sum.apps),
    sites: items(sum.sites),
    goalValue(goal, day) {
      return (sum.goal_days[goal.id] || {})[day] || 0;
    },
  };
}

// ---- API ----

async function api(path, options = {}) {
  const res = await fetch(path, {
    cache: 'no-store',
    ...options,
    headers: { 'X-Requested-With': 'timetracker', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
  });
  if (!res.ok) {
    let msg = `HTTP ${res.status}`;
    try { msg = (await res.json()).error || msg; } catch { /* not JSON */ }
    throw new Error(msg);
  }
  return res.json();
}

async function load() {
  $('status').textContent = 'Loading…';
  try {
    let data;
    if (isSummaryMode()) {
      const { from, to } = currentRange();
      const params = new URLSearchParams({ from, to, tz: Intl.DateTimeFormat().resolvedOptions().timeZone });
      if (state.host) params.set('host', state.host);
      if (state.workOnly) params.set('work', '1');
      const [summary, rules, goals, settings] = await Promise.all([
        api(`api/summary.php?${params}`), api('api/rules.php'), api('api/goals.php'), api('api/settings.php'),
      ]);
      applySettings(settings.settings);
      state.summary = summary;
      state.rules = rules.rules;
      state.usedApps = rules.used_apps || [];
      state.usedSites = rules.used_sites || [];
      state.goals = goals.goals;
      data = summary;
    } else {
      // Always fetch the 7 days ending on the selected date (goal history needs them even in day view)
      const params = new URLSearchParams({ from: ts(shiftDate(state.date, -6)), to: ts(shiftDate(state.date, 1)) });
      if (state.host) params.set('host', state.host);
      const [sessions, rules, goals, settings] = await Promise.all([
        api(`api/sessions.php?${params}`), api('api/rules.php'), api('api/goals.php'), api('api/settings.php'),
      ]);
      applySettings(settings.settings);
      state.allSessions = sessions.sessions;
      applyWorkFilter();
      state.rules = rules.rules;
      state.usedApps = rules.used_apps || [];
      state.usedSites = rules.used_sites || [];
      state.goals = goals.goals;
      data = sessions;
    }
    state.lastLoad = Date.now();
    renderHosts(data.hosts);
    renderAll();
    const last = data.last_received
      ? `last data received ${new Date(data.last_received * 1000).toLocaleString()}`
      : 'no data received yet';
    $('status').textContent = `Updated ${new Date().toLocaleTimeString()} · ${last}`;
  } catch (err) {
    $('status').textContent = `Could not load data: ${err.message}`;
  }
}

// Month/year categories are computed on the server, so rule and goal changes need a reload there
function refreshAfterChange() {
  if (isSummaryMode()) load();
  else renderAll();
}

async function saveRule(matchType, pattern, category) {
  try {
    const res = await api('api/rules.php', {
      method: 'POST',
      body: JSON.stringify({ match_type: matchType, pattern, category }),
    });
    state.rules = res.rules;
    refreshAfterChange();
  } catch (err) {
    $('status').textContent = `Could not save rule: ${err.message}`;
  }
}

async function deleteRule(id) {
  try {
    const res = await api(`api/rules.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    state.rules = res.rules;
    refreshAfterChange();
  } catch (err) {
    $('status').textContent = `Could not delete rule: ${err.message}`;
  }
}

// ---- Rendering ----

function renderHosts(hosts) {
  const sel = $('host');
  sel.hidden = hosts.length < 2;
  const wanted = ['', ...hosts];
  if (sel.options.length === wanted.length && [...sel.options].every((o, i) => o.value === wanted[i])) return;
  sel.replaceChildren(...wanted.map((h) => el('option', { value: h, textContent: h || 'All computers' })));
  sel.value = state.host;
}

function renderAll() {
  if (isSummaryMode() && !state.summary) return;
  const model = isSummaryMode() ? modelFromSummary() : modelFromSessions();
  state.model = model;
  const { total, cats, apps } = model;
  apps.forEach((a) => appColor(a.id));

  $('period-label').textContent = periodLabel();
  $('stat-total').textContent = total ? fmtDuration(total) : '–';
  const score = productivityScore(cats);
  $('stat-score').textContent = score === null ? '–' : `${score}`;
  $('stat-score').style.color = score === null ? '' : score >= 65 ? CATEGORIES.productive.color : score >= 40 ? '#f0ad4e' : CATEGORIES.distracting.color;
  $('stat-top').textContent = apps.length ? appName(apps[0].id) : '–';
  if (state.mode === 'day') {
    const { from, to } = currentRange();
    $('stat-span-label').textContent = 'Active span';
    const inRange = state.sessions.filter((s) => clippedDuration(s, from, to) > 0);
    $('stat-span').textContent = inRange.length
      ? `${fmtClock(Math.max(from, inRange[0].start_ts))} – ${fmtClock(Math.min(to, Math.max(...inRange.map((s) => s.end_ts))))}`
      : '–';
  } else {
    const { first, end } = periodBounds();
    const activeDays = Object.keys(model.days).filter((d) => d >= first && d < end && model.days[d].total > 0).length;
    $('stat-span-label').textContent = `Average per active day (${activeDays})`;
    $('stat-span').textContent = activeDays ? fmtDuration(total / activeDays) : '–';
  }

  renderGoals(model);
  renderKnownApps(apps);
  renderCategoryBar(cats, total);
  $('timeline-panel').hidden = state.mode !== 'day';
  $('week-panel').hidden = state.mode === 'day';
  $('heatmap-panel').hidden = state.mode !== 'year';
  if (state.mode === 'year') renderHeatmap(model);
  if (state.mode === 'day') renderTimeline();
  else renderPeriodChart(model);
  renderApps(apps, total);
  renderSites(model.sites, total);
  renderRules();
}

function renderCategoryBar(cats, total) {
  const bar = $('cat-bar');
  const legend = $('cat-legend');
  if (!total) {
    bar.replaceChildren();
    legend.replaceChildren(el('span', { className: 'muted', textContent: 'No activity recorded for this period.' }));
    return;
  }
  bar.replaceChildren(...CATEGORY_ORDER.filter((c) => cats[c] > 0).map((c) => el('div', {
    title: `${CATEGORIES[c].label}: ${fmtDuration(cats[c])}`,
    style: { width: `${cats[c] / total * 100}%`, background: CATEGORIES[c].color },
  })));
  legend.replaceChildren(...CATEGORY_ORDER.map((c) => el('span', { className: 'legend-item' },
    el('span', { className: 'swatch', style: { background: CATEGORIES[c].color } }),
    `${CATEGORIES[c].label} `,
    el('strong', { textContent: fmtDuration(cats[c]) }),
    el('span', { className: 'muted', textContent: ` ${Math.round(cats[c] / total * 100)}%` }),
  )));
}

function sessionColor(s) {
  return state.colorBy === 'cats' ? CATEGORIES[s.category].color : appColor(s.app);
}

function renderTimeline() {
  const { from, to } = currentRange();
  const inRange = state.sessions.filter((s) => s.end_ts > from && s.start_ts < to);
  // Show working hours by default, widening to fit any activity outside them
  let startHour = 7;
  let endHour = 19;
  for (const s of inRange) {
    startHour = Math.min(startHour, Math.floor((Math.max(s.start_ts, from) - from) / 3600));
    endHour = Math.max(endHour, Math.ceil((Math.min(s.end_ts, to) - from) / 3600));
  }
  const viewFrom = from + startHour * 3600;
  const viewSpan = (endHour - startHour) * 3600;

  // Shade the hours outside the work schedule (the whole day on days off)
  const viewTo = viewFrom + viewSpan;
  const work = workWindow(state.date);
  const offHours = (work ? [[viewFrom, work[0]], [work[1], viewTo]] : [[viewFrom, viewTo]])
    .map(([a, b]) => [Math.max(a, viewFrom), Math.min(b, viewTo)])
    .filter(([a, b]) => b > a)
    .map(([a, b]) => el('div', {
      className: 'off-hours',
      title: work ? 'Outside work hours' : 'Not a work day',
      style: { left: `${(a - viewFrom) / viewSpan * 100}%`, width: `${(b - a) / viewSpan * 100}%` },
    }));

  $('timeline').replaceChildren(...offHours, ...inRange.map((s) => {
    const left = (Math.max(s.start_ts, viewFrom) - viewFrom) / viewSpan * 100;
    const width = (Math.min(s.end_ts, viewFrom + viewSpan) - Math.max(s.start_ts, viewFrom)) / viewSpan * 100;
    return el('div', {
      className: 'block',
      title: `${appName(s.app)}${s.domain ? ` · ${s.domain}` : ''} · ${CATEGORIES[s.category].label} · ${fmtClock(s.start_ts)}–${fmtClock(s.end_ts)} (${fmtDuration(s.duration_sec)})\n${s.title}`,
      style: { left: `${left}%`, width: `${width}%`, background: sessionColor(s) },
    });
  }));

  const step = endHour - startHour > 12 ? 2 : 1;
  const labels = [];
  for (let h = startHour; h <= endHour; h += step) {
    const d = new Date((from + h * 3600) * 1000);
    labels.push(el('span', {
      textContent: h === 24 ? '24' : d.toLocaleTimeString([], { hour: 'numeric' }),
      style: { left: `${(h - startHour) / (endHour - startHour) * 100}%` },
    }));
  }
  $('timeline-axis').replaceChildren(...labels);
}

// Stacked bars: one per day (7 days, month) or per month (year)
function renderPeriodChart(model) {
  const { first, end } = periodBounds();
  let buckets;
  if (state.mode === 'year') {
    const year = parseDate(first).getFullYear();
    buckets = Array.from({ length: 12 }, (_, m) => {
      const prefix = `${year}-${String(m + 1).padStart(2, '0')}-`;
      return {
        label: new Date(year, m, 1).toLocaleDateString([], { month: 'short' }),
        days: Object.keys(model.days).filter((d) => d.startsWith(prefix)),
      };
    });
    $('chart-title').textContent = 'Hours per month';
  } else {
    buckets = [];
    for (let d = first; d < end; d = shiftDate(d, 1)) {
      buckets.push({
        label: parseDate(d).toLocaleDateString([], state.mode === 'week' ? { weekday: 'short', day: 'numeric' } : { day: 'numeric' }),
        days: [d],
      });
    }
    $('chart-title').textContent = 'Hours per day';
  }

  let series;
  if (state.colorBy === 'cats') {
    series = CATEGORY_ORDER.map((c) => ({
      label: CATEGORIES[c].label, color: CATEGORIES[c].color, value: (day) => day.cats[c] || 0,
    }));
  } else {
    const top = model.apps.slice(0, 6).map((a) => a.id);
    series = [
      ...top.map((id) => ({ label: appName(id), color: appColor(id), value: (day) => day.apps[id] || 0 })),
      {
        label: 'Other',
        color: '#4a5470',
        value: (day) => day.total - top.reduce((sum, id) => sum + (day.apps[id] || 0), 0),
      },
    ];
  }
  const datasets = series.map((ser) => ({
    label: ser.label,
    backgroundColor: ser.color,
    borderRadius: 3,
    data: buckets.map((b) => +(b.days.reduce((sum, d) => sum + (model.days[d] ? ser.value(model.days[d]) : 0), 0) / 3600).toFixed(2)),
  }));
  const labels = buckets.map((b) => b.label);

  if (typeof Chart === 'undefined') return;
  if (state.chart) {
    state.chart.data.labels = labels;
    state.chart.data.datasets = datasets;
    state.chart.update();
    return;
  }
  state.chart = new Chart($('week-chart'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
      maintainAspectRatio: false,
      scales: {
        x: { stacked: true, ticks: { color: cssVar('--muted') }, grid: { display: false } },
        y: { stacked: true, ticks: { color: cssVar('--muted'), callback: (v) => `${v}h` }, grid: { color: cssVar('--panel-border') } },
      },
      plugins: {
        legend: { labels: { color: cssVar('--text'), boxWidth: 12 } },
        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmtDuration(ctx.parsed.y * 3600)}` } },
      },
    },
  });
}

// GitHub-style calendar: one column per week, one row per weekday (Sunday first)
const HEATMAP_LEVELS = [1, 3600, 3 * 3600, 5 * 3600]; // seconds needed for shade 1..4
const HEATMAP_ALPHA = [0.3, 0.5, 0.75, 1];

function heatLevel(sec) {
  let level = 0;
  for (let i = 0; i < HEATMAP_LEVELS.length; i++) if (sec >= HEATMAP_LEVELS[i]) level = i + 1;
  return level;
}

function hexToRgba(hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

function scoreColor(score) {
  if (score === null) return CATEGORIES.neutral.color;
  return score >= 65 ? CATEGORIES.productive.color : score >= 40 ? '#f0ad4e' : CATEGORIES.distracting.color;
}

function heatColor(day) {
  const level = heatLevel(day ? day.total : 0);
  if (!level) return '';
  const base = state.colorBy === 'cats' ? scoreColor(productivityScore(day.cats)) : '#5b8cff';
  return hexToRgba(base, HEATMAP_ALPHA[level - 1]);
}

function openDay(date) {
  state.date = date;
  $('date').value = date;
  setMode('day');
}

function renderHeatmap(model) {
  const year = parseDate(state.date).getFullYear();
  const jan1 = new Date(year, 0, 1);
  const offset = jan1.getDay();
  const today = todayString();
  const cells = [];
  const monthStarts = [];

  for (let d = new Date(jan1); d.getFullYear() === year; d.setDate(d.getDate() + 1)) {
    const key = fmtDate(d);
    const index = Math.round((d - jan1) / 86400000);
    const col = Math.floor((index + offset) / 7);
    if (d.getDate() === 1) monthStarts.push({ col, name: d.toLocaleDateString([], { month: 'short' }) });

    const day = model.days[key];
    const future = key > today;
    const score = day ? productivityScore(day.cats) : null;
    const label = d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
    const cell = el('button', {
      type: 'button',
      className: `hm-day${future ? ' future' : ''}`,
      title: future ? label : `${label}: ${day ? fmtDuration(day.total) : 'nothing tracked'}${score !== null ? ` · score ${score}` : ''}`,
      'aria-label': label,
      style: { gridColumn: String(col + 2), gridRow: String(d.getDay() + 2) },
      disabled: future,
      onclick: () => openDay(key),
    });
    const color = heatColor(day);
    if (color) cell.style.background = color;
    cells.push(cell);
  }

  const weeks = Math.floor((Math.round((new Date(year, 11, 31) - jan1) / 86400000) + offset) / 7) + 1;
  const grid = $('heatmap');
  grid.style.setProperty('--weeks', weeks);
  grid.replaceChildren(
    ...monthStarts.map((m) => el('span', { className: 'hm-month', textContent: m.name, style: { gridColumn: `${m.col + 2} / span 4` } })),
    ...[['Mon', 1], ['Wed', 3], ['Fri', 5]].map(([name, row]) => el('span', { className: 'hm-weekday', textContent: name, style: { gridRow: String(row + 2) } })),
    ...cells,
  );

  // On narrow screens the grid scrolls; bring today (or the end of a past year) into view
  const scroller = grid.parentElement;
  if (scroller.scrollWidth > scroller.clientWidth) {
    const target = grid.querySelector(`[aria-label="${parseDate(today < `${year + 1}-01-01` ? today : `${year}-12-31`).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}"]`);
    if (target) {
      const x = target.getBoundingClientRect().left - grid.getBoundingClientRect().left;
      scroller.scrollLeft = Math.max(0, x - scroller.clientWidth + 60);
    }
  }

  const legend = $('heatmap-legend');
  if (state.colorBy === 'cats') {
    $('heatmap-hint').textContent = 'Each square is a day, colored by productivity score and shaded by time tracked. Click a day to open it.';
    legend.replaceChildren(...[['Productive (65+)', CATEGORIES.productive.color], ['Mixed (40–64)', '#f0ad4e'], ['Distracting (<40)', CATEGORIES.distracting.color]]
      .flatMap(([name, color]) => [el('span', { className: 'hm-day', style: { background: color } }), name, el('span', { className: 'gap' })]));
  } else {
    $('heatmap-hint').textContent = 'Each square is a day, shaded by time tracked. Click a day to open it.';
    legend.replaceChildren('Less',
      el('span', { className: 'hm-day', style: { background: 'var(--track)' } }),
      ...HEATMAP_ALPHA.map((a, i) => el('span', { className: 'hm-day', title: `${fmtDuration(HEATMAP_LEVELS[i])}+`, style: { background: hexToRgba('#5b8cff', a) } })),
      'More', el('span', { className: 'gap' }), el('span', { className: 'muted', textContent: '(1m+, 1h+, 3h+, 5h+)' }));
  }
}

function categorySelect(current, onChange, label) {
  const sel = el('select', { className: `cat-select cat-${current}`, 'aria-label': label },
    ...CATEGORY_ORDER.map((c) => el('option', { value: c, textContent: CATEGORIES[c].label })));
  sel.value = current;
  sel.addEventListener('change', () => onChange(sel.value));
  return sel;
}

function prefillRule(title) {
  $('rule-type').value = 'title';
  $('rule-pattern').value = title;
  $('rules-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
  $('rule-pattern').focus();
  $('rule-pattern').select();
}

function renderApps(apps, total) {
  $('empty').hidden = apps.length > 0;
  $('apps').replaceChildren(...itemRows(apps, total, {
    key: (id) => id,
    name: (id) => appName(id),
    sub: (id) => id,
    color: (id) => appColor(id),
    rule: (id) => appRule(id),
    ruleType: 'app',
  }));
}

function renderSites(sites, total) {
  $('sites-empty').hidden = sites.length > 0;
  $('sites').replaceChildren(...itemRows(sites, total, {
    key: (id) => `site:${id}`,
    name: (id) => id,
    sub: () => '',
    color: () => '#5b8cff',
    rule: (id) => siteRule(id),
    ruleType: 'site',
  }));
}

// Rows for the Applications and Websites lists: name, category menu, time, share and a bar
// split by category; clicking a row shows its window titles
function itemRows(items, total, kind) {
  return items.map((a) => {
    const rule = kind.rule(a.id);
    const key = kind.key(a.id);
    const expanded = state.expanded.has(key);

    const toggle = el('button', {
      type: 'button',
      className: 'app-toggle',
      'aria-expanded': String(expanded),
      onclick: () => {
        if (expanded) state.expanded.delete(key);
        else state.expanded.add(key);
        renderAll();
      },
    },
      el('span', { className: 'swatch', style: { background: kind.color(a.id) } }),
      el('span', { className: 'app-name', textContent: kind.name(a.id) }, kind.sub(a.id) ? el('small', { textContent: kind.sub(a.id) }) : ''),
    );

    // A site covered by a rule for its parent domain shows that rule's category; choosing one adds a rule for this site
    const row = el('div', { className: 'app-row' },
      toggle,
      categorySelect(rule ? rule.category : 'none', (c) => saveRule(kind.ruleType, a.id, c), `Category for ${kind.name(a.id)}`),
      el('span', { className: 'app-time', textContent: fmtDuration(a.total) }),
      el('span', { className: 'app-pct', textContent: `${Math.round(a.total / total * 100)}%` }),
    );

    // Bar length is relative to the busiest item; segments show the category split
    const bar = el('div', { className: 'app-bar' },
      el('div', { className: 'app-bar-fill', style: { width: `${a.total / items[0].total * 100}%` } },
        ...CATEGORY_ORDER.filter((c) => a.cats[c] > 0).map((c) => el('div', {
          title: `${CATEGORIES[c].label}: ${fmtDuration(a.cats[c])}`,
          style: {
            width: `${a.cats[c] / a.total * 100}%`,
            background: state.colorBy === 'cats' ? CATEGORIES[c].color : kind.color(a.id),
            opacity: state.colorBy === 'cats' ? 1 : 0.9,
          },
        }))));

    const wrap = el('div', { className: 'app' }, row, bar);

    if (expanded) {
      const titles = [...a.titles.entries()].sort((x, y) => y[1].sec - x[1].sec).slice(0, 25);
      wrap.append(el('ul', { className: 'titles' }, ...titles.map(([title, info]) => el('li', {},
        el('span', { className: 'dot', title: CATEGORIES[info.category].label, style: { background: CATEGORIES[info.category].color } }),
        el('span', { className: 'title-text', textContent: title, title }),
        el('button', { type: 'button', className: 'link', textContent: '+ rule', onclick: () => prefillRule(title) }),
        el('span', { className: 'title-time', textContent: fmtDuration(info.sec) }),
      ))));
    }
    return wrap;
  });
}

function renderRules() {
  if (!state.rules.length) {
    $('rules').replaceChildren(el('p', { className: 'muted', textContent: 'No rules yet.' }));
    return;
  }
  const used = new Set(state.usedApps.map((a) => a.toLowerCase()));
  const hiddenAppRules = state.rules.filter((r) => r.match_type === 'app' && !used.has(r.pattern.toLowerCase()));
  const groups = [['title', 'Window title contains'], ['site', 'Website is'], ['app', 'App is']];
  $('rules').replaceChildren(...groups.map(([type, heading]) => {
    let rules = state.rules.filter((r) => r.match_type === type);
    if (type === 'app' && !state.showAllAppRules) rules = rules.filter((r) => used.has(r.pattern.toLowerCase()));
    if (!rules.length && !(type === 'app' && hiddenAppRules.length)) return '';
    const toggle = type === 'app' && hiddenAppRules.length
      ? el('button', {
        type: 'button',
        className: 'link',
        textContent: state.showAllAppRules
          ? 'Hide rules for apps not used yet'
          : `Show ${hiddenAppRules.length} more for apps not used yet`,
        onclick: () => { state.showAllAppRules = !state.showAllAppRules; renderRules(); },
      })
      : '';
    return el('div', { className: 'rule-group' },
      el('h3', { textContent: heading }, ' ', toggle),
      el('ul', {}, ...rules.map((r) => el('li', {},
        el('span', { className: 'rule-pattern', textContent: type === 'app' ? `${appName(r.pattern)} (${r.pattern})` : `“${r.pattern}”` }),
        categorySelect(r.category, (c) => saveRule(r.match_type, r.pattern, c), `Category for ${r.pattern}`),
        el('button', { type: 'button', className: 'link danger', textContent: 'Delete', onclick: () => deleteRule(r.id) }),
      ))));
  }));
}

// ---- Goals ----

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const GOAL_STATUS = {
  met: { label: 'Met', cls: 'good' },
  under: { label: 'Under limit so far', cls: 'good' },
  progress: { label: 'In progress', cls: 'warn' },
  missed: { label: 'Not met', cls: 'bad' },
  over: { label: 'Over limit', cls: 'bad' },
  na: { label: 'Not scheduled', cls: 'na' },
};

function goalLabel(goal) {
  const amount = goal.minutes % 60 === 0 ? `${goal.minutes / 60}h` : fmtDuration(goal.minutes * 60);
  const verb = goal.comparison === 'at_least' ? 'At least' : 'At most';
  let what;
  if (goal.target_type === 'category') what = `${CATEGORIES[goal.target].label.toLowerCase()} time`;
  else if (goal.target_type === 'app') what = `in ${appName(goal.target)}`;
  else what = `on “${goal.target}”`;
  const days = goal.days === 62 ? ' · weekdays' : goal.days === 65 ? ' · weekends' : '';
  return `${verb} ${amount} ${what}${days}`;
}

// Status of a goal on one day: na, met, missed, progress (today, not reached yet), over, under (today, within limit)
function goalDay(model, goal, day) {
  const value = model.goalValue(goal, day);
  const limit = goal.minutes * 60;
  const applies = (goal.days >> parseDate(day).getDay()) & 1;
  const isToday = day === todayString();
  let status;
  if (!applies || (goal.created && day < goal.created)) status = 'na';
  else if (goal.comparison === 'at_least') status = value >= limit ? 'met' : isToday ? 'progress' : 'missed';
  else status = value > limit ? 'over' : isToday ? 'under' : 'met';
  return { day, value, limit, status };
}

// Days that count towards a met/missed tally: scheduled, and finished (today only once it's decided)
function decided(h) {
  return h.status === 'met' || h.status === 'missed' || h.status === 'over';
}

function ratioClass(ratio) {
  return ratio >= 0.8 ? 'good' : ratio >= 0.5 ? 'warn' : 'bad';
}

function dayDot(h, label) {
  return el('span', {
    className: `day-dot ${GOAL_STATUS[h.status].cls}`,
    title: `${parseDate(h.day).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}: ${GOAL_STATUS[h.status].label} (${fmtDuration(h.value)})`,
    textContent: label,
  });
}

function renderGoals(model) {
  if (!state.goals.length) {
    $('goals').replaceChildren(el('p', { className: 'muted', textContent: 'No goals yet. Add one below, e.g. “At least 4 hours of productive time”.' }));
    return;
  }
  $('goals').replaceChildren(...state.goals.map((goal) => (isSummaryMode()
    ? renderPeriodGoal(model, goal)
    : renderDayGoal(model, goal))));
}

// Day / 7-day views: progress on the selected day plus the last 7 days
function renderDayGoal(model, goal) {
  const today = goalDay(model, goal, state.date);
  const st = GOAL_STATUS[today.status];
  const history = [];
  for (let i = -6; i <= 0; i++) history.push(goalDay(model, goal, shiftDate(state.date, i)));
  const counted = history.filter(decided);
  const metCount = counted.filter((h) => h.status === 'met').length;

  return goalCard(goal, st.label, st.cls, Math.min(1, today.value / today.limit),
    `${fmtDuration(today.value)} of ${fmtDuration(today.limit)}${goal.comparison === 'at_most' ? ' limit' : ''}`,
    [...history.map((h) => dayDot(h, DAY_NAMES[parseDate(h.day).getDay()][0])),
      el('span', { className: 'muted', textContent: counted.length ? ` ${metCount}/${counted.length} days met` : '' })]);
}

// Month / year views: how many scheduled days met the goal
function renderPeriodGoal(model, goal) {
  const history = daysInView().map((d) => goalDay(model, goal, d));
  const counted = history.filter(decided);
  const metCount = counted.filter((h) => h.status === 'met').length;
  const ratio = counted.length ? metCount / counted.length : 0;
  const scheduled = history.filter((h) => h.status !== 'na');
  const avg = scheduled.length ? scheduled.reduce((sum, h) => sum + h.value, 0) / scheduled.length : 0;
  const chip = counted.length ? `Met ${metCount} of ${counted.length} days` : 'No finished days yet';

  let detail;
  if (state.mode === 'month') {
    // One dot per day of the month; days after today are faded
    const { first, end } = periodBounds();
    const all = [];
    for (let d = first; d < end; d = shiftDate(d, 1)) {
      const h = d <= todayString() ? goalDay(model, goal, d) : { day: d, value: 0, status: 'na' };
      const dot = dayDot(h, String(parseDate(d).getDate()));
      if (d > todayString()) dot.classList.add('future');
      all.push(dot);
    }
    detail = all;
  } else {
    // One cell per month, colored by the share of days met
    const year = parseDate(state.date).getFullYear();
    detail = [el('span', { className: 'month-cells' }, ...Array.from({ length: 12 }, (_, m) => {
      const prefix = `${year}-${String(m + 1).padStart(2, '0')}-`;
      const monthDays = counted.filter((h) => h.day.startsWith(prefix));
      const met = monthDays.filter((h) => h.status === 'met').length;
      const name = new Date(year, m, 1).toLocaleDateString([], { month: 'short' });
      return el('span', {
        className: `month-cell ${monthDays.length ? ratioClass(met / monthDays.length) : 'na'}`,
        title: monthDays.length ? `${name}: met ${met} of ${monthDays.length} days` : `${name}: no scheduled days`,
        textContent: name[0],
      });
    }))];
  }

  return goalCard(goal, chip, counted.length ? ratioClass(ratio) : 'na', ratio,
    `Average ${fmtDuration(avg)} per scheduled day (${goal.comparison === 'at_least' ? 'target' : 'limit'} ${fmtDuration(goal.minutes * 60)})`,
    detail);
}

function goalCard(goal, chipText, cls, fill, footText, detail) {
  return el('div', { className: 'goal' },
    el('div', { className: 'goal-head' },
      el('span', { className: 'goal-label', textContent: goalLabel(goal) }),
      el('span', { className: `chip ${cls}`, textContent: chipText }),
      el('button', { type: 'button', className: 'link danger', textContent: 'Delete', onclick: () => deleteGoal(goal.id) }),
    ),
    el('div', { className: 'goal-bar' },
      el('div', { className: `goal-fill ${cls}`, style: { width: `${fill * 100}%` } })),
    el('div', { className: 'goal-foot' },
      el('span', { className: 'muted', textContent: footText }),
      el('span', { className: 'goal-days' }, ...detail),
    ),
  );
}

// App suggestions for the goal and rule forms: every app that has been used, on any computer
function renderKnownApps(apps) {
  const ids = [...new Set([...state.usedApps, ...apps.map((a) => a.id)])].sort();
  const options = () => ids.map((a) => el('option', { value: a, textContent: appName(a) }));
  $('known-apps').replaceChildren(...options());
  $('used-apps').replaceChildren(...options());
  $('used-sites').replaceChildren(...state.usedSites.map((d) => el('option', { value: d })));
}

async function addGoal(goal) {
  try {
    const res = await api('api/goals.php', { method: 'POST', body: JSON.stringify(goal) });
    state.goals = res.goals;
    refreshAfterChange();
    return true;
  } catch (err) {
    $('status').textContent = `Could not save goal: ${err.message}`;
    return false;
  }
}

async function deleteGoal(id) {
  try {
    const res = await api(`api/goals.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    state.goals = res.goals;
    refreshAfterChange();
  } catch (err) {
    $('status').textContent = `Could not delete goal: ${err.message}`;
  }
}

// ---- CSV export ----

function csvCell(value) {
  let v = String(value ?? '');
  // Stop spreadsheet apps from treating window titles as formulas
  if (/^[=+\-@\t\r]/.test(v)) v = `'${v}`;
  return /[",\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

function localIso(t) {
  const d = new Date(t * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

function downloadCsv(name, rows) {
  // BOM so Excel opens UTF-8 titles correctly
  const text = '﻿' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n') + '\r\n';
  const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
  const a = el('a', { href: url, download: name });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function exportFileName(kind) {
  const { first, end } = periodBounds();
  const last = shiftDate(end, -1);
  const period = first === last ? first : `${first}_to_${last}`;
  const host = state.host ? `-${state.host}` : '';
  return `timetracker-${kind}${host}-${period}.csv`;
}

// Fetches sessions for the whole view in chunks (the sessions API allows up to 40 days per request)
async function fetchSessionsForView() {
  const { from, to } = currentRange();
  const all = [];
  for (let start = from; start < to; start += 31 * 86400) {
    const params = new URLSearchParams({ from: start, to: Math.min(to, start + 31 * 86400) });
    if (state.host) params.set('host', state.host);
    const res = await api(`api/sessions.php?${params}`);
    for (const s of res.sessions) {
      // A session overlapping a chunk boundary is returned twice; keep it once
      if (s.start_ts >= start || start === from) all.push(s);
    }
  }
  return all;
}

async function exportSessions() {
  $('status').textContent = 'Preparing sessions export…';
  try {
    const { from, to } = currentRange();
    const fetched = await fetchSessionsForView();
    const sessions = state.workOnly ? clipToWork(fetched) : fetched;
    const rows = [['date', 'start', 'end', 'duration_seconds', 'duration', 'computer', 'app', 'app_name', 'website', 'category', 'window_title']];
    for (const s of sessions) {
      const d = clippedDuration(s, from, to);
      if (d <= 0) continue;
      rows.push([
        localIso(s.start_ts).slice(0, 10), localIso(s.start_ts), localIso(s.end_ts), Math.round(d), fmtDuration(d),
        s.host, s.app, appName(s.app), s.domain || '', CATEGORIES[categorize(s.app, s.title, s.domain)].label, s.title,
      ]);
    }
    downloadCsv(exportFileName('sessions'), rows);
    $('status').textContent = `Exported ${rows.length - 1} sessions`;
  } catch (err) {
    $('status').textContent = `Export failed: ${err.message}`;
  }
}

function exportTotals() {
  const { total, apps } = state.model;
  const rows = [['app', 'app_name', 'total_seconds', 'total', 'percent', 'productive_seconds', 'neutral_seconds', 'distracting_seconds', 'uncategorized_seconds']];
  for (const a of apps) {
    rows.push([
      a.id, appName(a.id), Math.round(a.total), fmtDuration(a.total), (a.total / total * 100).toFixed(1),
      Math.round(a.cats.productive), Math.round(a.cats.neutral), Math.round(a.cats.distracting), Math.round(a.cats.none),
    ]);
  }
  downloadCsv(exportFileName('totals'), rows);
}

function exportDaily() {
  const { days } = state.model;
  const { first, end } = periodBounds();
  const rows = [['date', 'weekday', 'total_seconds', 'total', 'productivity_score', 'productive_seconds', 'neutral_seconds', 'distracting_seconds', 'uncategorized_seconds', 'top_app']];
  for (const d of daysInView()) {
    if (d < first || d >= end) continue;
    const day = days[d] || { total: 0, cats: emptyCats(), apps: {} };
    const top = Object.entries(day.apps).sort((x, y) => y[1] - x[1])[0];
    const score = productivityScore(day.cats);
    rows.push([
      d, DAY_NAMES[parseDate(d).getDay()], Math.round(day.total), fmtDuration(day.total), score ?? '',
      Math.round(day.cats.productive), Math.round(day.cats.neutral), Math.round(day.cats.distracting), Math.round(day.cats.none),
      top ? appName(top[0]) : '',
    ]);
  }
  downloadCsv(exportFileName('daily'), rows);
}

// ---- Install commands ----

async function loadInstallInfo() {
  try {
    const { token } = await api('api/install-info.php');
    const base = new URL('.', location.href).href.replace(/\/$/, '');
    $('cmd-windows').textContent =
      `iwr -UseBasicParsing ${base}/downloads/install.ps1 -OutFile $env:TEMP\\tt-install.ps1; ` +
      `powershell -NoProfile -ExecutionPolicy Bypass -File $env:TEMP\\tt-install.ps1 -Server ${base} -Token ${token}`;
    $('cmd-linux').textContent =
      `curl -fsSL ${base}/downloads/install.sh | bash -s -- --server ${base} --token ${token}`;
  } catch (err) {
    $('cmd-windows').textContent = $('cmd-linux').textContent = `Could not load install info: ${err.message}`;
  }
}

// navigator.clipboard needs HTTPS, so fall back to a selected textarea on plain HTTP
async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const ta = el('textarea', { value: text, style: { position: 'fixed', opacity: '0' } });
  document.body.append(ta);
  ta.select();
  document.execCommand('copy');
  ta.remove();
}

// ---- Controls ----

function setMode(mode) {
  state.mode = mode;
  for (const m of MODES) $(`mode-${m}`).classList.toggle('active', m === mode);
  load();
}

// ---- Work schedule ----

// [start, end] unix seconds of the work hours on a 'YYYY-MM-DD' date, or null on a day off
function workWindow(date) {
  const s = state.settings;
  if (!s) return null;
  const d = parseDate(date);
  if (!((s.work_days >> d.getDay()) & 1)) return null;
  const at = (time) => {
    const [h, m] = time.split(':').map(Number);
    return new Date(d.getFullYear(), d.getMonth(), d.getDate(), h, m).getTime() / 1000;
  };
  return [at(s.work_start), at(s.work_end)];
}

// Splits sessions into their parts within work hours (time is shared out in proportion)
function clipToWork(sessions) {
  const out = [];
  for (const s of sessions) {
    const span = s.end_ts - s.start_ts;
    if (span <= 0) continue;
    for (let day = fmtDate(new Date(s.start_ts * 1000)); ts(day) < s.end_ts; day = shiftDate(day, 1)) {
      const w = workWindow(day);
      if (!w) continue;
      const start = Math.max(s.start_ts, w[0]);
      const end = Math.min(s.end_ts, w[1]);
      if (end > start) out.push({ ...s, start_ts: start, end_ts: end, duration_sec: s.duration_sec * (end - start) / span });
    }
  }
  return out;
}

function applyWorkFilter() {
  state.sessions = state.workOnly ? clipToWork(state.allSessions) : state.allSessions;
}

function setHoursMode(workOnly) {
  state.workOnly = workOnly;
  $('hours-all').classList.toggle('active', !workOnly);
  $('hours-work').classList.toggle('active', workOnly);
  try { localStorage.setItem('tt-work-only', workOnly ? '1' : '0'); } catch { /* storage unavailable */ }
  // Month/year totals are computed on the server
  if (isSummaryMode()) {
    load();
  } else {
    applyWorkFilter();
    renderAll();
  }
}

// ---- Settings ----

function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  try { localStorage.setItem('tt-theme', theme); } catch { /* storage unavailable */ }
  if (state.model) renderAll(); // the chart reads its colors from the theme
}

function applySettings(settings) {
  const scheduleChanged = !state.settings
    || ['work_days', 'work_start', 'work_end'].some((k) => state.settings[k] !== settings[k]);
  state.settings = settings;
  if (document.documentElement.dataset.theme !== settings.theme) applyTheme(settings.theme);
  $('hours-work').title = `Count only ${scheduleLabel(settings)} (change in Settings)`;
  if (scheduleChanged) applyWorkFilter();
}

// "Mon–Fri 9:00–17:00"
function scheduleLabel(s) {
  const order = [1, 2, 3, 4, 5, 6, 0];
  const days = order.filter((d) => (s.work_days >> d) & 1);
  let dayText = days.map((d) => DAY_NAMES[d]).join(', ');
  if (s.work_days === 62) dayText = 'Mon–Fri';
  else if (s.work_days === 127) dayText = 'every day';
  const t = (v) => v.replace(/^0(\d)/, '$1');
  return `${dayText} ${t(s.work_start)}–${t(s.work_end)}`;
}

function settingsStatus(text, kind = '') {
  $('settings-status').textContent = text;
  $('settings-status').className = `settings-status ${kind}`;
}

function openSettings() {
  const s = state.settings;
  if (!s) return;
  const form = $('settings-form');
  form.querySelector(`input[name="theme"][value="${s.theme}"]`).checked = true;
  $('work-days').querySelectorAll('input').forEach((cb) => { cb.checked = Boolean((s.work_days >> Number(cb.value)) & 1); });
  $('work-start').value = s.work_start;
  $('work-end').value = s.work_end;
  $('report-enabled').checked = s.report_enabled;
  $('smtp-username').value = s.smtp_username;
  $('smtp-password').value = '';
  $('smtp-password').placeholder = s.has_password ? 'Saved (leave empty to keep it)' : 'Not set';
  $('mail-to').value = s.mail_to;
  $('smtp-host').value = s.smtp_host;
  $('smtp-port').value = s.smtp_port;
  $('from-name').value = s.from_name;
  $('dashboard-url').value = s.dashboard_url || new URL('.', location.href).href;
  settingsStatus('');
  $('settings-dialog').showModal();
}

function closeSettings() {
  // Undo a theme previewed but not saved
  if (state.settings && document.documentElement.dataset.theme !== state.settings.theme) applyTheme(state.settings.theme);
  $('settings-dialog').close();
}

function settingsFromForm() {
  let days = 0;
  $('work-days').querySelectorAll('input:checked').forEach((cb) => { days |= 1 << Number(cb.value); });
  return {
    theme: $('settings-form').querySelector('input[name="theme"]:checked').value,
    work_days: days,
    work_start: $('work-start').value,
    work_end: $('work-end').value,
    report_enabled: $('report-enabled').checked,
    smtp_username: $('smtp-username').value.trim(),
    smtp_password: $('smtp-password').value,
    mail_to: $('mail-to').value.trim(),
    smtp_host: $('smtp-host').value.trim() || 'smtp.gmail.com',
    smtp_port: Number($('smtp-port').value) || 587,
    from_name: $('from-name').value.trim(),
    dashboard_url: $('dashboard-url').value.trim(),
  };
}

// Returns true when saved
async function saveSettings() {
  const values = settingsFromForm();
  const problem = !values.work_days ? 'Pick at least one work day.'
    : !values.work_start || !values.work_end || values.work_end <= values.work_start ? 'The workday must end after it starts.'
      : '';
  if (problem) {
    settingsStatus(problem, 'error');
    return false;
  }
  settingsStatus('Saving…');
  try {
    const res = await api('api/settings.php', { method: 'POST', body: JSON.stringify(values) });
    const scheduleChanged = ['work_days', 'work_start', 'work_end'].some((k) => state.settings[k] !== res.settings[k]);
    applySettings(res.settings);
    $('smtp-password').value = '';
    $('smtp-password').placeholder = res.settings.has_password ? 'Saved (leave empty to keep it)' : 'Not set';
    if (scheduleChanged && state.workOnly && isSummaryMode()) load();
    else renderAll();
    return true;
  } catch (err) {
    settingsStatus(err.message, 'error');
    return false;
  }
}

function setColorBy(colorBy) {
  state.colorBy = colorBy;
  $('color-apps').classList.toggle('active', colorBy === 'apps');
  $('color-cats').classList.toggle('active', colorBy === 'cats');
  try { localStorage.setItem('tt-color-by', colorBy); } catch { /* storage unavailable */ }
  renderAll();
}

function setDate(date) {
  if (!date) return;
  state.date = date > todayString() ? todayString() : date;
  $('date').value = state.date;
  load();
}

function step(direction) {
  switch (state.mode) {
    case 'day': return setDate(shiftDate(state.date, direction));
    case 'week': return setDate(shiftDate(state.date, 7 * direction));
    case 'month': return setDate(shiftMonths(state.date, direction));
    default: return setDate(shiftMonths(state.date, 12 * direction));
  }
}

for (const m of MODES) $(`mode-${m}`).addEventListener('click', () => setMode(m));
$('color-apps').addEventListener('click', () => setColorBy('apps'));
$('color-cats').addEventListener('click', () => setColorBy('cats'));
$('prev').addEventListener('click', () => step(-1));
$('next').addEventListener('click', () => step(1));
$('today').addEventListener('click', () => setDate(todayString()));
$('date').addEventListener('change', (e) => setDate(e.target.value));
$('host').addEventListener('change', (e) => { state.host = e.target.value; load(); });
const RULE_INPUT = {
  title: { list: '', placeholder: 'e.g. YouTube' },
  site: { list: 'used-sites', placeholder: 'Website, e.g. youtube.com' },
  app: { list: 'used-apps', placeholder: 'App, e.g. google-chrome' },
};
$('rule-type').addEventListener('change', (e) => {
  const input = RULE_INPUT[e.target.value];
  $('rule-pattern').setAttribute('list', input.list);
  $('rule-pattern').placeholder = input.placeholder;
});
$('rule-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const pattern = $('rule-pattern').value.trim();
  if (!pattern) return;
  saveRule($('rule-type').value, pattern, $('rule-category').value);
  $('rule-pattern').value = '';
});

$('hours-all').addEventListener('click', () => setHoursMode(false));
$('hours-work').addEventListener('click', () => setHoursMode(true));

$('settings-open').addEventListener('click', openSettings);
$('settings-close').addEventListener('click', closeSettings);
$('settings-cancel').addEventListener('click', closeSettings);
$('settings-dialog').addEventListener('cancel', (e) => { e.preventDefault(); closeSettings(); });
$('settings-form').querySelectorAll('input[name="theme"]').forEach((r) => r.addEventListener('change', () => applyTheme(r.value)));
$('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (await saveSettings()) $('settings-dialog').close();
});
$('settings-test').addEventListener('click', async () => {
  // Save first so the test uses what's on screen
  if (!(await saveSettings())) return;
  settingsStatus('Sending test email…');
  try {
    const res = await api('api/settings.php?action=test', { method: 'POST' });
    settingsStatus(`Test email sent to ${res.sent_to.join(', ')}`, 'ok');
  } catch (err) {
    settingsStatus(err.message, 'error');
  }
});

$('export-sessions').addEventListener('click', () => { $('export-menu').open = false; exportSessions(); });
$('export-totals').addEventListener('click', () => { $('export-menu').open = false; exportTotals(); });
$('export-daily').addEventListener('click', () => { $('export-menu').open = false; exportDaily(); });
$('install-panel').addEventListener('toggle', () => {
  if ($('install-panel').open && $('cmd-linux').textContent === 'Loading…') loadInstallInfo();
});
document.querySelectorAll('button.copy').forEach((btn) => btn.addEventListener('click', async () => {
  try {
    await copyText($(btn.dataset.target).textContent);
    btn.textContent = 'Copied';
  } catch {
    btn.textContent = 'Copy failed';
  }
  setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
}));

$('goal-target-type').addEventListener('change', (e) => {
  const v = e.target.value;
  const input = $('goal-target');
  input.hidden = v.startsWith('category:');
  input.required = !input.hidden;
  input.placeholder = v === 'app' ? 'App, e.g. google-chrome' : 'Title text, e.g. YouTube';
  input.setAttribute('list', v === 'app' ? 'known-apps' : '');
});
// The amount's limits follow the unit: up to 24 hours (quarter-hour steps) or 1440 minutes
$('goal-unit').addEventListener('change', (e) => {
  const hours = e.target.value === '60';
  const amount = $('goal-amount');
  amount.max = hours ? 24 : 1440;
  amount.step = hours ? 0.25 : 1;
  amount.min = hours ? 0.25 : 1;
});
$('goal-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const typeValue = $('goal-target-type').value;
  const [type, category] = typeValue.split(':');
  const minutes = Math.round(parseFloat($('goal-amount').value) * Number($('goal-unit').value));
  if (!(minutes >= 1 && minutes <= 1440)) {
    $('status').textContent = 'Goal must be between 1 minute and 24 hours.';
    return;
  }
  const ok = await addGoal({
    target_type: type,
    target: category || $('goal-target').value.trim(),
    comparison: $('goal-comparison').value,
    minutes,
    days: Number($('goal-days').value),
  });
  if (ok) $('goal-target').value = '';
});

try {
  if (localStorage.getItem('tt-work-only') === '1') {
    state.workOnly = true;
    $('hours-all').classList.remove('active');
    $('hours-work').classList.add('active');
  }
  if (localStorage.getItem('tt-color-by') === 'cats') {
    state.colorBy = 'cats';
    $('color-apps').classList.remove('active');
    $('color-cats').classList.add('active');
  }
} catch { /* storage unavailable */ }

$('date').value = state.date;
$('date').max = state.date;
fetch('assets/app-names.json')
  .then((r) => (r.ok ? r.json() : {}))
  .catch(() => ({}))
  .then((names) => { APP_NAMES = names; })
  .finally(load);

// Keep views that include today fresh (month/year summaries less often)
setInterval(() => {
  if (document.hidden) return;
  const { first, end } = periodBounds();
  const today = todayString();
  if (today < first || today >= end) return;
  if (Date.now() - state.lastLoad >= (isSummaryMode() ? SUMMARY_REFRESH_MS : REFRESH_MS) - 1000) load();
}, REFRESH_MS);
