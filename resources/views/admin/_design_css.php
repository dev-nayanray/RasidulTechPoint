<?php
/**
 * Admin design system — premium tokens + components on top of Tailwind.
 *
 * Loaded by layouts/admin.php via inline <style> tag. Additive: enhances
 * the existing Tailwind utilities (btn-primary, btn-secondary, btn-danger,
 * .input) with brand-aligned admin chrome — sidebar polish, top bar
 * refinement, page headers, table styling, stat-card tones, empty states,
 * and reduced-motion safety.
 *
 * Brand palette (declared in tailwind.config.js):
 *   --navy:    #14243A   (sidebar bg, avatar, active pagination)
 *   --blue:    #2563EB   (primary actions, links, focus ring)
 *   --green:   #16A34A   (success / completed / available)
 *   --bg:      #F1F5F9   (page background)
 *   --ink:     #0F172A   (headings)
 *   --muted:   #64748B   (secondary text)
 *   --line:    #E2E8F0   (borders)
 */
?>
<style id="rtp-admin-css">
:root{
  --navy:#14243A;--navy-2:#1E3553;--navy-3:#2B4869;
  --blue:#2563EB;--blue-d:#1D4FD8;
  --green:#16A34A;--green-d:#15803D;
  --bg:#F1F5F9;
  --ink:#0F172A;--muted:#64748B;--line:#E2E8F0;
  --amber:#D97706;--red:#DC2626;
  --shadow-sm:0 1px 2px rgba(15,23,42,.04),0 2px 4px rgba(15,23,42,.04);
  --shadow-md:0 4px 12px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.06);
  --shadow-lg:0 12px 32px rgba(15,23,42,.10);
}

/* ============================================================
   PAGE BACKGROUND + SCROLLBAR
   ============================================================ */
body{font-feature-settings:"cv11","ss01";-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(15,23,42,.16);border-radius:5px;border:2px solid transparent;background-clip:padding-box}
::-webkit-scrollbar-thumb:hover{background:rgba(15,23,42,.28);background-clip:padding-box}

/* ============================================================
   SIDEBAR POLISH
   ============================================================ */
aside[data-sidebar]{
  background:linear-gradient(180deg,#14243A 0%,#0F1A2D 100%) !important;
  box-shadow:inset -1px 0 0 rgba(255,255,255,.04);
}
aside[data-sidebar] a[aria-current="page"],
aside[data-sidebar] a.bg-white\/10{
  background:linear-gradient(90deg,rgba(37,99,235,.18) 0%,rgba(37,99,235,.08) 100%) !important;
  color:#fff !important;
  position:relative;
  box-shadow:inset 3px 0 0 var(--blue);
}
aside[data-sidebar] a[aria-current="page"] svg,
aside[data-sidebar] a.bg-white\/10 svg{opacity:1 !important;color:#7FA4F5}
aside[data-sidebar] a:hover svg{opacity:1 !important}
aside[data-sidebar] p[data-sidebar-section]{letter-spacing:.08em}
/* Collapsed state — tooltips via title attribute, hide labels */
html.sidebar-collapsed aside[data-sidebar]{width:4.5rem !important}
html.sidebar-collapsed aside[data-sidebar] [data-sidebar-label],
html.sidebar-collapsed aside[data-sidebar] [data-sidebar-section]{display:none}
html.sidebar-collapsed aside[data-sidebar] a{justify-content:center;padding-inline:0}
html.sidebar-collapsed aside[data-sidebar] a svg{margin-inline:auto}

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
  border-radius:10px;background:#F8FAFC;border:1.5px solid transparent;transition:all .15s
}
header.sticky-top .input:focus{background:#fff;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.12)}

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
  background:#fff;border:1px solid var(--line);border-radius:14px;
  box-shadow:var(--shadow-sm);overflow:hidden;
}
.admin-card-header{
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 18px;border-bottom:1px solid #F1F5F9;
}
.admin-card-header h3{margin:0;font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.005em}
.admin-card-header a{font-size:13px;font-weight:600;color:var(--blue);text-decoration:none}
.admin-card-header a:hover{text-decoration:underline;text-underline-offset:2px}
.admin-card-body{padding:18px}

/* ============================================================
   ADMIN TABLES
   ============================================================ */
.admin-table{width:100%;border-collapse:collapse;font-size:14px}
.admin-table thead th{
  padding:10px 16px;text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;
  color:var(--muted);background:#F8FAFC;border-bottom:1px solid var(--line);white-space:nowrap;
}
.admin-table tbody td{padding:12px 16px;border-bottom:1px solid #F1F5F9;color:#1E293B;vertical-align:middle}
.admin-table tbody tr:last-child td{border-bottom:0}
.admin-table tbody tr{transition:background .12s}
.admin-table tbody tr:hover{background:#F8FAFC}
.admin-table a{color:var(--ink);font-weight:600;text-decoration:none}
.admin-table a:hover{color:var(--blue)}
.admin-table .num{text-align:right;font-variant-numeric:tabular-nums}
.admin-table .mono{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:13px}
/* Responsive wrapper to prevent horizontal overflow on small screens */
.admin-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}

/* ============================================================
   STAT CARD — semantic tones
   Use: tone="blue|green|amber|red|navy|slate"
   ============================================================ */
.admin-stat-card{
  background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;
  box-shadow:var(--shadow-sm);transition:border-color .15s,box-shadow .15s,transform .15s;
  position:relative;overflow:hidden;
}
.admin-stat-card:hover{border-color:rgba(37,99,235,.30);box-shadow:var(--shadow-md);transform:translateY(-1px)}
.admin-stat-card-label{font-size:13px;font-weight:600;color:var(--muted);letter-spacing:.01em;margin:0}
.admin-stat-card-value{font-size:28px;font-weight:800;letter-spacing:-.03em;color:var(--ink);
  font-variant-numeric:tabular-nums;margin:6px 0 0;line-height:1.1}
.admin-stat-card-hint{font-size:12px;color:#94A3B8;margin:4px 0 0}
.admin-stat-card-ic{
  width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:none;
  position:absolute;top:18px;right:18px;
}
.admin-stat-ic-blue{background:rgba(37,99,235,.10);color:var(--blue)}
.admin-stat-ic-green{background:rgba(22,163,74,.10);color:var(--green)}
.admin-stat-ic-amber{background:rgba(217,119,6,.12);color:var(--amber)}
.admin-stat-ic-red{background:rgba(220,38,38,.10);color:var(--red)}
.admin-stat-ic-navy{background:rgba(20,36,58,.10);color:var(--navy)}
.admin-stat-ic-slate{background:#F1F5F9;color:var(--muted)}
/* Top accent stripe — subtle color cue */
.admin-stat-card::before{
  content:"";position:absolute;left:0;right:0;top:0;height:2px;
  background:linear-gradient(90deg,var(--blue),var(--green));
  opacity:0;transition:opacity .2s;
}
.admin-stat-card:hover::before{opacity:1}

/* ============================================================
   FILTER BAR
   ============================================================ */
.admin-filter-bar{
  display:grid;gap:12px;background:#fff;border:1px solid var(--line);
  border-radius:14px;padding:14px;box-shadow:var(--shadow-sm);
}
.admin-filter-bar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

/* ============================================================
   EMPTY STATE — unified pattern
   ============================================================ */
.admin-empty{
  display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;
  padding:40px 20px;color:var(--muted);
}
.admin-empty-ic{
  width:56px;height:56px;border-radius:14px;background:#F1F5F9;color:#94A3B8;
  display:flex;align-items:center;justify-content:center;flex:none;
}
.admin-empty h3{margin:0;font-size:16px;font-weight:700;color:var(--ink);letter-spacing:-.01em}
.admin-empty p{margin:0;font-size:14px;color:var(--muted);max-width:400px;line-height:1.55}
.admin-empty .btn{margin-top:6px}

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
.admin-badge-info{background:rgba(37,99,235,.12);color:var(--blue);border-color:rgba(37,99,235,.20)}
.admin-badge-wait{background:rgba(217,119,6,.12);color:var(--amber);border-color:rgba(217,119,6,.22)}
.admin-badge-off{background:#F1F5F9;color:var(--muted);border-color:var(--line)}
.admin-badge-bad{background:rgba(220,38,38,.10);color:var(--red);border-color:rgba(220,38,38,.20)}

/* ============================================================
   BUTTON POLISH — enhance the existing .btn-primary / .btn-secondary
   ============================================================ */
.btn{transition:background .15s,border-color .15s,box-shadow .15s,transform .1s}
.btn-primary{box-shadow:0 1px 2px rgba(37,99,235,.20),0 4px 12px rgba(37,99,235,.18)}
.btn-primary:hover{box-shadow:0 2px 4px rgba(37,99,235,.25),0 6px 18px rgba(37,99,235,.25)}
.btn-primary:active{transform:translateY(1px)}
.btn-secondary:hover{box-shadow:var(--shadow-sm)}
.btn-danger{box-shadow:0 1px 2px rgba(220,38,38,.15)}
.btn-danger:hover{box-shadow:0 2px 4px rgba(220,38,38,.20)}
.btn:disabled,.btn[disabled]{opacity:.55;cursor:not-allowed;pointer-events:none}

/* ============================================================
   FORM INPUT POLISH
   ============================================================ */
.input{
  background:#fff;border:1.5px solid var(--line);border-radius:10px;
  font-size:14px;color:var(--ink);transition:border-color .15s,box-shadow .15s,background .15s;
}
.input::placeholder{color:#94A3B8}
.input:hover{border-color:#CBD5E1}
.input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.14);background:#fff}
.input[aria-invalid="true"]{border-color:var(--red) !important;box-shadow:0 0 0 3px rgba(220,38,38,.12) !important}

/* ============================================================
   FOCUS VISIBLE — consistent accent outline
   ============================================================ */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,
.btn:focus-visible,.icon-btn:focus-visible,[data-dropdown-toggle]:focus-visible{
  outline:2px solid var(--blue);outline-offset:2px;border-radius:8px;
}

/* ============================================================
   AVATAR — brand navy circle
   ============================================================ */
.avatar{
  display:inline-flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy-3) 100%);
  color:#fff;font-weight:700;letter-spacing:.02em;
  border-radius:50%;flex:none;
}

/* ============================================================
   RANGE TOGGLE (dashboard filter)
   ============================================================ */
.admin-range-toggle{
  display:inline-flex;gap:2px;padding:3px;background:#F1F5F9;border:1px solid var(--line);border-radius:10px;
}
.admin-range-toggle button{
  padding:6px 12px;border:0;background:transparent;color:var(--muted);font-size:13px;font-weight:600;
  border-radius:7px;cursor:pointer;transition:all .15s;
}
.admin-range-toggle button:hover{color:var(--ink)}
.admin-range-toggle button.active{
  background:#fff;color:var(--ink);box-shadow:var(--shadow-sm);
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
   REDUCED MOTION
   ============================================================ */
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation:none !important;transition:none !important}
  .admin-stat-card:hover,.btn:active{transform:none !important}
}
</style>
