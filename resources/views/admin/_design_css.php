<?php
/**
 * Admin design system — modern SaaS (Linear/Stripe style).
 *
 * Features: dark/light mode via CSS variables, indigo primary, 14px radius
 * cards, 8px spacing grid, subtle shadows, accessible contrast.
 * Loaded by layouts/admin.php via inline <style> tag.
 */
?>
<!-- rtp-admin-css-loaded -->
<style id="rtp-admin-css">
/* ============================================================
   DESIGN TOKENS — light (default) + dark (via .dark)
   ============================================================ */
:root{
  /* Surfaces */
  --bg:#F8FAFC;
  --surface:#FFFFFF;
  --surface-2:#F8FAFC;
  --surface-hover:#F1F5F9;
  --border:#E2E8F0;
  --border-strong:#CBD5E1;
  /* Text */
  --ink:#0F172A;
  --text:#334155;
  --muted:#64748B;
  --faint:#94A3B8;
  /* Primary (indigo) */
  --primary:#4F46E5;
  --primary-hover:#4338CA;
  --primary-light:#EEF2FF;
  --primary-contrast:#FFFFFF;
  /* Semantic */
  --green:#16A34A;--green-light:#DCFCE7;
  --amber:#D97706;--amber-light:#FEF3C7;
  --red:#DC2626;--red-light:#FEE2E2;
  /* Sidebar */
  --sidebar-bg:#0B1120;
  --sidebar-surface:#111827;
  --sidebar-text:#94A3B8;
  --sidebar-active:rgba(99,102,241,.15);
  --sidebar-active-text:#C7D2FE;
  /* Shadows */
  --shadow-sm:0 1px 2px rgba(0,0,0,.04),0 1px 3px rgba(0,0,0,.06);
  --shadow-md:0 4px 6px rgba(0,0,0,.04),0 10px 15px rgba(0,0,0,.05);
  --shadow-lg:0 10px 25px rgba(0,0,0,.08),0 20px 40px rgba(0,0,0,.06);
  /* Misc */
  --radius:14px;
  --radius-sm:10px;
  --radius-lg:20px;
  --ease:cubic-bezier(.2,.7,.2,1);
  --font-sans:'Inter',system-ui,sans-serif;
  --font-mono:'IBM Plex Mono',ui-monospace,monospace;
}

.dark{
  --bg:#0B1120;
  --surface:#111827;
  --surface-2:#0F172A;
  --surface-hover:#1E293B;
  --border:#1E293B;
  --border-strong:#334155;
  --ink:#F1F5F9;
  --text:#CBD5E1;
  --muted:#64748B;
  --faint:#475569;
  --primary:#818CF8;
  --primary-hover:#6366F1;
  --primary-light:rgba(99,102,241,.15);
  --primary-contrast:#FFFFFF;
  --green:#4ADE80;--green-light:rgba(34,197,94,.15);
  --amber:#FBBF24;--amber-light:rgba(251,191,36,.15);
  --red:#F87171;--red-light:rgba(239,68,68,.15);
  --sidebar-bg:#060B18;
  --sidebar-surface:#0B1120;
  --sidebar-text:#475569;
  --sidebar-active:rgba(129,140,248,.12);
  --sidebar-active-text:#A5B4FC;
  --shadow-sm:0 1px 2px rgba(0,0,0,.20);
  --shadow-md:0 4px 6px rgba(0,0,0,.25);
  --shadow-lg:0 10px 25px rgba(0,0,0,.30);
}

/* ============================================================
   PAGE BACKGROUND + SCROLLBAR
   ============================================================ */
body{
  background:var(--bg);
  color:var(--text);
  font-family:var(--font-sans);
  font-feature-settings:"cv11","ss01";
  -webkit-font-smoothing:antialiased;
  text-rendering:optimizeLegibility;
  transition:background-color .2s var(--ease),color .2s var(--ease);
}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,23,42,.16);border-radius:5px;border:2px solid transparent;background-clip:padding-box}
::-webkit-scrollbar-thumb:hover{background:rgba(15,23,42,.28);background-clip:padding-box}

/* ============================================================
   SIDEBAR — uses CSS variables so it adapts to dark mode
   ============================================================ */
aside[data-sidebar]{
  background:linear-gradient(180deg,var(--sidebar-bg) 0%,var(--sidebar-surface) 100%) !important;
  box-shadow:inset -1px 0 0 rgba(255,255,255,.04),4px 0 24px rgba(0,0,0,.18);
  position:relative;
}
aside[data-sidebar]::before{
  content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(circle at 30% 0%,rgba(99,102,241,.10),transparent 50%);
}
.dark aside[data-sidebar]{
  background:linear-gradient(180deg,var(--sidebar-bg) 0%,#060B18 100%) !important;
  box-shadow:inset -1px 0 0 rgba(255,255,255,.03),4px 0 24px rgba(0,0,0,.40);
}

/* Brand header — sticky at top of sidebar with subtle blur */
aside[data-sidebar] > div:first-child{
  position:relative;z-index:2;
  background:rgba(0,0,0,.25) !important;
  backdrop-filter:saturate(180%) blur(8px);
  -webkit-backdrop-filter:saturate(180%) blur(8px);
}

/* Custom scrollbar inside the sidebar nav */
.rtp-admin-nav{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent}
.rtp-admin-nav::-webkit-scrollbar{width:6px}
.rtp-admin-nav::-webkit-scrollbar-track{background:transparent}
.rtp-admin-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:3px}
.rtp-admin-nav::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.22)}

/* Section labels — small caps style */
.rtp-nav-section-label{
  position:relative;padding-left:14px;
}
.rtp-nav-section-label::before{
  content:"";position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:6px;height:1.5px;background:rgba(255,255,255,.20);border-radius:1px;
}

/* Nav links — smooth hover with subtle slide-right on hover */
.rtp-nav-link{
  position:relative;overflow:hidden;
}
.rtp-nav-link::before{
  content:"";position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:0;background:var(--primary);border-radius:0 3px 3px 0;
  transition:height .2s ease;
}
.rtp-nav-link:hover::before,
.rtp-nav-link.rtp-nav-active::before{height:60%}
.rtp-nav-link.rtp-nav-active{
  background:linear-gradient(90deg,rgba(99,102,241,.22) 0%,rgba(99,102,241,.06) 100%) !important;
  color:#fff !important;
}
.rtp-nav-link.rtp-nav-active .rtp-nav-icon{opacity:1 !important;color:#7FA4F5 !important}
.rtp-nav-link:hover .rtp-nav-icon{opacity:1 !important;color:#fff !important;transform:translateX(2px)}
.rtp-nav-icon{transition:transform .15s ease,color .15s ease}

/* Collapsed state — tooltips via title attribute, hide labels */
html.sidebar-collapsed aside[data-sidebar]{width:4.5rem !important}
html.sidebar-collapsed aside[data-sidebar] [data-sidebar-label],
html.sidebar-collapsed aside[data-sidebar] [data-sidebar-section]{display:none}
html.sidebar-collapsed aside[data-sidebar] .rtp-nav-link{justify-content:center;padding-inline:0}
html.sidebar-collapsed aside[data-sidebar] .rtp-nav-icon{margin-inline:auto}
html.sidebar-collapsed aside[data-sidebar] .rtp-nav-section-label{padding-left:0;text-align:center;font-size:0}
html.sidebar-collapsed aside[data-sidebar] .rtp-nav-section-label::before{display:none}

/* Sidebar footer — online indicator */
.rtp-admin-foot{position:relative;z-index:2}

/* ============================================================
   TOP BAR POLISH
   ============================================================ */
header.sticky-top{
  background:rgba(255,255,255,.96) !important;
  backdrop-filter:saturate(180%) blur(8px);
  -webkit-backdrop-filter:saturate(180%) blur(8px);
  box-shadow:0 1px 0 rgba(15,23,42,.04),0 1px 2px rgba(15,23,42,.04);
}
header.sticky-top h1{font-weight:700;letter-spacing:-.01em}
/* Search input refinement */
header.sticky-top .input{
  border-radius:10px;background:var(--surface-2);border:1.5px solid transparent;transition:all .15s
}
header.sticky-top .input:focus{background:var(--surface);border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.12)}

/* ============================================================
   PAGE HEADER COMPONENT
   Use: <div class="admin-page-header">
          <div class="admin-page-header-title">
            <h2>Orders</h2>
            <p class="admin-page-header-sub">Manage customer orders</p>
          </div>
          <div class="admin-page-header-actions">...buttons...</div>
        </div>
   ============================================================ */
.admin-page-header{
  display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:20px;
}
.admin-page-header-title h2{
  margin:0;font-size:clamp(20px,2vw,26px);font-weight:800;letter-spacing:-.02em;color:var(--ink);line-height:1.2;
}
.admin-page-header-sub{
  margin:4px 0 0;font-size:14px;color:var(--muted);line-height:1.5;max-width:600px;
}
.admin-page-header-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}

/* ============================================================
   ADMIN CARD — base surface
   ============================================================ */
.admin-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  box-shadow:var(--shadow-sm);overflow:hidden;
}
.admin-card-header{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 18px;border-bottom:1px solid var(--border);
}
.admin-card-header h3{margin:0;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.005em}
.admin-card-header a{font-size:13px;font-weight:600;color:var(--primary);text-decoration:none}
.admin-card-header a:hover{text-decoration:underline;text-underline-offset:2px}
.admin-card-body{padding:18px}

/* ============================================================
   ADMIN TABLES
   ============================================================ */
.admin-table{width:100%;border-collapse:collapse;font-size:14px}
.admin-table thead th{
  padding:10px 16px;text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
  color:var(--muted);background:var(--surface-2);border-bottom:1px solid var(--border);white-space:nowrap;
}
.admin-table tbody td{padding:12px 16px;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle}
.admin-table tbody tr:last-child td{border-bottom:0}
.admin-table tbody tr{transition:background .12s}
.admin-table tbody tr:hover{background:var(--surface-2)}
.admin-table a{color:var(--ink);font-weight:600;text-decoration:none}
.admin-table a:hover{color:var(--primary)}
.admin-table .num{text-align:right;font-variant-numeric:tabular-nums}
.admin-table .mono{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:13px}
/* Responsive wrapper to prevent horizontal overflow on small screens */
.admin-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}

/* ============================================================
   STAT CARD — premium, interactive, semantic tones
   Use: tone="blue|green|amber|red|navy|slate"
   Features: hover lift + shadow, gradient top accent per tone,
   icon tile with tone background, optional trend badge,
   optional animated count-up via .js-count-up + data-count-to.
   ============================================================ */
.admin-stat-card{
  position:relative;overflow:hidden;
  background:linear-gradient(180deg,#fff 0%,#FAFCFF 100%);
  border:1px solid var(--border);border-radius:16px;padding:18px;
  box-shadow:var(--shadow-sm);
  transition:border-color .2s,box-shadow .2s,transform .2s cubic-bezier(.2,.7,.2,1);
  display:flex;flex-direction:column;gap:6px;min-height:120px;
}
.admin-stat-card.is-link{cursor:pointer;text-decoration:none;color:inherit}
.admin-stat-card.is-link:hover{color:inherit}
.admin-stat-card:hover{
  transform:translateY(-3px);
  box-shadow:0 8px 20px rgba(15,23,42,.08),0 4px 8px rgba(15,23,42,.06);
}
.admin-stat-card:active{transform:translateY(-1px)}

/* Top row: icon tile (left) + optional trend badge (right) */
.admin-stat-card-top{
  display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px;
}
.admin-stat-card-ic{
  width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:none;
  transition:transform .2s cubic-bezier(.2,.7,.2,1);
}
.admin-stat-card:hover .admin-stat-card-ic{transform:scale(1.08) rotate(-3deg)}

/* Value — big, tabular, slight letter-spacing tighten */
.admin-stat-card-value{
  font-size:30px;font-weight:800;letter-spacing:-.035em;color:var(--ink);
  font-variant-numeric:tabular-nums;margin:2px 0 0;line-height:1.05;
  transition:color .2s;
}
.admin-stat-card.is-link:hover .admin-stat-card-value{color:var(--primary)}

/* Label + hint */
.admin-stat-card-label{font-size:13px;font-weight:600;color:var(--muted);letter-spacing:.01em;margin:0}
.admin-stat-card-hint{font-size:11.5px;color:#94A3B8;margin:2px 0 0;line-height:1.4}

/* Tone tiles — icon background */
.admin-stat-ic-blue{background:var(--primary-light);color:var(--primary)}
.admin-stat-ic-green{background:var(--green-light);color:var(--green)}
.admin-stat-ic-amber{background:var(--amber-light);color:var(--amber)}
.admin-stat-ic-red{background:var(--red-light);color:var(--red)}
.admin-stat-ic-navy{background:var(--surface-hover);color:var(--ink)}
.admin-stat-ic-slate{background:var(--surface-hover);color:var(--muted)}

/* Tone-colored top accent stripe — animates from 0 → 1 width on hover */
.admin-stat-card::before{
  content:"";position:absolute;left:0;top:0;height:3px;width:100%;
  border-radius:16px 16px 0 0;
  transform:scaleX(0);transform-origin:left;
  transition:transform .35s cubic-bezier(.2,.7,.2,1);
}
.admin-stat-card:hover::before{transform:scaleX(1)}
.admin-stat-card.admin-stat-blue::before{background:linear-gradient(90deg,var(--primary),#7FA4F5)}
.admin-stat-card.admin-stat-green::before{background:linear-gradient(90deg,var(--green),#4ADE80)}
.admin-stat-card.admin-stat-amber::before{background:linear-gradient(90deg,var(--amber),#FBBF24)}
.admin-stat-card.admin-stat-red::before{background:linear-gradient(90deg,var(--red),#F87171)}
.admin-stat-card.admin-stat-navy::before{background:linear-gradient(90deg,var(--ink),#2B4869)}
.admin-stat-card.admin-stat-slate::before{background:linear-gradient(90deg,#64748B,#94A3B8)}

/* Tone-colored hover border */
.admin-stat-card.admin-stat-blue:hover{border-background:var(--primary,.30)}
.admin-stat-card.admin-stat-green:hover{border-color:rgba(22,163,74,.30)}
.admin-stat-card.admin-stat-amber:hover{border-color:rgba(217,119,6,.35)}
.admin-stat-card.admin-stat-red:hover{border-color:rgba(220,38,38,.30)}
.admin-stat-card.admin-stat-navy:hover{border-color:rgba(20,36,58,.30)}
.admin-stat-card.admin-stat-slate:hover{border-color:rgba(100,116,139,.30)}

/* Trend badge — small pill in the top-right corner */
.admin-stat-trend{
  display:inline-flex;align-items:center;gap:3px;
  padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.01em;
}
.admin-stat-trend-up{background:rgba(22,163,74,.12);color:var(--green)}
.admin-stat-trend-down{background:rgba(220,38,38,.12);color:var(--red)}

/* Count-up animation: when the .js-count-up is in view, the value animates.
   JS sets the .is-counting class; we just provide a smooth transition on the value. */
.admin-stat-card.js-count-up .admin-stat-card-value{transition:opacity .4s ease}
.admin-stat-card.js-count-up.is-counting .admin-stat-card-value{opacity:.92}

/* ============================================================
   QUICK ACTIONS — shortcut grid on the dashboard
   ============================================================ */
.admin-quick-grid{
  display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));
}
.admin-quick-card{
  display:flex;flex-direction:column;gap:8px;padding:14px 16px;
  background:linear-gradient(135deg,var(--surface) 0%,var(--surface-2) 100%);
  border:1px solid var(--border);border-radius:var(--radius-sm);
  text-decoration:none;color:var(--ink);
  transition:border-color .15s,box-shadow .15s,transform .15s;
  position:relative;overflow:hidden;
}
.admin-quick-card::after{
  content:"→";position:absolute;right:14px;top:14px;color:var(--muted);font-size:18px;
  transition:transform .2s ease,color .15s;
}
.admin-quick-card:hover{border-color:var(--primary);box-shadow:var(--shadow-md);transform:translateY(-2px);color:var(--ink)}
.admin-quick-card:hover::after{transform:translateX(4px);color:var(--primary)}
.admin-quick-card-ic{
  width:36px;height:36px;border-radius:9px;
  background:var(--primary-light);color:var(--primary);
  display:flex;align-items:center;justify-content:center;
}
.admin-quick-card-title{font-size:14px;font-weight:700;color:var(--ink);margin:0}
.admin-quick-card-sub{font-size:12px;color:var(--muted);margin:0}

/* ============================================================
   ACTIVITY TABLE — modern hover + status pills
   ============================================================ */
.admin-table tbody tr{transition:background .12s,transform .12s}
.admin-table tbody tr:hover{background:var(--surface-2)}
.admin-table tbody tr:hover td:first-child{box-shadow:inset 3px 0 0 var(--primary)}
.admin-table tbody td:first-child{transition:box-shadow .15s}

/* ============================================================
   EMPTY STATES — modern, illustrated
   ============================================================ */
.admin-empty{
  display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;
  padding:40px 20px;color:var(--muted);
}
.admin-empty-ic{
  width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,var(--surface-hover) 0%,var(--primary-light) 100%);color:var(--muted);
  display:flex;align-items:center;justify-content:center;flex:none;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
}
.admin-empty h3{margin:0;font-size:16px;font-weight:700;color:var(--ink);letter-spacing:-.01em}
.admin-empty p{margin:0;font-size:14px;color:var(--muted);max-width:400px;line-height:1.55}
.admin-empty .btn{margin-top:6px}

/* Inline empty-state text — small, muted, centered */
.admin-empty-text{padding:32px 16px;text-align:center;color:var(--muted);font-size:14px;line-height:1.55}

/* ============================================================
   FILTER BAR
   ============================================================ */
.admin-filter-bar{
  display:grid;gap:12px;background:var(--surface);border:1px solid var(--border);
  border-radius:14px;padding:14px;box-shadow:var(--shadow-sm);
}
.admin-filter-bar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

/* ============================================================
   SECTION DIVIDER + SPACING
   ============================================================ */
.admin-section{margin-top:24px}
.admin-section-title{
  font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;
  margin:0 0 12px;
}

/* ============================================================
   STATUS BADGE — keep using components/badge.php, but align colors
   This is a refinement layer that overrides Tailwind default green/blue
   with brand tokens for the most common statuses.
   ============================================================ */
.admin-badge{
  display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;
  font-size:11px;font-weight:700;letter-spacing:.02em;text-transform:capitalize;
  border:1px solid transparent;
}
.admin-badge-ok{background:rgba(22,163,74,.12);color:var(--green);border-color:rgba(22,163,74,.20)}
.admin-badge-info{background:rgba(99,102,241,.12);color:var(--primary);border-background:var(--primary,.20)}
.admin-badge-wait{background:rgba(217,119,6,.12);color:var(--amber);border-color:rgba(217,119,6,.22)}
.admin-badge-off{background:var(--surface-hover);color:var(--muted);border-color:var(--border)}
.admin-badge-bad{background:rgba(220,38,38,.10);color:var(--red);border-color:rgba(220,38,38,.20)}

/* ============================================================
   BUTTON POLISH — enhance the existing .btn-primary / .btn-secondary
   ============================================================ */
.btn{transition:background .15s,border-color .15s,box-shadow .15s,transform .1s}
.btn-primary{box-shadow:0 1px 2px rgba(99,102,241,.20),0 4px 12px rgba(99,102,241,.18)}
.btn-primary:hover{box-shadow:0 2px 4px rgba(99,102,241,.25),0 6px 18px rgba(99,102,241,.25)}
.btn-primary:active{transform:translateY(1px)}
.btn-secondary:hover{box-shadow:var(--shadow-sm)}
.btn-danger{box-shadow:0 1px 2px rgba(220,38,38,.15)}
.btn-danger:hover{box-shadow:0 2px 4px rgba(220,38,38,.20)}
.btn:disabled,.btn[disabled]{opacity:.55;cursor:not-allowed;pointer-events:none}

/* ============================================================
   FORM INPUT POLISH
   ============================================================ */
.input{
  background:var(--surface);border:1.5px solid var(--border);border-radius:10px;
  font-size:14px;color:var(--ink);transition:border-color .15s,box-shadow .15s,background .15s;
}
.input::placeholder{color:#94A3B8}
.input:hover{border-color:#CBD5E1}
.input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.14);background:var(--surface)}
.input[aria-invalid="true"]{border-color:var(--red) !important;box-shadow:0 0 0 3px rgba(220,38,38,.12) !important}

/* ============================================================
   FOCUS VISIBLE — consistent accent outline
   ============================================================ */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,
.btn:focus-visible,.icon-btn:focus-visible,[data-dropdown-toggle]:focus-visible{
  outline:2px solid var(--primary);outline-offset:2px;border-radius:8px;
}

/* ============================================================
   AVATAR — brand navy circle
   ============================================================ */
.avatar{
  display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--ink) 0%,var(--navy-3) 100%);
  color:#fff;font-weight:700;letter-spacing:.02em;
  border-radius:50%;flex:none;
}

/* ============================================================
   RANGE TOGGLE (dashboard filter)
   ============================================================ */
.admin-range-toggle{
  display:inline-flex;gap:2px;padding:3px;background:var(--surface-hover);border:1px solid var(--border);border-radius:10px;
}
.admin-range-toggle button{
  padding:6px 12px;border:0;background:transparent;color:var(--muted);font-size:13px;font-weight:600;
  border-radius:7px;cursor:pointer;transition:all .15s;
}
.admin-range-toggle button:hover{color:var(--ink)}
.admin-range-toggle button.active{
  background:var(--surface);color:var(--ink);box-shadow:var(--shadow-sm);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media(max-width:1024px){
  .admin-page-header{align-items:flex-start}
}
@media(max-width:720px){
  .admin-page-header{margin-bottom:16px}
  .admin-card-header{flex-direction:column;align-items:flex-start;gap:8px}
  .admin-card-body{padding:14px}
  .admin-stat-card{padding:14px}
  .admin-stat-card-value{font-size:24px}
  .admin-stat-card-ic{top:14px;right:14px;width:36px;height:36px}
  .admin-table thead th,.admin-table tbody td{padding:10px 12px}
}
@media(max-width:480px){
  .admin-page-header-actions{width:100%}
  .admin-page-header-actions .btn{flex:1;min-width:0}
}

/* ============================================================
   DARK MODE — override Tailwind utility classes via CSS vars.
   These rules map the most common Tailwind classes used in the
   admin views to the dark-mode CSS variables defined in .dark class.
   ============================================================ */
.dark body{background:var(--bg);color:var(--text)}
.dark .bg-white{background-color:var(--surface) !important}
.dark .bg-slate-100{background-color:var(--bg) !important}
.dark .bg-slate-50{background-color:var(--surface-2) !important}
.dark .bg-slate-900\/50{background-color:rgba(0,0,0,.60) !important}
.dark .text-slate-900{color:var(--ink) !important}
.dark .text-slate-700{color:var(--text) !important}
.dark .text-slate-600{color:var(--muted) !important}
.dark .text-slate-500{color:var(--muted) !important}
.dark .text-slate-400{color:var(--faint) !important}
.dark .text-slate-300{color:var(--faint) !important}
.dark .border-slate-200{border-color:var(--border) !important}
.dark .border-slate-100{border-color:var(--border) !important}
.dark .divide-slate-200 > *{border-color:var(--border) !important}
.dark .divide-slate-100 > *{border-color:var(--border) !important}
.dark .ring-slate-200{--tw-ring-color:var(--border) !important}
.dark .hover\:bg-slate-100:hover{background-color:var(--surface-hover) !important}
.dark .hover\:bg-slate-50:hover{background-color:var(--surface-hover) !important}
.dark .hover\:text-slate-900:hover{color:var(--ink) !important}
.dark .hover\:text-slate-700:hover{color:var(--text) !important}
.dark .hover\:text-blue-700:hover{color:var(--primary) !important}
.dark .hover\:text-blue-800:hover{color:var(--primary-hover) !important}
.dark .hover\:border-slate-400:hover{border-color:var(--border-strong) !important}
.dark .hover\:bg-red-50:hover{background-color:var(--red-light) !important}
.dark .hover\:bg-blue-50\/50:hover{background-color:rgba(99,102,241,.10) !important}
.dark .bg-blue-50{background-color:var(--primary-light) !important}
.dark .bg-green-50{background-color:var(--green-light) !important}
.dark .bg-amber-50{background-color:var(--amber-light) !important}
.dark .bg-red-50{background-color:var(--red-light) !important}
.dark .text-blue-700{color:var(--primary) !important}
.dark .text-blue-600{color:var(--primary) !important}
.dark .text-brand-blue{color:var(--primary) !important}
.dark .text-brand-green{color:var(--green) !important}
.dark .text-green-700{color:var(--green) !important}
.dark .text-green-600{color:var(--green) !important}
.dark .text-amber-700{color:var(--amber) !important}
.dark .text-amber-600{color:var(--amber) !important}
.dark .text-red-600{color:var(--red) !important}
.dark .text-red-700{color:var(--red) !important}
.dark .bg-navy-700{background-color:var(--sidebar-bg) !important}
.dark .text-white{color:var(--ink) !important}
.dark .border-white\/10{border-color:rgba(255,255,255,.06) !important}
.dark .hover\:bg-white\/10:hover{background-color:rgba(255,255,255,.06) !important}
.dark .hover\:bg-white\/5:hover{background-color:rgba(255,255,255,.03) !important}
.dark .hover\:bg-slate-50:hover{background-color:var(--surface-hover) !important}
/* Header sticky bar in dark mode — handles bg-white/95 and bg-white/100 */
.dark header[class*="shrink-0"]{background:rgba(15,23,42,.95) !important;border-bottom-color:var(--border) !important;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
.dark header[class*="shrink-0"] h1{color:var(--ink) !important}
.dark header[class*="shrink-0"] .text-slate-600{color:var(--muted) !important}
.dark header[class*="shrink-0"] .hover\:bg-slate-100:hover{background-color:var(--surface-hover) !important}
.dark header[class*="shrink-0"] .border-slate-200{border-color:var(--border) !important}
.dark header[class*="shrink-0"] kbd{background:var(--surface-2) !important;border-color:var(--border) !important;color:var(--faint) !important}
/* Admin-specific component overrides for dark mode */
.dark .admin-card{background:var(--surface);border-color:var(--border)}
.dark .admin-card-header{border-bottom-color:var(--border)}
.dark .admin-table thead th{background:var(--surface-2);color:var(--muted)}
.dark .admin-table tbody td{border-bottom-color:var(--border);color:var(--text)}
.dark .admin-table tbody tr:hover{background:var(--surface-hover)}
.dark .admin-table a{color:var(--ink)}
.dark .admin-table a:hover{color:var(--primary)}
.dark .admin-stat-card{background:linear-gradient(180deg,var(--surface) 0%,var(--surface-2) 100%);border-color:var(--border)}
.dark .admin-stat-card-value{color:var(--ink)}
.dark .admin-stat-card-label{color:var(--muted)}
.dark .admin-filter-bar{background:var(--surface);border-color:var(--border)}
.dark .input{background:var(--surface-2);border-color:var(--border);color:var(--ink)}
.dark .input::placeholder{color:var(--faint)}
.dark .input:focus{background:var(--surface);border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.16)}
.dark .btn-primary{background:var(--primary);box-shadow:0 1px 2px rgba(99,102,241,.30)}
.dark .btn-primary:hover{background:var(--primary-hover)}
.dark .btn-secondary{background:var(--surface-2);border-color:var(--border);color:var(--text)}
.dark .btn-secondary:hover{background:var(--surface-hover);border-color:var(--border-strong)}
.dark .admin-empty{color:var(--muted)}
.dark .admin-empty h3{color:var(--ink)}
.dark .admin-empty-ic{background:var(--surface-hover);color:var(--faint)}
.dark .admin-badge-ok{background:var(--green-light);color:var(--green)}
.dark .admin-badge-info{background:var(--primary-light);color:var(--primary)}
.dark .admin-badge-wait{background:var(--amber-light);color:var(--amber)}
.dark .admin-badge-off{background:var(--surface-hover);color:var(--muted)}
.dark .admin-badge-bad{background:var(--red-light);color:var(--red)}
/* Low stock progress bar */
.admin-stock-bar{height:6px;border-radius:3px;background:var(--surface-hover);overflow:hidden;flex:1;min-width:40px}
.admin-stock-bar-fill{height:100%;border-radius:3px;transition:width .4s var(--ease)}
.admin-stock-bar-fill.ok{background:var(--green)}
.admin-stock-bar-fill.low{background:var(--amber)}
.admin-stock-bar-fill.crit{background:var(--red)}
.admin-stock-bar-fill.zero{background:var(--faint)}

/* ============================================================
   REDUCED MOTION
   ============================================================ */
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation:none !important;transition:none !important}
  .admin-stat-card:hover,.btn:active{transform:none !important}
}
</style>

<script>
// Premium admin interactions — vanilla JS, no libraries.
// 1. Animated count-up for stat cards when they scroll into view.
// 2. Smooth hover micro-interactions are handled via CSS only.
(function () {
  'use strict';
  if (typeof document === 'undefined') return;

  // ---- Count-up animation -------------------------------------------------
  function formatNumber(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return Math.round(n).toString();
  }

  function animateCountUp(el) {
    if (el.dataset.counted === '1') return;
    el.dataset.counted = '1';
    var to = parseFloat(el.dataset.countTo);
    var original = el.dataset.countOriginal || '';
    if (!isFinite(to) || to < 0) { return; }
    var valueEl = el.querySelector('.admin-stat-card-value');
    if (!valueEl) return;
    // Extract currency / prefix / suffix from the original string so the
    // animated output preserves the same format (e.g. "৳1,299" → "৳1,299").
    var prefix = '';
    var suffix = '';
    var numPart = (original.match(/[\d,.\-]+/) || [''])[0];
    prefix = original.slice(0, original.indexOf(numPart));
    suffix = original.slice(original.indexOf(numPart) + numPart.length);
    var isMoney = original.indexOf('৳') !== -1 || original.indexOf('$') !== -1;
    var decimals = (numPart.split('.')[1] || '').length;
    el.classList.add('is-counting');
    var start = null;
    var duration = 1100;
    function step(ts) {
      if (start === null) start = ts;
      var progress = Math.min(1, (ts - start) / duration);
      // ease-out cubic
      var eased = 1 - Math.pow(1 - progress, 3);
      var current = to * eased;
      var displayNum;
      if (isMoney) {
        displayNum = current.toLocaleString('en-US', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        });
      } else {
        displayNum = decimals > 0
          ? current.toFixed(decimals)
          : Math.round(current).toLocaleString('en-US');
      }
      valueEl.textContent = prefix + displayNum + suffix;
      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        // Restore the original formatting at the end so it always matches
        // what the server rendered (avoids trailing-zero / rounding drift).
        valueEl.textContent = original;
        el.classList.remove('is-counting');
      }
    }
    requestAnimationFrame(step);
  }

  function init() {
    var els = document.querySelectorAll('.admin-stat-card.js-count-up');
    if (els.length === 0) return;
    if (!('IntersectionObserver' in window)) {
      // Old browsers: animate immediately.
      els.forEach(animateCountUp);
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateCountUp(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });
    els.forEach(function (el) { observer.observe(el); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
