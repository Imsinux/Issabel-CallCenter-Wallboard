<?php
/* --- 1. SECURITY CHECK --- */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: wallboard.php");
    exit;
}
/* ------------------------- */

date_default_timezone_set('Asia/Tehran');
header('Cache-Control: no-store');

$start = $_GET['start'] ?? date('Y-m-d');
$end   = $_GET['end']   ?? date('Y-m-d');
$queue = $_GET['queue'] ?? '';
$sla   = (int)($_GET['sla'] ?? 20);

$query   = 'start='.urlencode($start).'&end='.urlencode($end).'&sla='.$sla.($queue!=='' ? '&queue='.urlencode($queue) : '');
$apiUrl  = 'queue_agent_summary.php?api=1&live=1&refresh=3&trends=1&compare=1&'.$query;
$xlsxUrl = 'queue_agent_summary.php?export=xlsx&'.$query;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Call Center – IRANSOLAR</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="chart.umd.min.js"></script>
<style>
/* =========================================================================
   IRANSOLAR Color System v1.0  —  WCAG 2.1 AA  —  dark · .light · .hc
   Legacy names (--muted --accent2 --danger --warn) preserved as aliases so
   the rest of this stylesheet keeps working unchanged.
   ========================================================================= */
:root{
  /* ── DARK MODE (default) ── */
  --bg:#0f172a;           /* L0 base            */
  --panel:#1e293b;        /* L1 cards/appbar    */
  --card:#1e293b;
  --card2:#334155;        /* L2 inset/headers   */
  --elevated:#475569;     /* L3 hover/raised    */
  --text:#f8fafc;         /* 17:1 on bg         */
  --muted:#94a3b8;        /* 6.96:1 — AA        */
  --text-faint:#64748b;   /* decorative only    */
  --border:#334155;
  --border-strong:#475569;

  --accent:#3b82f6;       /* 4.85:1 on bg       */
  --accent2:#60a5fa;
  --ok:#10b981;           /* 7.04:1 on bg       */
  --avail:#38bdf8;        /* 7.8:1 on bg (was #0ea5e9) */
  --pause:#94a3b8;
  --danger:#f87171;       /* danger TEXT on dark, AA (base #ef4444) */
  --danger-base:#ef4444;  /* for tints/borders  */
  --warn:#fbbf24;         /* warning TEXT on dark, 8+:1 */
  --warn-base:#f59e0b;
  --shadow:0 10px 25px -5px rgba(0,0,0,.4), 0 8px 10px -6px rgba(0,0,0,.1);
  --focus-ring:0 0 0 3px rgba(59,130,246,.55);

  /* Tile gradients — ends DARKENED so the 12px white label clears 4.5:1 */
  --tile1:linear-gradient(135deg, #3b82f6, #1d4ed8); /* blue    6.7  */
  --tile2:linear-gradient(135deg, #10b981, #047857); /* emerald 5.48 */
  --tile3:linear-gradient(135deg, #f43f5e, #be123c); /* rose    6.29 */
  --tile4:linear-gradient(135deg, #8b5cf6, #6d28d9); /* violet  7.1  */
  --tile5:linear-gradient(135deg, #f97316, #c2410c); /* orange  5.18 */
  --tile6:linear-gradient(135deg, #22d3ee, #0e7490); /* cyan    5.36 */
  --tile-text:#ffffff;

  /* Chart series — distinct in hue AND luminance (color-blind safe order) */
  --chart-1:#3b82f6; --chart-2:#f59e0b; --chart-3:#10b981;
  --chart-4:#e11d48; --chart-5:#8b5cf6; --chart-6:#0e7490;
  --chart-grid:#334155; --chart-axis:#94a3b8;

  --r-card:16px; --r-btn:12px; --r-chip:999px;
  --ease-smooth: cubic-bezier(0.4, 0, 0.2, 1);
}

:root.light{
  /* ── LIGHT MODE ── */
  --bg:#f8fafc;           /* L0 */
  --panel:#ffffff;        /* L1 */
  --card:#ffffff;
  --card2:#f1f5f9;        /* L2 */
  --elevated:#e2e8f0;     /* L3 */
  --text:#0f172a;         /* 17:1 on bg */
  --muted:#475569;        /* 7.4:1 — AA (was #64748b, borderline) */
  --text-faint:#64748b;
  --border:#e2e8f0;
  --border-strong:#cbd5e1;

  --accent:#2563eb;       /* 4.94:1 on bg */
  --accent2:#1d4ed8;
  --ok:#047857;           /* 5.24:1 (was #059669 = 3.6, FAIL) */
  --avail:#0369a1;        /* 5.67:1 (was #0284c7) */
  --pause:#475569;
  --danger:#dc2626;       /* 4.62:1 */
  --danger-base:#dc2626;
  --warn:#b45309;         /* 4.8:1 (was #d97706 = 3.04, FAIL) */
  --warn-base:#b45309;
  --shadow:0 4px 15px -3px rgba(0,0,0,.08), 0 4px 6px -4px rgba(0,0,0,.04);

  --tile1:linear-gradient(135deg, #3b82f6, #1d4ed8);
  --tile2:linear-gradient(135deg, #10b981, #047857);
  --tile3:linear-gradient(135deg, #f43f5e, #be123c);
  --tile4:linear-gradient(135deg, #8b5cf6, #6d28d9);
  --tile5:linear-gradient(135deg, #f97316, #c2410c);
  --tile6:linear-gradient(135deg, #22d3ee, #0e7490);

  --chart-1:#2563eb; --chart-2:#b45309; --chart-3:#047857;
  --chart-4:#be123c; --chart-5:#6d28d9; --chart-6:#0e7490;
  --chart-grid:#e2e8f0; --chart-axis:#475569;
}

:root.hc{
  /* ── HIGH-CONTRAST (wall display / low vision) — pure-black, ~AAA ── */
  --bg:#000000;
  --panel:#0a0f1a;
  --card:#0a0f1a;
  --card2:#15203a;
  --elevated:#22304f;
  --text:#ffffff;         /* 21:1 */
  --muted:#cbd5e1;        /* 13:1 */
  --text-faint:#94a3b8;
  --border:#5b6b8c;
  --border-strong:#8da2c8;

  --accent:#7dd3fc;
  --accent2:#bae6fd;
  --ok:#34d399;
  --avail:#7dd3fc;
  --pause:#cbd5e1;
  --danger:#fca5a5;
  --danger-base:#fca5a5;
  --warn:#fcd34d;
  --warn-base:#fcd34d;
  --shadow:0 10px 25px -5px rgba(0,0,0,.6);
  --focus-ring:0 0 0 3px #ffffff;

  --tile-text:#000000;    /* dark text on bright tiles for HC */
  --tile1:linear-gradient(135deg, #93c5fd, #60a5fa);
  --tile2:linear-gradient(135deg, #6ee7b7, #34d399);
  --tile3:linear-gradient(135deg, #fda4af, #fb7185);
  --tile4:linear-gradient(135deg, #c4b5fd, #a78bfa);
  --tile5:linear-gradient(135deg, #fdba74, #fb923c);
  --tile6:linear-gradient(135deg, #67e8f9, #22d3ee);

  --chart-1:#7dd3fc; --chart-2:#fcd34d; --chart-3:#34d399;
  --chart-4:#fda4af; --chart-5:#c4b5fd; --chart-6:#67e8f9;
  --chart-grid:#334155; --chart-axis:#cbd5e1;
}

*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 "Vazirmatn","Inter",sans-serif; transition:background-color 0.3s, color 0.3s;}
:where(.value,.live-val,#clock_time){font-variant-numeric:tabular-nums lining-nums}

/* ── Appbar ── */
.appbar{
  position:sticky;top:0;z-index:50;
  padding:10px 24px;
  background:rgba(15,23,42,0.92);
  backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);
  box-shadow:0 4px 20px rgba(0,0,0,0.2);
  display:flex;align-items:center;justify-content:space-between;
  transition:background 0.3s,border-color 0.3s;
}
:root.light .appbar{background:rgba(255,255,255,0.97);border-bottom:1px solid var(--border);box-shadow:0 2px 15px rgba(0,0,0,.05);}
:root.hc .appbar{background:rgba(0,0,0,0.96);border-bottom:1px solid var(--border);}

.brand{display:flex;align-items:center;gap:12px;}
.brand h1{font-size:16px;font-weight:700;margin:0;opacity:.9;letter-spacing:-.5px;}
.brand .orb{width:10px;height:10px;border-radius:50%;background:var(--accent);box-shadow:0 0 10px var(--accent);}

.cmd{display:flex;align-items:center;gap:10px;}
.min-input-group{
  position:relative;display:flex;align-items:center;
  background:var(--card2);border:1px solid var(--border);
  border-radius:8px;padding:0 8px;height:36px;
  transition:all 0.2s var(--ease-smooth);
}
:root.light .min-input-group{background:#f8fafc;}
.min-input-group:focus-within{border-color:var(--accent);box-shadow:0 0 0 2px rgba(59,130,246,.2);transform:translateY(-1px);}
.min-input-group svg{width:14px;height:14px;opacity:.6;pointer-events:none;color:var(--text);}
.min-input{
  background:transparent;border:none;color:var(--text);
  font-family:inherit;font-size:13px;font-weight:600;
  height:100%;outline:none;padding:0 8px;
}
input[type="date"]::-webkit-calendar-picker-indicator{opacity:.6;cursor:pointer;filter:invert(var(--dark-inv,1));}
:root.light input[type="date"]::-webkit-calendar-picker-indicator{--dark-inv:0;}

.btn-min{
  height:36px;padding:0 16px;border-radius:8px;border:none;
  font-size:13px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;gap:6px;
  transition:all 0.2s var(--ease-smooth);text-decoration:none;
}
.btn-min:hover{transform:translateY(-1px);}
.btn-min:active{transform:scale(0.96);}
.btn-primary{background:var(--accent);color:#fff;box-shadow:0 4px 10px rgba(59,130,246,.2);}
.btn-primary:hover{background:var(--accent2);box-shadow:0 6px 14px rgba(59,130,246,.3);}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border);}
.btn-ghost:hover{background:var(--card2);color:var(--text);}
.btn-logout{background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.2);}
.btn-logout:hover{background:rgba(239,68,68,.2);}

.sep{width:1px;height:20px;background:var(--border);margin:0 4px;}

.live-pill{display:inline-flex;align-items:center;gap:6px;padding:0 10px;border-radius:var(--r-chip);border:1px solid rgba(16,185,129,.3);background:rgba(16,185,129,.1);color:var(--ok);font-size:11px;height:24px;font-weight:bold;}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--ok);animation:pulse 2s infinite ease-out;}
@keyframes pulse{from{transform:scale(1);opacity:1}to{transform:scale(1.8);opacity:0}}

/* ── Layout ── */
#wallboardWrapper{width:1288px;max-width:1288px;margin:20px auto 32px;padding:0 16px;transform-origin:top center;transition:transform 0.3s cubic-bezier(.2,.8,.2,1);}
.page{max-width:none;margin:0;padding:0;}
.grid{display:grid;gap:12px;}

/* ── KPI tiles ── */
.kpis{grid-template-columns:repeat(6,1fr);}
.kpis2{grid-template-columns:repeat(6,1fr);margin-top:12px;}
@media(max-width:1100px){.kpis,.kpis2{grid-template-columns:repeat(3,1fr);}}

.tile{
  padding:14px;border-radius:var(--r-card);border:none;box-shadow:0 4px 15px rgba(0,0,0,.1);
  transition:transform .25s var(--ease-smooth),box-shadow .25s var(--ease-smooth);
  color:var(--tile-text);
}
.tile:hover{transform:translateY(-4px);box-shadow:0 12px 25px rgba(0,0,0,.2);z-index:2;}
.tile .label{font-size:12px;color:rgba(255,255,255,.85);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;justify-content:space-between;font-weight:600;}
.tile .value{font-weight:800;font-size:24px;margin-top:4px;}
.tile1{background:var(--tile1)}.tile2{background:var(--tile2)}.tile3{background:var(--tile3)}
.tile4{background:var(--tile4)}.tile5{background:var(--tile5)}.tile6{background:var(--tile6)}

/* Delta badge on tile (adjusted for colorful tiles) */
.delta{
  display:inline-flex;align-items:center;gap:2px;
  font-size:10px;font-weight:700;padding:2px 8px;
  border-radius:var(--r-chip);
}
/* White transparent badges on colored tiles */
.delta.up  {background:rgba(255,255,255,.25); color:#fff;}
.delta.down{background:rgba(0,0,0,.25); color:#fff;}
.delta.flat{background:rgba(255,255,255,.15);color:#fff;}

/* ── Live box ── */
@keyframes livePulse{
  0%  {box-shadow:0 4px 15px rgba(59,130,246,.15);border-color:rgba(59,130,246,.3);}
  50% {box-shadow:0 4px 25px rgba(59,130,246,.3);border-color:rgba(59,130,246,.6);}
  100%{box-shadow:0 4px 15px rgba(59,130,246,.15);border-color:rgba(59,130,246,.3);}
}

.livebox{
  display:flex;align-items:center;justify-content:center;gap:24px;
  background:var(--card);
  border:1px solid var(--border);border-radius:calc(var(--r-card) + 2px);
  padding:16px 20px;
  animation:livePulse 3s infinite ease-in-out;
  margin-top:12px;
}
.livebox:hover{animation-play-state:paused;}
.live-inline{display:flex;align-items:center;justify-content:center;gap:8px;font-size:15px;color:var(--text);font-weight:600;}
.live-val{font-weight:900;font-size:26px;color:var(--text)!important;}
.live-divider{width:1px;height:32px;background:var(--border);border-radius:1px;}

/* ── Cards ── */
.card{
  background:var(--card);border:1px solid var(--border);border-radius:var(--r-card);
  box-shadow:var(--shadow);padding:14px;margin-top:12px;
  transition:transform .25s var(--ease-smooth),box-shadow .25s,border-color .2s;
}
.card:hover{border-color:var(--accent);}
.card h3{margin:0 0 12px;font-size:14px;color:var(--muted);letter-spacing:.2px;display:flex;align-items:center;gap:8px;font-weight:700;}

/* ── Charts ── */
.charts{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:12px;margin-top:12px;}
@media(max-width:1100px){.charts{grid-template-columns:1fr;}}
.pie{width:170px;height:170px;margin:0 auto;display:grid;place-items:center;}
#bar_agents{height:220px!important;max-height:none!important;}
.numlabel{text-align:center;font-size:.85rem;color:var(--muted);margin-top:.5rem;font-weight:600;}

.trends{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
@media(max-width:1100px){.trends{grid-template-columns:1fr;}}
.trend-canvas{height:240px!important;max-height:none!important;}

/* ── Wait distribution bar ── */
.wait-dist{display:flex;flex-direction:column;gap:8px;margin-top:8px;}
.wdist-row{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;}
.wdist-label{width:70px;color:var(--text);text-align:right;flex-shrink:0;}
.wdist-bar-wrap{flex:1;background:var(--card2);border-radius:6px;height:14px;overflow:hidden;}
.wdist-bar{height:100%;border-radius:6px;transition:width .6s ease;}
.wdist-count{width:45px;text-align:left;color:var(--muted);}

/* ── Occupancy bar in agent table ── */
.occ-bar-wrap{width:60px;height:8px;background:var(--card2);border-radius:4px;display:inline-block;vertical-align:middle;margin-left:6px;}
.occ-bar{height:100%;border-radius:4px;background:var(--accent);}

/* ── Tables ── */
.table-wrap{
  border:1px solid var(--border);border-radius:var(--r-card);
  background:var(--card);
  scrollbar-width:thin;scrollbar-color:var(--border) transparent;
  overflow:hidden;
}
.table-wrap::-webkit-scrollbar{width:6px;height:6px;}
.table-wrap::-webkit-scrollbar-thumb{background-color:var(--border);border-radius:4px;}

table{border-collapse:collapse;width:100%;min-width:800px;}
thead th{
  position:sticky;top:0;z-index:10;
  text-align:right;font-size:12px;color:var(--muted);letter-spacing:.5px;
  background:var(--card2);
  border-bottom:2px solid var(--border);
  padding:12px 14px;
}
tbody td{
  padding:12px 14px;border-bottom:1px solid var(--border);
  font-variant-numeric:tabular-nums;transition:background .2s,color .2s;color:var(--text);
  font-weight:500;
}
tbody tr{transition:background .2s;}
tbody tr:hover{background:var(--card2)!important;}

tr.oncall td{color:var(--ok);font-weight:800;background:rgba(16,185,129,.08);}
tr.available td{color:var(--avail);font-weight:800;background:rgba(59,130,246,.08);}

.table-scroll-y{max-height:300px;overflow-y:auto;}

.count-badge{
  display:inline-block;padding:2px 10px;border-radius:6px;
  background:var(--card2);color:var(--text);
  font-weight:700;font-size:12px;border:1px solid var(--border);
  transition:transform .2s;
}
tbody tr:hover .count-badge{transform:scale(1.1);background:var(--card);border-color:var(--accent);}

.warn-badge{background:rgba(239,68,68,.15);color:var(--danger);border-color:rgba(239,68,68,.3);}

/* ── Phone icon ── */
.phone{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:8px;color:var(--muted);background:var(--card2);}
.phone svg{width:14px;height:14px;fill:currentColor;}
.phone.ring{color:#fff;background:var(--ok);box-shadow:0 2px 8px rgba(16,185,129,.4);}
@keyframes shake{0%,100%{transform:rotate(0)}20%{transform:rotate(-12deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-8deg)}80%{transform:rotate(6deg)}}
.phone.ring svg{animation:shake .9s ease-in-out infinite;}

/* ── Badges / Footer ── */
.badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.badge{background:var(--card);color:var(--text);padding:6px 12px;border-radius:var(--r-chip);border:1px solid var(--border);font-size:12px;font-weight:600;}
.badge:hover{background:var(--card2);border-color:var(--accent);}
.footer{color:var(--muted);font-size:12px;text-align:center;margin-top:12px;}

/* ── Animations ── */
@keyframes fadeSlideUp{from{opacity:0;transform:translateY(15px);}to{opacity:1;transform:translateY(0);}}
.appbar,.livebox,.card,.badges,.footer{animation:fadeSlideUp .6s cubic-bezier(.16,1,.3,1) both;}
.kpis{animation:fadeSlideUp .6s cubic-bezier(.16,1,.3,1) both;animation-delay:.1s;}
.kpis2{animation:fadeSlideUp .6s cubic-bezier(.16,1,.3,1) both;animation-delay:.18s;}
.livebox{animation-delay:.25s;}
.charts{animation:fadeSlideUp .6s cubic-bezier(.16,1,.3,1) both;animation-delay:.4s;}
.tile{animation:fadeSlideUp .6s cubic-bezier(.16,1,.3,1) both;}
.tile:nth-child(1){animation-delay:.10s;}.tile:nth-child(2){animation-delay:.14s;}
.tile:nth-child(3){animation-delay:.18s;}.tile:nth-child(4){animation-delay:.22s;}
.tile:nth-child(5){animation-delay:.26s;}.tile:nth-child(6){animation-delay:.30s;}
</style>
</head>
<body>

<!-- ═══════════════════════ APPBAR ═══════════════════════ -->
<div class="appbar">
  <div class="brand">
    <div class="orb"></div>
    <h1>داشبورد مرکز ارتباط با مشتریان</h1>
    <span class="live-pill"><span class="live-dot"></span> LIVE</span>
  </div>

  <form class="cmd" method="get">
    <div class="min-input-group" title="Start Date">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <input class="min-input" type="date" name="start" value="<?php echo htmlspecialchars($start);?>">
    </div>
    <span style="opacity:.3;font-size:16px;">/</span>
    <div class="min-input-group" title="End Date">
      <input class="min-input" type="date" name="end" value="<?php echo htmlspecialchars($end);?>">
    </div>
    <div class="sep"></div>
    <div class="min-input-group" title="Queue">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      <input class="min-input" type="text" name="queue" value="<?php echo htmlspecialchars($queue);?>" placeholder="Queue (All)" style="width:90px;">
    </div>
    <div class="min-input-group" title="SLA (seconds)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <input class="min-input" type="number" min="0" name="sla" value="<?php echo (int)$sla;?>" style="width:50px;">
    </div>
    <button class="btn-min btn-primary" type="submit">اعمال</button>
    <a class="btn-min btn-ghost" href="?start=<?php echo date('Y-m-d');?>&end=<?php echo date('Y-m-d');?>">امروز</a>
    <a class="btn-min btn-ghost" style="padding:0 10px;" href="<?php echo htmlspecialchars($xlsxUrl);?>" title="Export Excel">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    </a>
    <button id="themeToggle" class="btn-min btn-ghost" style="padding:0 10px;" type="button">🌗</button>
    <a href="logout.php" class="btn-min btn-logout">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </form>
</div>

<div style="display:none"><span id="clock_time"></span><span id="clock_date"></span></div>

<!-- ═══════════════════════ MAIN CONTENT ═══════════════════════ -->
<div id="wallboardWrapper">
  <div class="page">

    <!-- Row 1: Core KPIs -->
    <div class="grid kpis">
      <div class="tile tile1">
        <div class="label">Total <span id="d_total" class="delta flat"></span></div>
        <div id="k_total" class="value">0</div>
      </div>
      <div class="tile tile2">
        <div class="label">Answered <span id="d_ans" class="delta flat"></span></div>
        <div id="k_ans" class="value">0</div>
      </div>
      <div class="tile tile3">
        <div class="label">Customer-Ended <span id="d_abn" class="delta flat"></span></div>
        <div id="k_abn" class="value">0</div>
      </div>
      <div class="tile tile4">
        <div class="label">Answer Rate <span id="d_ar" class="delta flat"></span></div>
        <div id="k_ar" class="value">0%</div>
      </div>
      <div class="tile tile4">
        <div class="label">Avg Wait <span id="d_aw" class="delta flat"></span></div>
        <div id="k_aw" class="value">0:00</div>
      </div>
      <div class="tile tile4">
        <div class="label">Avg Talk</div>
        <div id="k_at" class="value">0:00</div>
      </div>
    </div>

    <!-- Row 2: Extended KPIs -->
    <div class="grid kpis2">
      <div class="tile tile3">
        <div class="label">Abandon Rate <span id="d_abr" class="delta flat"></span></div>
        <div id="k_abr" class="value">0%</div>
      </div>
      <div class="tile tile2">
        <div class="label">SLA % <span id="d_sla" class="delta flat"></span></div>
        <div id="k_sla" class="value">0%</div>
      </div>
      <div class="tile tile6">
        <div class="label">Wait P90 <span id="d_p90" class="delta flat"></span></div>
        <div id="k_p90" class="value">0:00</div>
      </div>
      <div class="tile tile5">
        <div class="label">Transfer Rate</div>
        <div id="k_xfer" class="value">0%</div>
      </div>
      <div class="tile tile3">
        <div class="label">Short Calls &lt;30s</div>
        <div id="k_short" class="value">0%</div>
      </div>
      <div class="tile tile6">
        <div class="label">Repeat Callers</div>
        <div id="k_repeat" class="value">0%</div>
      </div>
    </div>

    <!-- Live status bar -->
    <div class="livebox">
      <div class="live-inline">
        <span class="live-dot" style="background:var(--accent);box-shadow:0 0 8px var(--accent);"></span>
        <span>مشتریان در صف</span>
        <span id="live_waiting" class="live-val">0</span>
      </div>
      <div class="live-divider"></div>
      <div class="live-inline">
        <span class="live-dot" style="background:var(--ok);box-shadow:0 0 8px var(--ok);"></span>
        <span>تماس‌های فعال</span>
        <span id="live_active" class="live-val" style="color:var(--ok)!important;">0</span>
      </div>
      <div class="live-divider"></div>
      <div class="live-inline">
        <span>ساعت اوج (قطع مشتری)</span>
        <span id="k_peak_hour" class="live-val" style="font-size:20px;color:var(--warn)!important;">N/A</span>
      </div>
      <div class="live-divider"></div>
      <div class="live-inline">
        <span>میانگین انتظار قطع‌شده</span>
        <span id="k_aband_wait" class="live-val" style="font-size:20px;color:var(--danger)!important;">0:00</span>
      </div>
    </div>

    <!-- Time range tiles -->
    <div class="card">
      <h3>تماس‌ها در بازه‌های زمانی</h3>
      <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;">
        <div class="tile tile1"><div class="label">۱ ساعت اخیر</div><div id="r_1h" class="value">0</div></div>
        <div class="tile tile2"><div class="label">۳ ساعت اخیر</div><div id="r_3h" class="value">0</div></div>
        <div class="tile tile3"><div class="label">۲۴ ساعت اخیر</div><div id="r_24h" class="value">0</div></div>
        <div class="tile tile4"><div class="label">۷ روز اخیر</div><div id="r_7d" class="value">0</div></div>
      </div>
    </div>

    <!-- Agents table -->
    <div class="card">
      <h3>Agents Summary (Live)</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>Agent/Ext</th>
              <th>Answered</th>
              <th>Outgoing</th>
              <th>Avg Wait</th>
              <th>Avg Talk</th>
              <th>Occupancy</th>
              <th>Short Calls</th>
              <th>Transfers</th>
              <th>Paused</th>
            </tr>
          </thead>
          <tbody id="tbl_agents"></tbody>
        </table>
      </div>
    </div>

    <!-- Charts row -->
    <div class="charts">
      <div class="card">
        <h3>Answered vs Customer-Ended</h3>
        <div class="pie"><canvas id="pie_calls"></canvas></div>
        <div id="lbl_calls" class="numlabel">–</div>
      </div>
      <div class="card">
        <h3>SLA & Answer Rate</h3>
        <div class="pie"><canvas id="pie_sla"></canvas></div>
        <div id="lbl_sla" class="numlabel">–</div>
      </div>
      <div class="card">
        <h3>Wait Time Distribution</h3>
        <div id="wait_dist" class="wait-dist"></div>
        <div style="margin-top:10px;">
          <canvas id="bar_agents"></canvas>
        </div>
        <div id="lbl_agents" class="numlabel">–</div>
      </div>
    </div>

    <!-- Trends -->
    <div class="trends">
      <div class="card">
        <h3>Calls Trend (Offered / Answered / Customer-Ended)</h3>
        <canvas id="line_calls_trend" class="trend-canvas"></canvas>
        <div id="lbl_calls_trend" class="numlabel">–</div>
      </div>
      <div class="card">
        <h3>Quality Trend (Answer Rate / SLA / Avg Wait)</h3>
        <canvas id="line_quality_trend" class="trend-canvas"></canvas>
        <div id="lbl_quality_trend" class="numlabel">–</div>
      </div>
    </div>

    <!-- Frequent callers -->
    <div class="card">
      <h3>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        شماره‌های پرتکرار (۳ تماس یا بیشتر)
      </h3>
      <div class="table-wrap table-scroll-y">
        <table>
          <thead><tr><th>تاریخ</th><th>شماره تماس</th><th>تعداد</th></tr></thead>
          <tbody id="tbl_freq"><tr><td colspan="3" style="text-align:center;color:var(--muted)">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="badges">
      <span class="badge">Refresh: 3s</span>
      <span class="badge"><?php echo $queue!=='' ? 'Queue: '.htmlspecialchars($queue) : 'Queue: ALL';?></span>
      <span class="badge">SLA: <?php echo (int)$sla;?>s</span>
      <span class="badge">Range: <?php echo htmlspecialchars($start);?> → <?php echo htmlspecialchars($end);?></span>
      <span class="badge" id="badge_compare">Compare: loading…</span>
    </div>

    <div class="footer">Powered by queue_agent_summary.php • LIVE every 3s</div>
  </div>
</div>

<!-- ═══════════════════════ SCRIPTS ═══════════════════════ -->
<script>
const API_URL = <?php echo json_encode($apiUrl);?>;

let pieCalls, pieSla, barAgents, lineCallsTrend, lineQualityTrend;

/* ── Theme (cycle: dark → light → high-contrast) ── */
(function(){
  const root = document.documentElement;
  const order = ['dark','light','hc'];
  const icons = {dark:'🌙', light:'☀️', hc:'◐'};
  function apply(t){
    root.classList.remove('light','hc');
    if(t!=='dark') root.classList.add(t);
    localStorage.setItem('theme', t);
    const btn=document.getElementById('themeToggle');
    if(btn){ btn.textContent=icons[t]; btn.title='Theme: '+t; }
    [pieCalls,pieSla,barAgents,lineCallsTrend,lineQualityTrend].forEach(c=>{ try{c&&c.update('none');}catch(_){} });
  }
  const saved = localStorage.getItem('theme');
  apply(order.includes(saved) ? saved : 'dark');
  document.getElementById('themeToggle')?.addEventListener('click',()=>{
    const cur = root.classList.contains('hc') ? 'hc' : root.classList.contains('light') ? 'light' : 'dark';
    apply(order[(order.indexOf(cur)+1) % order.length]);
  });
})();

/* ── Helpers ── */
function setText(id,v){ const el=document.getElementById(id); if(el) el.textContent=v; }
function n(x){ return (x||0).toLocaleString('en-US'); }
function secToHMS(s){ s=Math.max(0,parseInt(s||0)); const h=Math.floor(s/3600),m=Math.floor((s%3600)/60),ss=s%60; return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(ss).padStart(2,'0')}`; }

function makeGrad(ctx, area, stops){
  if(!area) return null;
  const g=ctx.createLinearGradient(area.left,area.top,area.right,area.bottom);
  stops.forEach(s=>g.addColorStop(s.at,s.color));
  return g;
}

/* ── Vibrant Chart Colors ── */
function colorfulStops(type,slice){
  if(type==='calls') return slice===0
    ? [{at:0,color:'#34d399'},{at:1,color:'#10b981'}] /* Vibrant Green */
    : [{at:0,color:'#fb7185'},{at:1,color:'#e11d48'}]; /* Vibrant Red */
  return slice===0
    ? [{at:0,color:'#60a5fa'},{at:1,color:'#3b82f6'}] /* Vibrant Blue */
    : [{at:0,color:'#fbbf24'},{at:1,color:'#ea580c'}]; /* Vibrant Amber/Orange */
}

const CenterText={
  id:'centerText',
  afterDraw(chart,_,opts){
    const {ctx,chartArea}=chart; if(!chartArea) return;
    const cx=(chartArea.left+chartArea.right)/2, cy=(chartArea.top+chartArea.bottom)/2;
    const t=typeof opts.text==='function'?opts.text():(opts.text||'');
    const s=typeof opts.subtext==='function'?opts.subtext():(opts.subtext||'');
    ctx.save();
    ctx.textAlign='center'; ctx.textBaseline='middle';
    ctx.fillStyle=getComputedStyle(document.documentElement).getPropertyValue('--text')||'#f8fafc';
    ctx.font='900 18px Vazirmatn,Inter,system-ui';
    ctx.fillText(t,cx,cy-6);
    if(s){ctx.globalAlpha=.85;ctx.font='12px Vazirmatn,Inter,system-ui';ctx.fillText(s,cx,cy+12);}
    ctx.restore();
  }
};

/* ── Clean/filter ghost agents ── */
function cleanAgents(list){
  if(!Array.isArray(list)) return [];
  return list.filter(a=>{
    const ext=String(a.ext||'').trim().toLowerCase();
    return ext!=='n'&&ext!=='h'&&ext!=='s'&&ext!=='';
  }).map(a=>{
    let name=String(a.ext||'');
    if(name.includes('Local')||name.includes('/')){
      const m=name.match(/Local\/(\d+)/)||name.match(/^(\d+)/)||name.match(/\/(\d+)/);
      if(m&&m[1]) a.ext=m[1];
    }
    return a;
  });
}

/* ── Delta badges ── */
function setDelta(id, cur, prev, lowerIsBetter=false){
  const el=document.getElementById(id); if(!el) return;
  if(prev===null||prev===undefined||isNaN(prev)||isNaN(cur)){ el.textContent=''; el.className='delta flat'; return; }
  const diff=parseFloat((cur-prev).toFixed(1));
  if(diff===0){ el.textContent='→'; el.className='delta flat'; return; }
  const improved = lowerIsBetter ? diff<0 : diff>0;
  const arrow = diff>0 ? '▲' : '▼';
  const abs = Math.abs(diff);
  el.textContent=`${arrow} ${abs}`;
  el.className='delta '+(improved?'up':'down');
}

/* ── Wait distribution ── */
function renderWaitDist(waitSeconds){ return; }

function buildWaitDistFromKpi(k){
  const box=document.getElementById('wait_dist'); if(!box) return;
  const p50=k.wait_p50||0, p90=k.wait_p90||0, p95=k.wait_p95||0, pMax=k.wait_max||0;
  const items=[
    {label:'P50 (med)', val:mmss(p50), pct:50, color:'var(--ok)'},
    {label:'P90', val:mmss(p90), pct:90, color:'var(--warn)'},
    {label:'P95', val:mmss(p95), pct:95, color:'#f97316'},
    {label:'Max', val:mmss(pMax), pct:100, color:'var(--danger)'},
  ];
  box.innerHTML=items.map(it=>`
    <div class="wdist-row">
      <div class="wdist-label">${it.label}</div>
      <div class="wdist-bar-wrap">
        <div class="wdist-bar" style="width:${Math.min(100,Math.round(it.pct))}%;background:${it.color};"></div>
      </div>
      <div class="wdist-count">${it.val}</div>
    </div>
  `).join('');
}

function mmss(s){ s=Math.max(0,parseInt(s||0)); return `${Math.floor(s/60)}:${String(s%60).padStart(2,'0')}`; }

/* ── Chart init ── */
function initCharts(){
  pieCalls=new Chart(document.getElementById('pie_calls'),{
    type:'doughnut',
    data:{labels:['Answered','Customer-Ended'],datasets:[{
      data:[0,0],
      backgroundColor:(ctx)=>{const{chart,dataIndex}=ctx; return makeGrad(chart.ctx,chart.chartArea,colorfulStops('calls',dataIndex))||'#888';},
      borderWidth:0,hoverOffset:8,spacing:4,borderRadius:8
    }]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
      animation:{animateRotate:true,duration:600,easing:'easeOutQuart'},
      plugins:{legend:{display:false},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',padding:10,borderColor:'rgba(255,255,255,.1)',borderWidth:1,displayColors:false},
        centerText:{text:()=>'0',subtext:()=>'Total'}}
    },plugins:[CenterText]
  });

  pieSla=new Chart(document.getElementById('pie_sla'),{
    type:'doughnut',
    data:{labels:['AnswerRate %','SLA %'],datasets:[{
      data:[0,0],
      backgroundColor:(ctx)=>{const{chart,dataIndex}=ctx; return makeGrad(chart.ctx,chart.chartArea,colorfulStops('sla',dataIndex))||'#888';},
      borderWidth:0,hoverOffset:8,spacing:4,borderRadius:8
    }]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
      animation:{animateRotate:true,duration:600,easing:'easeOutQuart'},
      plugins:{legend:{display:false},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',padding:10,borderColor:'rgba(255,255,255,.1)',borderWidth:1,displayColors:false},
        centerText:{text:()=>`${Math.round(pieSla?.data?.datasets[0]?.data?.[0]||0)}%`,subtext:()=>'Answer Rate'}}
    },plugins:[CenterText]
  });

  barAgents=new Chart(document.getElementById('bar_agents'),{
    type:'bar',
    data:{labels:[],datasets:[{data:[],backgroundColor:'#3b82f6',borderWidth:0,borderRadius:6,barPercentage:.7,categoryPercentage:.8}]},
    options:{
      indexAxis:'x',maintainAspectRatio:false,layout:{padding:{top:6,right:6,bottom:6,left:6}},
      plugins:{legend:{display:false},tooltip:{backgroundColor:'rgba(15,23,42,.95)',padding:10,borderColor:'rgba(255,255,255,.1)',borderWidth:1,
        callbacks:{title:(i)=>i[0]?.label||'',label:(c)=>`Answered: ${c.parsed.y}`},displayColors:false}},
      scales:{x:{ticks:{autoSkip:true,maxRotation:30,callback:(v,i,tks)=>{const s=String(tks[i].label??'');return s.length>10?s.slice(0,9)+'…':s;}},grid:{display:false}},
        y:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(148,163,184,.15)'}}}
    }
  });

  lineCallsTrend=new Chart(document.getElementById('line_calls_trend'),{
    type:'line',
    data:{labels:[],datasets:[
      {label:'Offered',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#3b82f6'},
      {label:'Answered',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#10b981'},
      {label:'Customer-Ended',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#f43f5e'}
    ]},
    options:{maintainAspectRatio:false,animation:{duration:450},
      plugins:{legend:{display:true,labels:{boxWidth:12,boxHeight:12,usePointStyle:true}},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',padding:10,borderColor:'rgba(255,255,255,.1)',borderWidth:1,displayColors:true}},
      scales:{x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:10}},
        y:{beginAtZero:true,ticks:{precision:0},grid:{color:'rgba(148,163,184,.15)'}}}
    }
  });

  lineQualityTrend=new Chart(document.getElementById('line_quality_trend'),{
    type:'line',
    data:{labels:[],datasets:[
      {label:'Answer Rate %',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#8b5cf6',yAxisID:'y'},
      {label:'SLA % (Answered)',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#06b6d4',yAxisID:'y'},
      {label:'Avg Wait (min)',data:[],tension:.3,borderWidth:3,pointRadius:0,borderColor:'#f59e0b',yAxisID:'y1'}
    ]},
    options:{maintainAspectRatio:false,animation:{duration:450},
      plugins:{legend:{display:true,labels:{boxWidth:12,boxHeight:12,usePointStyle:true}},
        tooltip:{backgroundColor:'rgba(15,23,42,.95)',padding:10,borderColor:'rgba(255,255,255,.1)',borderWidth:1,displayColors:true}},
      scales:{
        x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:10}},
        y:{beginAtZero:true,min:0,max:100,grid:{color:'rgba(148,163,184,.15)'},ticks:{callback:v=>v+'%'}},
        y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{callback:v=>v}}
      }
    }
  });
}

/* ── Trends ── */
function updateTrends(tr){
  if(!tr||!Array.isArray(tr.labels)) return;
  const c=tr.calls||{}, q=tr.quality||{};
  lineCallsTrend.data.labels=tr.labels;
  lineCallsTrend.data.datasets[0].data=c.offered||[];
  lineCallsTrend.data.datasets[1].data=c.answered||[];
  lineCallsTrend.data.datasets[2].data=c.customer_ended||[];
  lineCallsTrend.update('none');

  const awMin=(q.avg_wait_sec||[]).map(s=>Math.round((Number(s||0)/60)*10)/10);
  lineQualityTrend.data.labels=tr.labels;
  lineQualityTrend.data.datasets[0].data=q.answer_rate||[];
  lineQualityTrend.data.datasets[1].data=q.sla_answered||[];
  lineQualityTrend.data.datasets[2].data=awMin;
  lineQualityTrend.update('none');

  const bsec=tr.bucket_sec||0;
  const lbl=bsec?`Bucket: ${Math.round(bsec/60)} min`:'–';
  setText('lbl_calls_trend',lbl);
  setText('lbl_quality_trend',lbl);
}

/* ── Previous period cache ── */
let _prevKpi = null;

/* ── Main load ── */
async function load(){
  try{
    const res=await fetch(API_URL,{cache:'no-store'});
    if(!res.ok) return;
    const data=await res.json();
    const k=data.kpi||{};
    const prev=data.compare||null;

    /* Row 1 KPIs */
    setText('k_total', n(k.total_calls));
    setText('k_ans',   n(k.answered_calls));
    setText('k_abn',   n(k.no_answered));
    setText('k_ar',    (k.answer_rate||0)+'%');
    setText('k_aw',    k.avg_wait_mmss||'0:00');
    setText('k_at',    k.avg_talk_mmss||'0:00');

    /* Row 2 KPIs */
    setText('k_abr',   (k.abandon_rate||0)+'%');
    setText('k_sla',   (k.sla_answered||0)+'%');
    setText('k_p90',   k.wait_p90_mmss||'0:00');
    setText('k_xfer',  (k.transfer_rate||0)+'%');
    setText('k_short', (k.short_call_rate||0)+'%');
    setText('k_repeat',(k.repeat_caller_rate||0)+'%');

    /* Delta badges vs previous period */
    if(prev){
      setDelta('d_total', k.total_calls, prev.total_calls);
      setDelta('d_ans',   k.answered_calls, prev.answered_calls);
      setDelta('d_abn',   k.no_answered, prev.no_answered, true);
      setDelta('d_ar',    k.answer_rate, prev.answer_rate);
      setDelta('d_aw',    k.avg_wait_sec, prev.avg_wait_sec, true);
      setDelta('d_abr',   k.abandon_rate, prev.abandon_rate, true);
      setDelta('d_sla',   k.sla_answered, prev.sla_answered||0);
      setDelta('d_p90',   k.wait_p90, prev.wait_p90, true);
      const win=prev.window_start&&prev.window_end?`${prev.window_start} → ${prev.window_end}`:'prev period';
      setText('badge_compare',`Compare: ${win}`);
    }

    /* Live box */
    const live=data.live||{};
    setText('live_waiting', live.queue_waiting||0);
    setText('live_active',  live.active_calls||0);
    setText('k_aband_wait', k.avg_abandon_wait_mmss||'0:00');

    /* Peak hour */
    const elPeak=document.getElementById('k_peak_hour');
    if(elPeak){
      if((k.peak_abandon_count||0)>0&&k.peak_abandon_hour){
        const tp=k.peak_abandon_hour.split(' ')[1]||k.peak_abandon_hour;
        elPeak.textContent=tp;
        elPeak.title=`${n(k.peak_abandon_count)} Customer-Ended during ${k.peak_abandon_hour}`;
      } else { elPeak.textContent='N/A'; elPeak.title=''; }
    }

    /* Pie: calls */
    const ans=k.answered_calls||0, noan=k.no_answered||0, tot=k.total_calls||(ans+noan);
    pieCalls.data.datasets[0].data=[ans,noan];
    pieCalls.options.plugins.centerText.text=()=>n(tot);
    pieCalls.options.plugins.centerText.subtext=()=>'Total';
    pieCalls.update('none');
    let lblC=`Answered: ${n(ans)} | Customer-Ended: ${n(noan)}`;
    if((k.peak_abandon_count||0)>0){ const hr=(k.peak_abandon_hour||'').split(' ')[1]||''; if(hr) lblC+=` (Peak: ${hr.split(':')[0]}:00)`; }
    setText('lbl_calls',lblC);

    /* Pie: SLA */
    const ar=Math.round(k.answer_rate||0), sla=Math.round(k.sla_answered||0);
    pieSla.data.datasets[0].data=[ar,sla];
    pieSla.options.plugins.centerText.text=()=>`${ar}%`;
    pieSla.options.plugins.centerText.subtext=()=>'Answer Rate';
    pieSla.update('none');
    setText('lbl_sla',`AnswerRate: ${ar}% | SLA: ${sla}% | P90: ${k.wait_p90_mmss||'0:00'}`);

    /* Wait distribution */
    buildWaitDistFromKpi(k);

    /* Pause map for agents table */
    const pauseMap={};
    (data.workforce||[]).forEach(r=>{ const ext=(r.ext||r.agent||'').toString(); if(!ext) return; pauseMap[ext]=(pauseMap[ext]||0)+(parseInt(r.pause_sec||0)||0); });

    /* Agents bar + table */
    const raw=cleanAgents(data.agents||[]);
    const agents=raw.sort((a,b)=>(b.answered||0)-(a.answered||0)).slice(0,10);

    barAgents.data.labels=agents.map(a=>a.ext||'');
    barAgents.data.datasets[0].data=agents.map(a=>a.answered||0);
    const area=barAgents.chartArea;
    if(area) barAgents.data.datasets[0].backgroundColor=makeGrad(barAgents.ctx,area,[{at:0,color:'#60a5fa'},{at:1,color:'#3b82f6'}]);
    barAgents.update('none');
    setText('lbl_agents',`Top ${agents.length} agents`);

    const status=data.agent_status||{};
    const tb=document.getElementById('tbl_agents'); tb.innerHTML='';
    for(const a of agents){
      const ext=a.ext||'';
      const on=status[ext]?1:0;
      const cls=on?'oncall':'available';
      const pausedSec=pauseMap[ext]||0;
      const occPct=a.occupancy_pct||0;
      const shortRate=a.short_call_rate||0;
      const xfers=a.transfer_count||0;

      const phone=on
        ?`<span class="phone ring" aria-label="On call"><svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.5 2.9 3.7 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .4 2 .6 3.1.6.7 0 1.3.6 1.3 1.3v3.4c0 .7-.6 1.3-1.3 1.3C10.9 22.6 1.4 13.1 1.4 1.3 1.4.6 2 .1 2.7.1H6c.7 0 1.3.6 1.3 1.3 0 1.1.2 2.1.6 3.1.1.4 0 .9-.2 1.2l-2.1 2.1z"/></svg></span>`
        :`<span class="phone" aria-label="Idle"><svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.5 2.9 3.7 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .4 2 .6 3.1.6.7 0 1.3.6 1.3 1.3v3.4c0 .7-.6 1.3-1.3 1.3C10.9 22.6 1.4 13.1 1.4 1.3 1.4.6 2 .1 2.7.1H6c.7 0 1.3.6 1.3 1.3 0 1.1.2 2.1.6 3.1.1.4 0 .9-.2 1.2l-2.1 2.1z"/></svg></span>`;

      const occBar=`<div class="occ-bar-wrap" title="Occupancy ${occPct}%"><div class="occ-bar" style="width:${Math.min(100,occPct)}%"></div></div>`;
      const shortBadge=shortRate>10?`<span class="count-badge warn-badge">${shortRate}%</span>`:`<span>${shortRate}%</span>`;
      const outgoing=a.outgoing_calls||0;

      tb.insertAdjacentHTML('beforeend',`
        <tr class="${cls}">
          <td>${phone}</td>
          <td title="${ext}">${ext}</td>
          <td>${a.answered||0}</td>
          <td><span class="count-badge">${outgoing}</span></td>
          <td>${a.avg_wait||'0:00'}</td>
          <td>${a.avg_talk||'0:00'}</td>
          <td>${occBar} ${occPct}%</td>
          <td>${shortBadge}</td>
          <td>${xfers}</td>
          <td data-sec="${pausedSec}">${secToHMS(pausedSec)}</td>
        </tr>`);
    }

    /* Frequent callers */
    const tbFreq=document.getElementById('tbl_freq');
    if(tbFreq&&data.frequent_callers){
      if(!data.frequent_callers.length){
        tbFreq.innerHTML='<tr><td colspan="3" style="text-align:center;color:var(--muted)">موردی یافت نشد</td></tr>';
      } else {
        tbFreq.innerHTML=data.frequent_callers.map(r=>`<tr><td>${r.date}</td><td>${r.number}</td><td><span class="count-badge">${r.count}</span></td></tr>`).join('');
      }
    }

    /* Trends */
    if(data.trends) updateTrends(data.trends);

  }catch(e){}
}

/* ── Time-range tiles ── */
const TZ='Asia/Tehran';
function toParts(dt){ return new Intl.DateTimeFormat('en-CA',{timeZone:TZ,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).formatToParts(dt).reduce((acc,p)=>(acc[p.type]=p.value,acc),{}); }
function fmtDT(parts){ return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`; }
function buildRangeUrl(msBack){
  const endDt=new Date(), startDt=new Date(Date.now()-msBack);
  const sp=toParts(startDt), ep=toParts(endDt);
  const params=new URLSearchParams({api:'1',live:'1',refresh:'3',start:fmtDT(sp),end:fmtDT(ep)});
  const pageQ=new URLSearchParams(location.search);
  if(pageQ.get('queue')) params.set('queue',pageQ.get('queue'));
  if(pageQ.get('sla')) params.set('sla',pageQ.get('sla'));
  return 'queue_agent_summary.php?'+params;
}
async function fetchRange(msBack){
  try{
    const r=await fetch(buildRangeUrl(msBack),{cache:'no-store'});
    if(!r.ok) return 0;
    const js=await r.json(); const k=js.kpi||{};
    return (k.total_calls!=null)?k.total_calls:((k.answered_calls||0)+(k.no_answered||0));
  }catch(_){ return 0; }
}
async function loadRanges(){
  const ranges={r_1h:3600000,r_3h:10800000,r_24h:86400000,r_7d:604800000};
  const keys=Object.keys(ranges);
  const vals=await Promise.all(keys.map(k=>fetchRange(ranges[k])));
  keys.forEach((k,i)=>setText(k,n(vals[i])));
}

/* ── Clock ── */
const clockFmtTime=new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:TZ});
const clockFmtDate=new Intl.DateTimeFormat('en-GB',{weekday:'long',year:'numeric',month:'long',day:'numeric',timeZone:TZ});
function tickClock(){
  const now=new Date();
  const t=document.getElementById('clock_time'),d=document.getElementById('clock_date');
  if(t) t.textContent=clockFmtTime.format(now);
  if(d) d.textContent=clockFmtDate.format(now);
}
if(window.__clockInterval) clearInterval(window.__clockInterval);
window.__clockInterval=setInterval(tickClock,1000); tickClock();

/* ── Scale wallboard ── */
function scaleWallboard(){
  const wr=document.getElementById('wallboardWrapper'); if(!wr) return;
  const scale=Math.min(1,(window.innerWidth-32)/1288);
  wr.style.transform=`scale(${scale})`;
  setTimeout(()=>{ document.body.style.minHeight=`${wr.offsetHeight*scale+100}px`; },50);
}
scaleWallboard(); window.addEventListener('resize',scaleWallboard);

/* ── Boot ── */
initCharts();
load();
loadRanges();
setInterval(load,3000);
setInterval(loadRanges,3000);
</script>
</body>
</html>