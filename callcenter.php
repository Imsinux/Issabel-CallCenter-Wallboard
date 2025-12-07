<?php
/* --- 1. SECURITY CHECK --- */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: wallboard.php"); // Adjust if your login page has a different name
    exit;
}
/* ------------------------- */

/* Call Center Dashboard – FIXED VERSION
 * - Fixed: Filtered out 'n' and 'h' ghost agents.
 * - Fixed: Auto-cleaning of Local/ channels to show extensions.
 * - Added: GitHub Signature (imsinux)
 */

date_default_timezone_set('Asia/Tehran');
header('Cache-Control: no-store');

$start = $_GET['start'] ?? date('Y-m-d');
$end   = $_GET['end']   ?? date('Y-m-d');
$queue = $_GET['queue'] ?? '';
$sla   = (int)($_GET['sla'] ?? 20);

$query   = 'start='.urlencode($start).'&end='.urlencode($end).'&sla='.$sla.($queue!=='' ? '&queue='.urlencode($queue) : '');
$apiUrl  = 'queue_agent_summary.php?api=1&live=1&refresh=3&'.$query;
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Vazirmatn:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{
  /* --- Original Dark Mode --- */
  --bg:#0e1321; --panel:#11182b; --card:#121a30; --card2:#0f1528;
  --text:#e8eef6; --muted:#9fb0c3; --border:#1f2a44;
  --accent:#6aa3ff; --accent2:#7ad3ff;
  --ok:#22c55e; --avail:#3b82f6; --pause:#a5b4c3; --danger:#ef4444;
  --shadow:0 16px 34px rgba(0,0,0,.35);

  --tile1:linear-gradient(135deg, rgba(48,84,150,.65), rgba(28,52,98,.65));
  --tile2:linear-gradient(135deg, rgba(28,102,78,.65), rgba(17,60,46,.65));
  --tile3:linear-gradient(135deg, rgba(114,74,18,.65), rgba(70,46,12,.65));
  --tile4:linear-gradient(135deg, rgba(60,72,96,.65), rgba(36,44,60,.65));
  
  --r-card:16px; --r-btn:12px; --r-chip:999px;
}

:root.light{
  /* --- Original Light Mode (Brighter Glass) --- */
  --bg:#f0f3f8; --panel:#e7ecf3; --card:#ffffff; --card2:#ffffff;
  --text:#000000; --muted:#475569; --border:#cbd5e1;
  --accent:#0d47a1; --accent2:#1e88e5;
  --ok:#16a34a; --avail:#1e40af; --pause:#64748b; --danger:#b91c1c;
  --shadow:0 12px 26px rgba(0,0,0,.15);
  
  --tile1:linear-gradient(135deg, #F5EBE0, #fdfdfdff);
  --tile2:linear-gradient(135deg, #b3fa97ff, #ffffffff);
  --tile3:linear-gradient(135deg, #fdb6b6ff, #fdfdfdff);
  --tile4:linear-gradient(135deg, #99f5f0ff, #fdfdfdff);
}

*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 "Vazirmatn","Inter",sans-serif}
:where(.value,.live-val,#clock_time){font-variant-numeric:tabular-nums lining-nums}

/* Neon numerals in DARK only */
:root:not(.light) :is(.value,.live-val,#clock_time){
  color:#bfe4ff;
  text-shadow:0 0 10px rgba(106,163,255,.70),0 0 20px rgba(122,211,255,.50);
}
:root.light :is(.kpis .value, #clock_time){ color:var(--text); font-weight:900; }
:root.light .tile .label, :root.light .live-label { color:var(--text); opacity: 0.8; }

/* --- APPBAR --- */
.appbar {
  position: sticky; top: 0; z-index: 50;
  padding: 10px 24px;
  background: rgba(14, 19, 33, 0.85);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
  display: flex; align-items: center; justify-content: space-between;
}
:root.light .appbar { background: rgba(255, 255, 255, 0.9); border-bottom: 1px solid rgba(0, 0, 0, 0.06); }

.brand { display: flex; align-items: center; gap: 12px; }
.brand h1 { font-size: 16px; font-weight: 700; margin: 0; opacity: 0.9; letter-spacing: -0.5px; }
.brand .orb { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 10px var(--accent); }

.cmd { display: flex; align-items: center; gap: 10px; }

.min-input-group {
  position: relative; display: flex; align-items: center;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 8px; padding: 0 8px; height: 36px; transition: 0.2s;
}
:root.light .min-input-group { background: #fff; border-color: #e2e8f0; }
.min-input-group:focus-within { border-color: var(--accent); background: rgba(0,0,0,0.2); }
:root.light .min-input-group:focus-within { background: #fff; }

.min-input-group svg { width: 14px; height: 14px; opacity: 0.5; pointer-events: none; }
.min-input {
  background: transparent; border: none; color: var(--text);
  font-family: inherit; font-size: 13px; font-weight: 600;
  height: 100%; outline: none; padding: 0 8px;
}
input[type="date"]::-webkit-calendar-picker-indicator { opacity: 0.4; cursor: pointer; filter: invert(var(--dark-inv, 1)); }
:root.light input[type="date"]::-webkit-calendar-picker-indicator { --dark-inv: 0; }

.btn-min {
  height: 36px; padding: 0 16px; border-radius: 8px; border: none;
  font-size: 13px; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
  transition: transform 0.1s;
  text-decoration: none;
}
.btn-min:active { transform: scale(0.96); }
.btn-primary { background: var(--avail); color: #fff; box-shadow: 0 2px 10px rgba(59, 130, 246, 0.3); }
.btn-ghost { background: transparent; color: var(--muted); border: 1px solid rgba(255,255,255,0.1); }
:root.light .btn-ghost { border-color: rgba(0,0,0,0.1); }
.btn-ghost:hover { background: rgba(255,255,255,0.05); color: var(--text); }
.btn-logout { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
.btn-logout:hover { background: rgba(239, 68, 68, 0.25); color: #fca5a5; }

.sep { width: 1px; height: 20px; background: rgba(255,255,255,0.1); margin: 0 4px; }
:root.light .sep { background: rgba(0,0,0,0.1); }

/* --- LIVE PILL --- */
.live-pill { display:inline-flex; align-items:center; gap:6px; padding:0 10px; border-radius:var(--r-chip); border:1px solid var(--border); background:rgba(34,197,94,0.1); color:var(--text); font-size:11px; height:24px; font-weight:bold; }
.live-dot { width:7px; height:7px; border-radius:50%; background:var(--ok); animation:pulse 1.6s infinite ease-out; box-shadow: 0 0 5px var(--ok); }
@keyframes pulse{from{transform:scale(1);opacity:1}to{transform:scale(1.7);opacity:0}}

/* --- LAYOUT --- */
#wallboardWrapper { width: 1288px; max-width: 1288px; margin: 20px auto 32px; padding: 0 16px; transform-origin: top center; transition: transform 0.2s ease-out; }
.page{max-width:none;margin:0;padding:0;}

.grid{display:grid;gap:12px}
.kpis{grid-template-columns:repeat(6,1fr)}
@media(max-width:1100px){.kpis{grid-template-columns:repeat(3,1fr)}}
.tile{padding:12px;border-radius:var(--r-card);border:1px solid var(--border);box-shadow:var(--shadow)}
.tile .label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.tile .value{font-weight:800;font-size:22px}
.tile1{background:var(--tile1)} .tile2{background:var(--tile2)} .tile3{background:var(--tile3)} .tile4{background:var(--tile4)}

/* Live queue widget */
@keyframes livePulse { 0% { transform: scale(1); box-shadow: 0 4px 16px rgba(0,0,0,.4); } 50% { transform: scale(1.01); box-shadow: 0 0 24px rgba(106,163,255,.35); } 100% { transform: scale(1); box-shadow: 0 4px 16px rgba(0,0,0,.4); } }
.livebox{ display:flex; align-items:center; justify-content:center; gap:10px; background:linear-gradient(135deg, rgba(30,60,120,.8), rgba(20,40,90,.8)); border:1px solid rgba(106,163,255,.4); border-radius:calc(var(--r-card) + 2px); box-shadow:0 4px 16px rgba(0,0,0,.4); padding:14px 18px; text-align:center; animation: livePulse 2.5s infinite ease-in-out; margin-top:12px; justify-content: center; align-items: center; gap: 24px;}
.livebox:hover{ transform:none; box-shadow:0 0 20px rgba(106,163,255,.35); }
.live-left{ display:flex; align-items:center; justify-content:center; gap:10px; }
.live-inline{ display:flex; align-items:center; justify-content:center; gap:8px; font-size:15px; color:#e9f2ff; font-weight:600; }
.live-val{ font-weight:900; font-size:26px; color:#ffffff !important; text-shadow: 0 0 10px rgba(255,255,255,.5); }

/* Cards & Charts */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r-card);box-shadow:var(--shadow);padding:12px; margin-top:12px;}
.card h3{margin:0 0 8px;font-size:13px;color:var(--muted);letter-spacing:.2px; display:flex; align-items:center; gap:8px;}
.charts{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:12px; margin-top:12px;}
@media(max-width:1100px){.charts{grid-template-columns:1fr}}
.pie{width:170px;height:170px;margin:0 auto;display:grid;place-items:center}
#bar_agents{height:220px !important; max-height:none !important;}
.numlabel{text-align:center;font-size:.8rem;color:var(--muted);margin-top:.35rem}

/* ====================================================
   UNIVERSAL TABLE STYLING (Beautiful Glass Blue)
   ====================================================
*/
.table-wrap {
  border:1px solid var(--border); border-radius:var(--r-card);
  /* Slightly transparent background for glass effect */
  background: rgba(14, 19, 33, 0.3); 
  /* Custom Scrollbar */
  scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
:root.light .table-wrap { background: rgba(255,255,255,0.5); }

.table-wrap::-webkit-scrollbar { width: 6px; height: 6px; }
.table-wrap::-webkit-scrollbar-track { background: transparent; }
.table-wrap::-webkit-scrollbar-thumb { background-color: var(--border); border-radius: 4px; }

table { border-collapse:collapse; width:100%; min-width:720px; }
thead th { 
  position:sticky; top:0; z-index:10;
  text-align:right; font-size:12px; color:var(--accent); letter-spacing:0.5px;
  /* Glass gradient header using original colors */
  background: linear-gradient(to bottom, var(--panel), rgba(106,163,255, 0.1));
  backdrop-filter: blur(6px);
  border-bottom: 2px solid rgba(106,163,255, 0.25);
  padding: 12px 14px;
}
tbody td {
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
  font-variant-numeric: tabular-nums;
  transition: background 0.2s;
  color: var(--text);
}
/* Unified Hover Effect */
tbody tr:hover { background: rgba(106,163,255, 0.08) !important; }
tbody tr:nth-child(even) { background: rgba(255,255,255,0.015); }
:root.light tbody tr:nth-child(even) { background: rgba(0,0,0,0.015); }

/* Specific: Agent Status Colors (Original Neon Glass) */
tr.oncall td { color:var(--ok); font-weight:800; background:rgba(43,217,100,.10); }
tr.available td { color:var(--avail); font-weight:800; background:rgba(106,163,255,.10); }
tr.paused td { color:var(--pause); font-weight:700; background:rgba(165,180,195,.12); }

/* Specific: Frequent Callers Scroll Logic */
.table-scroll-y { max-height: 300px; overflow-y: auto; } 

/* Count Badges (Glass Blue) */
.count-badge { 
  display:inline-block; padding: 2px 10px; border-radius:6px; 
  background: rgba(106,163,255, 0.15); color: var(--accent); 
  font-weight: 700; font-size:12px; border: 1px solid rgba(106,163,255, 0.25);
}

/* Footer */
.badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.badge{background:rgba(106,163,255,.12);color:var(--accent);padding:4px 10px;border-radius:var(--r-chip);border:1px solid rgba(106,163,255,.2);font-size:12px}
.footer{color:var(--muted);font-size:12px;text-align:center;margin-top:12px}

/* Ringing phone */
.phone{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:8px;position:relative;color:var(--muted)}
.phone svg{width:14px;height:14px;display:block}
.phone.ring{color:var(--ok);background:rgba(43,217,100,.10);box-shadow:0 0 0 1px rgba(43,217,100,.25) inset}
@keyframes ring{from{transform:scale(.8);opacity:1}to{transform:scale(1.4);opacity:0}}
@keyframes shake{0%,100%{transform:rotate(0)}20%{transform:rotate(-12deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-8deg)}80%{transform:rotate(6deg)}}
.phone.ring svg{animation:shake .9s ease-in-out infinite}

/* --- ADDED: Github Signature --- */
.github-link {
    position: fixed;
    bottom: 12px;
    right: 12px;
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 9999;
    font-weight: 600;
    transition: all 0.2s ease;
    background: rgba(0,0,0,0.2);
    padding: 6px 12px;
    border-radius: 50px;
    border: 1px solid rgba(255,255,255,0.05);
    backdrop-filter: blur(4px);
}
.github-link:hover {
    color: var(--text);
    transform: translateY(-2px);
    background: rgba(0,0,0,0.4);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    border-color: var(--accent);
}
/* Light Mode Adjustment for Github Link */
:root.light .github-link {
    background: rgba(255,255,255,0.6);
    border-color: rgba(0,0,0,0.1);
    color: var(--muted);
}
:root.light .github-link:hover {
    color: #000;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.github-link .fa-github { font-size: 1.2em; }
</style>
</head>
<body>

<div class="appbar">
  <div class="brand">
    <div class="orb"></div>
    <h1>داشبورد مرکز ارتباط با مشتریان</h1>
    <span class="live-pill">
      <span class="live-dot"></span> LIVE
    </span>
  </div>

  <form class="cmd" method="get">
    <div class="min-input-group" title="Start Date">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
      <input class="min-input" type="date" name="start" value="<?php echo htmlspecialchars($start); ?>">
    </div>
    
    <span style="opacity:0.3; font-size:16px;">/</span>

    <div class="min-input-group" title="End Date">
      <input class="min-input" type="date" name="end" value="<?php echo htmlspecialchars($end); ?>">
    </div>

    <div class="sep"></div>

    <div class="min-input-group" title="Queue Number">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
      <input class="min-input" type="text" name="queue" value="<?php echo htmlspecialchars($queue); ?>" placeholder="Queue (All)" style="width: 90px;">
    </div>

    <div class="min-input-group" title="SLA (seconds)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
      <input class="min-input" type="number" min="0" name="sla" value="<?php echo (int)$sla; ?>" style="width: 50px;">
    </div>

    <button class="btn-min btn-primary" type="submit">اعمال</button>
    <a class="btn-min btn-ghost" href="?start=<?php echo date('Y-m-d'); ?>&end=<?php echo date('Y-m-d'); ?>">امروز</a>
    <a class="btn-min btn-ghost" style="padding:0 10px;" href="<?php echo htmlspecialchars($xlsxUrl); ?>" title="Export Excel">
       <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
    </a>
    <button id="themeToggle" class="btn-min btn-ghost" style="padding:0 10px;" type="button">🌗</button>
    
    <a href="logout.php" class="btn-min btn-logout" title="Exit">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
        <polyline points="16 17 21 12 16 7"></polyline>
        <line x1="21" y1="12" x2="9" y2="12"></line>
      </svg>
      Logout
    </a>
  </form>
</div>

<div style="display:none"><span id="clock_time"></span><span id="clock_date"></span></div>

<div id="wallboardWrapper">
  <div class="page">
    
    <div class="grid kpis">
      <div class="tile tile1"><div class="label">Total</div><div id="k_total" class="value">0</div></div>
      <div class="tile tile2"><div class="label">Answered</div><div id="k_ans" class="value">0</div></div>
      <div class="tile tile3"><div class="label">Customer-Ended</div><div id="k_abn" class="value">0</div></div>
      <div class="tile tile4"><div class="label">Answer Rate</div><div id="k_ar" class="value">0%</div></div>
      <div class="tile tile4"><div class="label">Avg Wait</div><div id="k_aw" class="value">0:00</div></div>
      <div class="tile tile4"><div class="label">Avg Talk</div><div id="k_at" class="value">0:00</div></div>
    </div>

    <div class="livebox">
      <div class="live-left">
        <span class="live-dot"></span>
        <div class="live-inline">
          <span>مشتریان در صف</span>
          <span id="live_waiting" class="live-val">0</span>
        </div>
      </div>
      <div style="width: 1px; height: 32px; background: rgba(106,163,255,.3); border-radius: 1px;"></div>
      <div class="live-inline" style="gap: 12px;">
          <span>ساعت اوج (Customer-Ended)</span>
          <span id="k_peak_hour" class="live-val" style="font-size:22px; color: #ee9e27ff !important; text-shadow: 0 0 8px #ee9e27ff;">N/A</span>
      </div>
    </div>

    <div class="card">
      <h3>تماس‌ها در بازه‌های زمانی</h3>
      <div id="ranges_box" class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px">
        <div class="tile tile1"><div class="label">۱ ساعت اخیر</div><div id="r_1h" class="value">0</div></div>
        <div class="tile tile2"><div class="label">۳ ساعت اخیر</div><div id="r_3h" class="value">0</div></div>
        <div class="tile tile3"><div class="label">۲۴ ساعت اخیر</div><div id="r_24h" class="value">0</div></div>
        <div class="tile tile4"><div class="label">۷ روز اخیر</div><div id="r_7d" class="value">0</div></div>
      </div>
    </div>

    <div class="card">
      <h3>Agents Summary (Live)</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Status</th><th>Agent/Ext</th><th>Answered</th><th>Avg Wait</th><th>Avg Talk</th><th>Paused</th></tr>
          </thead>
          <tbody id="tbl_agents"></tbody>
        </table>
      </div>
    </div>

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
        <h3>Top Agents (Answered)</h3>
        <canvas id="bar_agents"></canvas>
        <div id="lbl_agents" class="numlabel">–</div>
      </div>
    </div>
    
    <div class="card">
        <h3>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            شماره‌های پرتکرار (۳ تماس یا بیشتر)
        </h3>
        <div class="table-wrap table-scroll-y">
          <table>
             <thead>
               <tr><th>تاریخ</th><th>شماره تماس</th><th>تعداد تماس</th></tr>
             </thead>
             <tbody id="tbl_freq">
               <tr><td colspan="3" style="text-align:center;color:var(--muted)">Loading...</td></tr>
             </tbody>
          </table>
        </div>
    </div>
    
    <div class="badges">
      <span class="badge">Refresh: 3s</span>
      <span class="badge"><?php echo $queue!=='' ? 'Queue: '.htmlspecialchars($queue) : 'Queue: ALL'; ?></span>
      <span class="badge">SLA: <?php echo (int)$sla; ?>s</span>
      <span class="badge">Range: <?php echo htmlspecialchars($start); ?> → <?php echo htmlspecialchars($end); ?></span>
    </div>

    <div class="footer">Powered by queue_agent_summary.php • LIVE every 3s</div>
  </div>
</div>

<a href="https://github.com/imsinux" class="github-link" target="_blank" rel="noopener noreferrer">
    <i class="fab fa-github"></i> 
    imsinux
</a>

<script>
const API_URL = <?php echo json_encode($apiUrl); ?>;
let pieCalls, pieSla, barAgents;

(function(){
  const root = document.documentElement;
  const saved = localStorage.getItem('theme');
  if(saved === 'light') root.classList.add('light');
  const btn = document.getElementById('themeToggle');
  if(btn){
    btn.addEventListener('click', ()=>{
      root.classList.toggle('light');
      localStorage.setItem('theme', root.classList.contains('light') ? 'light' : 'dark');
      try{ pieCalls && pieCalls.update('none'); pieSla && pieSla.update('none'); barAgents && barAgents.update('none'); }catch(_e){}
    });
  }
})();

function makeLinearGradient(ctx, area, stops){
  if(!area) return null;
  const g = ctx.createLinearGradient(area.left, area.top, area.right, area.bottom);
  for(const s of stops){ g.addColorStop(s.at, s.color); }
  return g;
}
function secToHMS(total){
  total = Math.max(0, parseInt(total||0,10));
  const h = Math.floor(total/3600);
  const m = Math.floor((total%3600)/60);
  const s = total%60;
  const pad = (x)=> (''+x).padStart(2,'0');
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}
const CenterText = {
  id:'centerText',
  afterDraw(chart, _args, opts){
    const {ctx, chartArea} = chart; if(!chartArea) return;
    const cx = (chartArea.left + chartArea.right)/2;
    const cy = (chartArea.top + chartArea.bottom)/2;
    const t  = typeof opts.text==='function' ? opts.text(): (opts.text||'');
    const s  = typeof opts.subtext==='function' ? opts.subtext(): (opts.subtext||'');
    ctx.save();
    ctx.textAlign='center'; ctx.textBaseline='middle';
    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text') || '#e8eef6';
    ctx.font='900 18px Vazirmatn,Inter,system-ui';
    ctx.fillText(t, cx, cy - 6);
    if(s){ ctx.globalAlpha=.85; ctx.font='12px Vazirmatn,Inter,system-ui'; ctx.fillText(s, cx, cy + 12); }
    ctx.restore();
  }
};
function colorfulStops(type, slice){
  if(type==='calls'){
    return slice===0
      ? [{at:0,'color':'#16e27a'},{at:.5,'color':'#20d4b4'},{at:1,'color':'#40b7ff'}]
      : [{at:0,'color':'#ff7a7f'},{at:.5,'color':'#ff5fbd'},{at:1,'color':'#b07bff'}];
  }
  return slice===0
    ? [{at:0,'color':'#7dd3fc'},{at:.5,'color':'#60a5fa'},{at:1,'color':'#34d399'}]
    : [{at:0,'color':'#fca5a5'},{at:.5,'color':'#f59e0b'},{at:1,'color':'#fde047'}];
}

function initCharts(){
  pieCalls = new Chart(document.getElementById('pie_calls'), {
    type:'doughnut',
    data:{ labels:['Answered','Customer-Ended'], datasets:[{
      data:[0,0],
      backgroundColor:(ctx)=>{
        const {chart,dataIndex} = ctx;
        const gs = colorfulStops('calls', dataIndex);
        return makeLinearGradient(chart.ctx, chart.chartArea, gs) || gs.at(-1).color;
      },
      borderWidth:0, hoverOffset:8, spacing:3, borderRadius:16
    }]},
    options:{
      responsive:true, maintainAspectRatio:false, cutout:'66%',
      animation:{animateRotate:true, duration:600, easing:'easeOutQuart'},
      plugins:{ legend:{display:false}, tooltip:{backgroundColor:'rgba(15,23,42,.92)',padding:10,borderColor:'rgba(255,255,255,.08)',borderWidth:1,displayColors:false}, centerText:{text:()=>'0', subtext:()=>'Total'} }
    },
    plugins:[CenterText]
  });

  pieSla = new Chart(document.getElementById('pie_sla'), {
    type:'doughnut',
    data:{ labels:['AnswerRate %','SLA %'], datasets:[{
      data:[0,0],
      backgroundColor:(ctx)=>{
        const {chart,dataIndex} = ctx;
        const gs = colorfulStops('sla', dataIndex);
        return makeLinearGradient(chart.ctx, chart.chartArea, gs) || gs.at(-1).color;
      },
      borderWidth:0, hoverOffset:8, spacing:3, borderRadius:16
    }]},
    options:{
      responsive:true, maintainAspectRatio:false, cutout:'66%',
      animation:{animateRotate:true, duration:600, easing:'easeOutQuart'},
      plugins:{ legend:{display:false}, tooltip:{backgroundColor:'rgba(15,23,42,.92)',padding:10,borderColor:'rgba(255,255,255,.08)',borderWidth:1,displayColors:false}, centerText:{text:()=>`${Math.round(pieSla?.data?.datasets[0]?.data?.[0] || 0)}%`, subtext:()=>'Answer Rate'} }
    },
    plugins:[CenterText]
  });

  barAgents = new Chart(document.getElementById('bar_agents'), {
    type:'bar',
    data:{ labels:[], datasets:[{
      data:[], backgroundColor:'#60a5fa', borderWidth:0, borderRadius:8, barPercentage:0.8, categoryPercentage:0.7
    }]},
    options:{
      indexAxis:'x', maintainAspectRatio:false, layout:{padding:{top:6,right:6,bottom:6,left:6}},
      plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'rgba(15,23,42,.92)', padding:10, borderColor:'rgba(255,255,255,.08)', borderWidth:1, callbacks:{ title:(items)=> items[0]?.label || '', label:(ctx)=> `Answered: ${ctx.parsed.y}` }, displayColors:false } },
      scales:{ x:{ ticks:{ autoSkip:true, maxRotation:30, minRotation:0, callback:(value, idx, ticks)=>{ const s = String(ticks[idx].label ?? ''); return s.length>12 ? s.slice(0,11)+'…' : s; } }, grid:{display:false} }, y:{ beginAtZero:true, ticks:{precision:0}, grid:{color:'rgba(255,255,255,.06)'} } }
    }
  });
}

function setText(id, v){ const el=document.getElementById(id); if(el) el.textContent=v; }
function n(x){ return (x||0).toLocaleString('en-US'); }

/* NEW FUNCTION: Clean bad agent names (removes 'n' and extracts numbers from Local/) */
function cleanAndFilterAgents(agentsList) {
    if(!Array.isArray(agentsList)) return [];
    
    // 1. Filter out garbage extensions ("n", "h", "s")
    let cleaned = agentsList.filter(a => {
        const ext = String(a.ext || '').trim().toLowerCase();
        return ext !== 'n' && ext !== 'h' && ext !== 's' && ext !== '';
    });

    // 2. Fix Local Channels (e.g., Local/8002@from-queue/n -> 8002)
    cleaned.forEach(a => {
        let name = String(a.ext || '');
        if (name.includes('Local') || name.includes('/')) {
             // Try to extract just the number
             const match = name.match(/Local\/(\d+)/) || name.match(/^(\d+)/) || name.match(/\/(\d+)/);
             if (match && match[1]) {
                 a.ext = match[1];
             }
        }
    });
    
    return cleaned;
}

async function load(){
  try{
    const res = await fetch(API_URL, {cache:'no-store'});
    if(!res.ok) return;
    const data = await res.json();
    const k = data.kpi || {};

    setText('k_total', n(k.total_calls));
    setText('k_ans', n(k.answered_calls));
    setText('k_abn', n(k.no_answered));
    setText('k_ar',  (k.answer_rate||0) + '%');
    setText('k_aw',  k.avg_wait_mmss || '0:00');
    setText('k_at',  k.avg_talk_mmss || '0:00');

    const peakHourStr = k.peak_abandon_hour || '';
    const peakCount = k.peak_abandon_count || 0;
    const elPeak = document.getElementById('k_peak_hour');
    let peakTimeFullRange = ""; 
    let peakTimeHourOnly = "N/A";
    if (peakCount > 0 && peakHourStr) {
      const timePart = peakHourStr.split(' ')[1] || peakHourStr;
      peakTimeHourOnly = timePart; 
      const hour = timePart.split(':')[0];
      if (hour) peakTimeFullRange = `${hour}:00 - ${hour}:59`;
      if (elPeak) {
        elPeak.textContent = peakTimeHourOnly;
        elPeak.title = `${n(peakCount)} Customer-Ended calls during ${peakHourStr}`;
      }
    } else {
      if (elPeak) { elPeak.textContent = 'N/A'; elPeak.title = 'No Customer-Ended events in range'; }
    }

    const repNum = k.repetitive_num || 'N/A';
    const repCnt = k.repetitive_count || 0;

    const waiting = (data.live && typeof data.live.queue_waiting!=='undefined') ? (data.live.queue_waiting||0) : 0;
    setText('live_waiting', waiting);

    const answered = k.answered_calls||0;
    const noans    = k.no_answered||0;
    const total    = (k.total_calls!=null) ? k.total_calls : (answered + noans);
    pieCalls.data.datasets[0].data = [answered, noans];
    pieCalls.options.plugins.centerText.text = ()=> n(total);
    pieCalls.options.plugins.centerText.subtext = ()=> 'Total';
    pieCalls.update('none');

    let lblCallsText = `Answered: ${n(answered)} | Customer-Ended: ${n(noans)}`;
    if (peakCount > 0 && peakTimeFullRange) { lblCallsText += ` (Peak: ${peakTimeFullRange})`; }
    setText('lbl_calls', lblCallsText);

    const ar  = Math.round(k.answer_rate||0);
    const sla = Math.round(k.sla_answered||0);
    pieSla.data.datasets[0].data = [ar, sla];
    pieSla.options.plugins.centerText.text = ()=> `${ar}%`;
    pieSla.options.plugins.centerText.subtext = ()=> 'Answer Rate';
    pieSla.update('none');
    setText('lbl_sla', `AnswerRate: ${ar}% | SLA: ${sla}%`);

    const pauseMap = {};
    if (Array.isArray(data.workforce)){
      for (const row of data.workforce){
        const ext = (row.ext || row.agent || '').toString();
        if (!ext) continue;
        const sec = parseInt(row.pause_sec || 0, 10) || 0;
        pauseMap[ext] = (pauseMap[ext] || 0) + sec;
      }
    }

    /* --- FIXED AGENT LOGIC --- */
    let rawAgents = data.agents || [];
    let cleanAgents = cleanAndFilterAgents(rawAgents);
    const agents = cleanAgents.sort((a,b)=>(b.answered||0)-(a.answered||0)).slice(0,10);
    /* ------------------------- */

    const labels = agents.map(a=>a.ext || '');
    barAgents.data.labels = labels;
    barAgents.data.datasets[0].data = agents.map(a=>a.answered||0);
    const area = barAgents.chartArea;
    if(area){
      barAgents.data.datasets[0].backgroundColor = makeLinearGradient(barAgents.ctx, area, [
        {at:0, color:'#60a5fa'}, {at:.5, color:'#a78bfa'}, {at:1, color:'#f472b6'}
      ]);
    }
    barAgents.update('none');
    setText('lbl_agents', `Top ${agents.length} agents`);

    const status = data.agent_status || {};
    const tb = document.getElementById('tbl_agents'); tb.innerHTML = '';
    for(const a of agents){
      const ext = a.ext || '';
      const on = status[ext] ? 1 : 0;
      const cls = on ? 'oncall' : 'available';
      const pausedSec = pauseMap[ext] || 0;
      const phone = on
        ? `<span class=\"phone ring\" aria-label=\"On call\">\n             <svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M6.6 10.8c1.5 2.9 3.7 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .4 2 .6 3.1.6.7 0 1.3.6 1.3 1.3v3.4c0 .7-.6 1.3-1.3 1.3C10.9 22.6 1.4 13.1 1.4 1.3 1.4.6 2 .1 2.7.1H6c.7 0 1.3.6 1.3 1.3 0 1.1.2 2.1.6 3.1.1.4 0 .9-.2 1.2l-2.1 2.1z\"/></svg>\n             </span>`
        : `<span class=\"phone\" aria-label=\"Idle\">\n             <svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M6.6 10.8c1.5 2.9 3.7 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .4 2 .6 3.1.6.7 0 1.3.6 1.3 1.3v3.4c0 .7-.6 1.3-1.3 1.3C10.9 22.6 1.4 13.1 1.4 1.3 1.4.6 2 .1 2.7.1H6c.7 0 1.3.6 1.3 1.3 0 1.1.2 2.1.6 3.1.1.4 0 .9-.2 1.2l-2.1 2.1z\"/></svg>\n             </span>`;
      tb.insertAdjacentHTML('beforeend', `
        <tr class="${cls}">
          <td>${phone}</td>
          <td title="${ext}">${ext}</td>
          <td>${a.answered||0}</td>
          <td>${a.avg_wait||'0:00'}</td>
          <td>${a.avg_talk||'0:00'}</td>
          <td data-sec="${pausedSec}">${secToHMS(pausedSec)}</td>
        </tr>
      `);
    }

    // UPDATE FREQUENT CALLERS
    const tbFreq = document.getElementById('tbl_freq');
    if (tbFreq && data.frequent_callers) {
        let html = '';
        if (data.frequent_callers.length === 0) {
            html = '<tr><td colspan="3" style="text-align:center;color:var(--muted)">موردی یافت نشد (None)</td></tr>';
        } else {
            data.frequent_callers.forEach(row => {
                html += `<tr><td>${row.date}</td><td>${row.number}</td><td><span class="count-badge">${row.count}</span></td></tr>`;
            });
        }
        tbFreq.innerHTML = html;
    }

  }catch(e){ }
}

const TZ = 'Asia/Tehran';
function toParts(dt){ return new Intl.DateTimeFormat('en-CA',{ timeZone:TZ, year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false }).formatToParts(dt).reduce((acc,p)=> (acc[p.type]=p.value, acc), {}); }
function fmtYYYYMMDD_HHMM(parts){ return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`; }
function subMillis(ms){ return new Date(Date.now()-ms); }
function buildRangeUrl(startDt,endDt){ const sp=toParts(startDt), ep=toParts(endDt); const params=new URLSearchParams(); params.set('api','1'); params.set('live','1'); params.set('refresh','3'); params.set('start',fmtYYYYMMDD_HHMM(sp)); params.set('end',fmtYYYYMMDD_HHMM(ep)); const pageQ=new URLSearchParams(location.search); const q=pageQ.get('queue'); const sla=pageQ.get('sla'); if(q) params.set('queue',q); if(sla) params.set('sla',sla); return 'queue_agent_summary.php?'+params.toString(); }
async function fetchTotalCallsForRange(msBack){ const endDt=new Date(); const startDt=subMillis(msBack); const url=buildRangeUrl(startDt,endDt); try{ const r=await fetch(url,{cache:'no-store'}); if(!r.ok) throw new Error('HTTP '+r.status); const js=await r.json(); const k=js.kpi||{}; const total=(k.total_calls!=null)?k.total_calls:((k.answered_calls||0)+(k.no_answered||0)); return total||0; }catch(_e){ return 0; } }
async function loadRanges(){ const ranges={ r_1h:3600000, r_3h:10800000, r_24h:86400000, r_7d:604800000 }; const keys=Object.keys(ranges); const vals=await Promise.all(keys.map(k=>fetchTotalCallsForRange(ranges[k]))); keys.forEach((k,i)=> setText(k, n(vals[i]))); }

const CLOCK_TZ = 'Asia/Tehran';
const clockFmtTime = new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:CLOCK_TZ});
const clockFmtDate = new Intl.DateTimeFormat('en-GB',{weekday:'long',year:'numeric',month:'long',day:'numeric',timeZone:CLOCK_TZ});
function tickClock(){ const now=new Date(); const t=document.getElementById('clock_time'); const d=document.getElementById('clock_date'); if(t) t.textContent=clockFmtTime.format(now); if(d) d.textContent=clockFmtDate.format(now); }
function setupClock(){ tickClock(); if(window.__clockInterval) clearInterval(window.__clockInterval); window.__clockInterval=setInterval(tickClock,1000); }

function scaleWallboard() {
    const wrapper = document.getElementById('wallboardWrapper');
    if (!wrapper) return;
    const designWidth = 1288; 
    const viewportWidth = window.innerWidth;
    const scale = Math.min(1, (viewportWidth - 32) / designWidth);
    wrapper.style.transform = `scale(${scale})`;
    setTimeout(() => {
        const scaledHeight = wrapper.offsetHeight * scale;
        document.body.style.minHeight = `${scaledHeight + 100}px`;
    }, 50);
}

initCharts();
setupClock();
scaleWallboard();
window.addEventListener('resize', scaleWallboard);
load();
loadRanges();
setInterval(load, 3000);
setInterval(loadRanges, 3000);
</script>
</body>
</html>