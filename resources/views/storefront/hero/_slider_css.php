<?php
/**
 * Hero slider CSS (storefront home + admin preview).
 *
 * Design source: the approved reference (hero-slider.html). Per-slide palettes
 * are generated at runtime as .hs-t{i} classes by _slider.php from
 * HeroSlideService::cssVars(); this file only carries shared structure.
 *
 * Isolation rules (these fixed the "doesn't match my UI" bug):
 *  - Every class is prefixed hs- so the storefront stylesheet (.wrap, .btn,
 *    .btn-ghost, .chip, .ghost, .specs, .eyebrow ...) can no longer leak in.
 *  - Tokens (--bg, --ink, --line, --muted ...) live on .hs-hero, NOT :root, so
 *    they no longer override the site-wide variables of the same name.
 *  - Fonts are self-hosted from /assets/fonts. The site CSP is
 *    "style-src 'self' 'unsafe-inline'; font-src 'self'", which blocks the
 *    Google Fonts stylesheet and font files.
 *
 * The reference's own announcement bar and nav row are served by the site-wide
 * header that sits directly above the hero on the homepage.
 */
$fontBase = 'assets/fonts/';
?>
<style id="hero-slider-v2-css">
@font-face{font-family:"Bricolage Grotesque";font-style:normal;font-weight:200 800;font-display:swap;src:url("<?= e(url($fontBase . 'bricolage-grotesque-latin-var.woff2')) ?>") format("woff2")}
@font-face{font-family:"Figtree";font-style:normal;font-weight:300 900;font-display:swap;src:url("<?= e(url($fontBase . 'figtree-latin-var.woff2')) ?>") format("woff2")}
@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:400;font-display:swap;src:url("<?= e(url($fontBase . 'ibm-plex-mono-latin-400.woff2')) ?>") format("woff2")}
@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:500;font-display:swap;src:url("<?= e(url($fontBase . 'ibm-plex-mono-latin-500.woff2')) ?>") format("woff2")}

.hs-hero{
  --font-display:"Bricolage Grotesque","Segoe UI",system-ui,sans-serif;
  --font-body:"Figtree","Segoe UI",system-ui,sans-serif;
  --font-mono:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;
  --hero-ease:cubic-bezier(.2,.7,.2,1);
  --hero-dur:7000ms;
  --hero-gutter:clamp(16px,5vw,80px);
  /* Default tokens (mist teal). The root carries the active slide's .hs-t{i} class, which overrides these. */
  --bg:#dce6ec;--bg2:#f3f8fa;--ink:#0d1b26;--muted:#3b4d5b;--accent:#086560;--accent-ink:#ffffff;
  --line:rgba(13,27,38,.2);--chip:rgba(255,255,255,.62);--dot:rgba(13,27,38,.16);--ghost:rgba(13,27,38,.16);
  --chassis:#b9c4cc;--chassis-hi:#e4eaee;--bezel:#0a0d10;--glow:rgba(8,101,96,.28);--bar:rgba(13,27,38,.07);
}
.hs-hero{position:relative;width:100%;overflow:hidden;isolation:isolate;display:grid;grid-template-rows:1fr auto;min-height:clamp(600px,calc(100svh - 160px),800px);background:var(--bg);color:var(--ink);font-family:var(--font-body);line-height:1.5;-webkit-font-smoothing:antialiased;transition:background-color .8s var(--hero-ease),color .8s var(--hero-ease)}
.hs-hero *,.hs-hero *::before,.hs-hero *::after{box-sizing:border-box}
.hs-hero :where(a,button):focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.hs-hero a{color:inherit;text-decoration:none}
.hs-hero button{font-family:inherit}
.hs-hero .hs-wrap{width:100%;max-width:1440px;margin-inline:auto;padding-inline:var(--hero-gutter)}

/* backgrounds: one layer per slide, cross-faded */
.hs-hero .hs-bgs{position:absolute;inset:0;z-index:-1}
.hs-hero .hs-bg{position:absolute;inset:0;background:var(--bg);opacity:0;transition:opacity .9s var(--hero-ease)}
.hs-hero .hs-bg.is-active{opacity:1}
.hs-hero .hs-bg::before{content:"";position:absolute;inset:0;background:radial-gradient(55% 70% at 72% 48%,var(--bg2),transparent 72%)}
.hs-hero .hs-bg::after{content:"";position:absolute;inset:0;background-image:radial-gradient(var(--dot) 1.1px,transparent 1.3px);background-size:24px 24px;-webkit-mask-image:linear-gradient(to bottom,transparent,#000 35%,#000 65%,transparent);mask-image:linear-gradient(to bottom,transparent,#000 35%,#000 65%,transparent)}

/* slides: all stacked in one grid cell so the hero is as tall as the tallest slide */
.hs-hero .hs-slides{position:relative;display:grid;min-width:0}
.hs-hero .hs-slide{grid-area:1/1;display:grid;align-items:center;min-width:0;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .5s var(--hero-ease),visibility 0s .5s}
.hs-hero .hs-slide.is-active{opacity:1;visibility:visible;pointer-events:auto;z-index:1;transition-delay:0s,0s}
.hs-hero .hs-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.08fr);align-items:center;gap:clamp(24px,4vw,72px);padding-block:clamp(28px,4.5vw,64px) clamp(20px,3vw,40px)}

/* copy column */
.hs-hero .hs-copy{position:relative;z-index:2;display:flex;flex-direction:column;align-items:flex-start;gap:clamp(14px,1.7vw,22px);min-width:0}
.hs-hero .hs-eyebrow{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:0;font:500 12px/1 var(--font-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.hs-hero .hs-badge{padding:6px 10px;border-radius:4px;background:var(--accent);color:var(--accent-ink);font:500 11px/1 var(--font-mono);letter-spacing:.1em}
.hs-hero .hs-nw{white-space:nowrap}
.hs-hero h2.hs-head{margin:0;font:700 clamp(2.5rem,5.3vw,5rem)/.98 var(--font-display);font-variation-settings:"opsz" 96;letter-spacing:-.035em;text-wrap:balance;color:var(--ink)}
.hs-hero .hs-lede{margin:0;max-width:46ch;font-size:clamp(1rem,1.25vw,1.15rem);line-height:1.55;color:var(--muted)}
.hs-hero .hs-specs{display:flex;flex-wrap:wrap;gap:8px 22px;margin:0;padding:0;list-style:none;font:500 12px/1.3 var(--font-mono);letter-spacing:.04em}
.hs-hero .hs-specs li{display:flex;align-items:center;gap:8px}
.hs-hero .hs-specs li::before{content:"";flex:none;width:6px;height:6px;background:var(--accent)}
.hs-hero .hs-buy{display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px 28px;margin-top:2px}
.hs-hero .hs-price{font:700 clamp(2.2rem,3.4vw,3.2rem)/1 var(--font-display);letter-spacing:-.03em;font-variant-numeric:tabular-nums;color:var(--ink)}
.hs-hero .hs-was{margin-left:10px;font:400 14px var(--font-mono);color:var(--muted);text-decoration:line-through;font-variant-numeric:tabular-nums}
.hs-hero .hs-emi{margin:0;font-size:13px;line-height:1.45;color:var(--muted)}
.hs-hero .hs-emi b{color:var(--ink);font-variant-numeric:tabular-nums}
.hs-hero .hs-cta{display:flex;flex-wrap:wrap;gap:12px;margin-top:6px}
.hs-hero .hs-btn{display:inline-flex;align-items:center;gap:10px;min-height:50px;padding:0 24px;border:1.5px solid transparent;border-radius:8px;font:600 15px/1 var(--font-body);white-space:nowrap;cursor:pointer;transition:transform .25s var(--hero-ease),background-color .2s,color .2s,border-color .2s}
.hs-hero .hs-btn svg{transition:transform .25s var(--hero-ease)}
.hs-hero .hs-btn:hover{transform:translateY(-2px)}
.hs-hero .hs-btn:hover svg{transform:translateX(4px)}
.hs-hero .hs-btn-primary{background:var(--accent);color:var(--accent-ink)}
.hs-hero .hs-btn-ghost{background:transparent;border-color:var(--ink);color:var(--ink)}
.hs-hero .hs-btn-ghost:hover{background:var(--ink);color:var(--bg)}

/* visual column: pure-CSS laptop */
.hs-hero .hs-visual{position:relative;z-index:1;display:grid;place-items:center;min-width:0;padding-block:clamp(16px,3vw,36px) clamp(28px,4vw,52px)}
.hs-hero .hs-ghost{position:absolute;left:50%;top:46%;translate:-50% -50%;z-index:0;font:800 clamp(7rem,21vw,19rem)/.8 var(--font-display);letter-spacing:-.05em;text-transform:uppercase;white-space:nowrap;color:transparent;-webkit-text-stroke:1.5px var(--ghost);pointer-events:none;user-select:none}
.hs-hero .hs-glow{position:absolute;left:50%;top:48%;translate:-50% -50%;z-index:0;width:80%;aspect-ratio:1;max-width:100%;background:radial-gradient(closest-side,var(--glow),transparent);filter:blur(10px)}
.hs-hero .hs-stage{position:relative;z-index:1;width:min(100%,660px);container-type:inline-size}
.hs-hero .hs-laptop{position:relative;width:84%;margin-inline:auto;transform-style:preserve-3d;transform:perspective(1800px) rotateY(calc(-13deg + var(--ry,0deg))) rotateX(calc(3deg + var(--rx,0deg)));transition:transform .3s ease-out}
.hs-hero .hs-lid{position:relative;padding:1.6cqw 1.6cqw 1.9cqw;border-radius:2.2cqw 2.2cqw .6cqw .6cqw;background:var(--bezel);box-shadow:0 0 0 .35cqw var(--chassis),0 3cqw 6cqw rgba(0,0,0,.28)}
.hs-hero .hs-lid::before{content:"";position:absolute;top:.6cqw;left:50%;width:.6cqw;height:.6cqw;margin-left:-.3cqw;border-radius:50%;background:#1d2933}
.hs-hero .hs-screen{position:relative;aspect-ratio:16/10;border-radius:.7cqw;overflow:hidden;background:#000}
.hs-hero .hs-screen svg{display:block;width:100%;height:100%}
.hs-hero .hs-screen img{position:absolute;inset:0;width:100%;height:100%;max-width:none;object-fit:cover}
.hs-hero .hs-screen::after{content:"";position:absolute;inset:0;background:linear-gradient(115deg,rgba(255,255,255,.17),transparent 38%);pointer-events:none}
.hs-hero .hs-base{position:relative;width:112%;height:2.5cqw;margin:-.1cqw 0 0 -6%;border-radius:0 0 4cqw 4cqw/0 0 2.3cqw 2.3cqw;background:linear-gradient(var(--chassis-hi),var(--chassis));box-shadow:0 .4cqw 0 rgba(0,0,0,.18),0 2.6cqw 5cqw -1cqw var(--glow)}
.hs-hero .hs-base::before{content:"";position:absolute;left:50%;top:0;width:16%;height:55%;margin-left:-8%;border-radius:0 0 1cqw 1cqw;background:rgba(0,0,0,.22)}
.hs-hero .hs-floor{position:absolute;left:6%;right:6%;bottom:-3.4cqw;height:3cqw;background:radial-gradient(closest-side,rgba(0,0,0,.4),transparent);filter:blur(4px)}

/* floating glass chips */
.hs-hero .hs-chip{position:absolute;z-index:3;display:flex;flex-direction:column;gap:3px;padding:10px 14px;border:1px solid var(--line);border-radius:10px;background:var(--chip);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);font:400 10.5px/1.2 var(--font-mono);letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.hs-hero .hs-chip b{font:700 clamp(1.05rem,1.9vw,1.45rem)/1 var(--font-display);letter-spacing:-.02em;text-transform:none;color:var(--ink)}
.hs-hero .hs-c1{left:2%;top:-4%}
.hs-hero .hs-c2{right:0;top:28%}
.hs-hero .hs-c3{left:3%;bottom:2%}

/* bottom bar: progress indicators + controls */
.hs-hero .hs-bar{border-top:1px solid var(--line)}
.hs-hero .hs-bar .hs-wrap{display:flex;align-items:center;gap:clamp(12px,3vw,40px);padding-block:12px}
.hs-hero .hs-inds{display:flex;flex:1;min-width:0;gap:clamp(8px,2vw,24px);margin:0;padding:0;list-style:none}
.hs-hero .hs-ind{flex:1;min-width:0}
.hs-hero .hs-ind button{display:block;width:100%;min-height:44px;padding:0;border:0;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer}
.hs-hero .hs-track{position:relative;display:block;height:2px;margin-bottom:10px;background:var(--line);overflow:hidden}
.hs-hero .hs-fill{position:absolute;inset:0;background:var(--ink);transform:scaleX(0);transform-origin:left}
.hs-hero .hs-lab{display:flex;gap:10px;font:500 12px/1 var(--font-mono);letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;opacity:.55;transition:opacity .3s}
.hs-hero .hs-lab i{font-style:normal;font-variant-numeric:tabular-nums}
.hs-hero .hs-nm{overflow:hidden;text-overflow:ellipsis}
.hs-hero .hs-ind button:hover .hs-lab,.hs-hero .hs-ind.is-active .hs-lab{opacity:1}
.hs-hero .hs-ctrls{display:flex;gap:8px;flex:none}
.hs-hero .hs-round{display:grid;place-items:center;width:44px;height:44px;padding:0;border:1.5px solid var(--line);border-radius:50%;background:transparent;color:var(--ink);cursor:pointer;transition:background-color .2s,color .2s,border-color .2s}
.hs-hero .hs-round:hover{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.hs-hero .hs-round [hidden]{display:none}

/* motion */
@keyframes hsRise{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
@keyframes hsEnter{from{opacity:0;transform:translateX(calc(var(--dir,1)*9%)) scale(.95)}to{opacity:1;transform:none}}
@keyframes hsFade{from{opacity:0}to{opacity:1}}
@keyframes hsDrift{from{transform:translateY(-4px)}to{transform:translateY(5px)}}
.hs-hero .hs-slide.is-active .hs-rise{animation:hsRise .8s var(--hero-ease) both;animation-delay:calc(var(--d,0)*70ms + 120ms)}
.hs-hero .hs-slide.is-active .hs-stage{animation:hsEnter 1s var(--hero-ease) .1s both}
.hs-hero .hs-slide.is-active .hs-ghost{animation:hsFade 1.2s var(--hero-ease) .2s both}
.hs-hero .hs-slide.is-active .hs-chip{animation:hsRise .8s var(--hero-ease) calc(var(--d,5)*70ms + 300ms) both,hsDrift 6s ease-in-out 1.4s infinite alternate}
.hs-hero .hs-c2{--d:6}
.hs-hero .hs-c3{--d:7}

@media (max-width:900px){
  .hs-hero .hs-grid{grid-template-columns:minmax(0,1fr);gap:4px;padding-block:20px}
  .hs-hero .hs-visual{order:-1;padding-block:8px 28px}
  .hs-hero .hs-stage{width:min(100%,520px)}
  .hs-hero .hs-lede{max-width:56ch}
}
@media (max-width:700px){
  .hs-hero .hs-nm{display:none}
  .hs-hero .hs-round{width:40px;height:40px}
  .hs-hero .hs-ctrls{gap:6px}
}
@media (max-width:560px){
  .hs-hero .hs-c3{display:none}
  .hs-hero .hs-chip{padding:8px 10px;font-size:9.5px}
  .hs-hero .hs-btn{padding:0 20px}
}
/* Reduced motion: no movement or fades. Autoplay still runs (there is a visible pause button). */
@media (prefers-reduced-motion:reduce){
  .hs-hero *,.hs-hero *::before,.hs-hero *::after{animation:none!important;transition-duration:.01ms!important;transition-delay:0s!important}
}
</style>
