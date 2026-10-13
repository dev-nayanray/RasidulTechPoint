<?php
/**
 * Hero slider v2 CSS — matches the reference (hero-slider.html) as closely
 * as the existing design system allows. Per-slide palettes (the reference's
 * .t0..t3 classes) are generated at runtime as .hs-t{i} by _slider.php from
 * HeroSlideService::cssVars(); this file only carries the shared structure.
 * The reference's internal announcement bar and nav row are served by the
 * site-wide header that sits directly above the hero on the homepage.
 */
?>
<style id="hero-slider-v2-css">
:root{
  --font-display:"Bricolage Grotesque","Segoe UI",system-ui,sans-serif;
  --font-body:"Figtree","Segoe UI",system-ui,sans-serif;
  --font-mono:"IBM Plex Mono",ui-monospace,Menlo,Consolas,monospace;
  --hero-ease:cubic-bezier(.2,.7,.2,1);
  --hero-dur:7000ms;
  --hero-gutter:clamp(16px,5vw,80px);
  /* Default tokens (mist teal) — the hero root carries the active slide's .hs-t{i} class */
  --bg:#dce6ec;--bg2:#f3f8fa;--ink:#0d1b26;--muted:#3b4d5b;--accent:#086560;--accent-ink:#ffffff;
  --line:rgba(13,27,38,.2);--chip:rgba(255,255,255,.62);--dot:rgba(13,27,38,.16);--ghost:rgba(13,27,38,.16);
  --chassis:#b9c4cc;--chassis-hi:#e4eaee;--bezel:#0a0d10;--glow:rgba(8,101,96,.28);--bar:rgba(13,27,38,.07);
}
.hero{position:relative;width:100%;overflow:hidden;isolation:isolate;display:grid;grid-template-rows:auto 1fr auto;min-height:clamp(640px,90svh,820px);background:var(--bg);color:var(--ink);font-family:var(--font-body);-webkit-font-smoothing:antialiased;transition:background-color .8s var(--hero-ease),color .8s var(--hero-ease)}
.hero :where(a,button):focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.hero a{color:inherit;text-decoration:none}
.hero .wrap{width:100%;max-width:1440px;margin-inline:auto;padding-inline:var(--hero-gutter)}
.hero .bgs{position:absolute;inset:0;z-index:-1}
.hero .bg{position:absolute;inset:0;background:var(--bg);opacity:0;transition:opacity .9s var(--hero-ease)}
.hero .bg.is-active{opacity:1}
.hero .bg::before{content:"";position:absolute;inset:0;background:radial-gradient(55% 70% at 72% 48%,var(--bg2),transparent 72%)}
.hero .bg::after{content:"";position:absolute;inset:0;background-image:radial-gradient(var(--dot) 1.1px,transparent 1.3px);background-size:24px 24px;-webkit-mask-image:linear-gradient(to bottom,transparent,#000 35%,#000 65%,transparent);mask-image:linear-gradient(to bottom,transparent,#000 35%,#000 65%,transparent)}
.hero .slides{position:relative;display:grid}
.hero .slide{grid-area:1/1;display:grid;align-items:center;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .5s var(--hero-ease),visibility 0s .5s}
.hero .slide.is-active{opacity:1;visibility:visible;pointer-events:auto;z-index:1;transition-delay:0s,0s}
.hero .grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.08fr);align-items:center;gap:clamp(24px,4vw,72px);padding-block:clamp(12px,2.4vw,32px)}
.hero .copy{position:relative;z-index:2;display:flex;flex-direction:column;align-items:flex-start;gap:clamp(14px,1.7vw,22px);min-width:0}
.hero .eyebrow{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:0;font:500 12px/1 var(--font-mono);letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.hero .badge{padding:6px 10px;border-radius:4px;background:var(--accent);color:var(--accent-ink);font:500 11px/1 var(--font-mono);letter-spacing:.1em}
.hero h2.hs-head{margin:0;font:700 clamp(2.5rem,5.3vw,5rem)/.98 var(--font-display);font-variation-settings:"opsz" 96;letter-spacing:-.035em;text-wrap:balance}
.hero .lede{margin:0;max-width:46ch;font-size:clamp(1rem,1.25vw,1.15rem);line-height:1.55;color:var(--muted)}
.hero .specs{display:flex;flex-wrap:wrap;gap:8px 22px;margin:0;padding:0;list-style:none;font:500 12px/1.3 var(--font-mono);letter-spacing:.04em}
.hero .specs li{display:flex;align-items:center;gap:8px}
.hero .specs li::before{content:"";width:6px;height:6px;background:var(--accent)}
.hero .buy{display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px 28px;margin-top:2px}
.hero .price{font:700 clamp(2.2rem,3.4vw,3.2rem)/1 var(--font-display);letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.hero .was{margin-left:10px;font:400 14px var(--font-mono);color:var(--muted);text-decoration:line-through;font-variant-numeric:tabular-nums}
.hero .emi{margin:0;font-size:13px;line-height:1.45;color:var(--muted)}
.hero .emi b{color:var(--ink);font-variant-numeric:tabular-nums}
.hero .cta-row{display:flex;flex-wrap:wrap;gap:12px;margin-top:6px}
.hero .btn{display:inline-flex;align-items:center;gap:10px;min-height:50px;padding:0 24px;border:1.5px solid transparent;border-radius:8px;font:600 15px/1 var(--font-body);cursor:pointer;transition:transform .25s var(--hero-ease),background-color .2s,color .2s,border-color .2s}
.hero .btn svg{transition:transform .25s var(--hero-ease)}
.hero .btn:hover{transform:translateY(-2px)}
.hero .btn:hover svg{transform:translateX(4px)}
.hero .btn-primary{background:var(--accent);color:var(--accent-ink)}
.hero .btn-ghost{border-color:var(--ink);color:var(--ink)}
.hero .btn-ghost:hover{background:var(--ink);color:var(--bg)}

.hero .visual{position:relative;z-index:1;display:grid;place-items:center;min-width:0;padding-block:clamp(16px,3vw,36px) clamp(28px,4vw,52px)}
.hero .ghost{position:absolute;left:50%;top:46%;translate:-50% -50%;z-index:0;font:800 clamp(7rem,21vw,19rem)/.8 var(--font-display);letter-spacing:-.05em;text-transform:uppercase;white-space:nowrap;color:transparent;-webkit-text-stroke:1.5px var(--ghost);pointer-events:none;user-select:none}
.hero .glow{position:absolute;left:50%;top:48%;translate:-50% -50%;z-index:0;width:80%;aspect-ratio:1;max-width:100%;background:radial-gradient(closest-side,var(--glow),transparent);filter:blur(10px)}
.hero .stage{position:relative;z-index:1;width:min(100%,660px);container-type:inline-size}
.hero .laptop{position:relative;width:84%;margin-inline:auto;transform-style:preserve-3d;transform:perspective(1800px) rotateY(calc(-13deg + var(--ry,0deg))) rotateX(calc(3deg + var(--rx,0deg)));transition:transform .3s ease-out}
.hero .lid{position:relative;padding:1.6cqw 1.6cqw 1.9cqw;border-radius:2.2cqw 2.2cqw .6cqw .6cqw;background:var(--bezel);box-shadow:0 0 0 .35cqw var(--chassis),0 3cqw 6cqw rgba(0,0,0,.28)}
.hero .lid::before{content:"";position:absolute;top:.6cqw;left:50%;width:.6cqw;height:.6cqw;margin-left:-.3cqw;border-radius:50%;background:#1d2933}
.hero .screen{position:relative;aspect-ratio:16/10;border-radius:.7cqw;overflow:hidden;background:#000}
.hero .screen svg{display:block;width:100%;height:100%}
.hero .screen img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.hero .screen::after{content:"";position:absolute;inset:0;background:linear-gradient(115deg,rgba(255,255,255,.17),transparent 38%);pointer-events:none}
.hero .base{position:relative;width:112%;height:2.5cqw;margin:-.1cqw 0 0 -6%;border-radius:0 0 4cqw 4cqw/0 0 2.3cqw 2.3cqw;background:linear-gradient(var(--chassis-hi),var(--chassis));box-shadow:0 .4cqw 0 rgba(0,0,0,.18),0 2.6cqw 5cqw -1cqw var(--glow)}
.hero .base::before{content:"";position:absolute;left:50%;top:0;width:16%;height:55%;margin-left:-8%;border-radius:0 0 1cqw 1cqw;background:rgba(0,0,0,.22)}
.hero .floor{position:absolute;left:6%;right:6%;bottom:-3.4cqw;height:3cqw;background:radial-gradient(closest-side,rgba(0,0,0,.4),transparent);filter:blur(4px)}

.hero .chip{position:absolute;z-index:3;display:flex;flex-direction:column;gap:3px;padding:10px 14px;border:1px solid var(--line);border-radius:10px;background:var(--chip);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);font:400 10.5px/1.2 var(--font-mono);letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
.hero .chip b{font:700 clamp(1.05rem,1.9vw,1.45rem)/1 var(--font-display);letter-spacing:-.02em;text-transform:none;color:var(--ink)}
.hero .c1{left:2%;top:-4%}
.hero .c2{right:0;top:28%}
.hero .c3{left:3%;bottom:2%}

.hero .bar{border-top:1px solid var(--line)}
.hero .bar .wrap{display:flex;align-items:center;gap:clamp(12px,3vw,40px);padding-block:12px}
.hero .inds{display:flex;flex:1;min-width:0;gap:clamp(8px,2vw,24px);margin:0;padding:0;list-style:none}
.hero .ind{flex:1;min-width:0}
.hero .ind button{display:block;width:100%;min-height:44px;padding:0;border:0;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer}
.hero .track{position:relative;display:block;height:2px;margin-bottom:10px;background:var(--line);overflow:hidden}
.hero .fill{position:absolute;inset:0;background:var(--ink);transform:scaleX(0);transform-origin:left}
.hero .lab{display:flex;gap:10px;font:500 12px/1 var(--font-mono);letter-spacing:.1em;text-transform:uppercase;opacity:.55;transition:opacity .3s}
.hero .lab i{font-style:normal;font-variant-numeric:tabular-nums}
.hero .ind button:hover .lab,.hero .ind.is-active .lab{opacity:1}
/* Progress bar fill is driven by JS (rAF) for reliable restart across slides */
.hero .ind.is-active .fill{transform:scaleX(var(--fill-pct,0))}
.hero.is-paused .ind.is-active .fill{transition:none}
.hero .ctrls{display:flex;gap:8px}
.hero .round{display:grid;place-items:center;width:44px;height:44px;border:1.5px solid var(--line);border-radius:50%;background:transparent;color:var(--ink);cursor:pointer;transition:background-color .2s,color .2s,border-color .2s}
.hero .round:hover{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.hero .round [hidden]{display:none}

/* Motion */
@keyframes heroRise{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}
@keyframes heroEnter{from{opacity:0;transform:translateX(calc(var(--dir,1)*9%)) scale(.95)}to{opacity:1;transform:none}}
@keyframes heroFade{from{opacity:0}to{opacity:1}}
@keyframes heroDrift{from{transform:translateY(-4px)}to{transform:translateY(5px)}}
.hero .slide.is-active .rise{animation:heroRise .8s var(--hero-ease) both;animation-delay:calc(var(--d,0)*70ms + 120ms)}
.hero .slide.is-active .stage{animation:heroEnter 1s var(--hero-ease) .1s both}
.hero .slide.is-active .ghost{animation:heroFade 1.2s var(--hero-ease) .2s both}
.hero .slide.is-active .chip{animation:heroRise .8s var(--hero-ease) calc(var(--d,5)*70ms + 300ms) both,heroDrift 6s ease-in-out 1.4s infinite alternate}
.hero .c2{--d:6}.hero .c3{--d:7}

@media (max-width:900px){
  .hero .grid{grid-template-columns:minmax(0,1fr);gap:4px}
  .hero .visual{order:-1;padding-block:8px 28px}
  .hero .stage{width:min(100%,520px)}
  .hero .lede{max-width:56ch}
}
@media (max-width:700px){
  .hero .nm{display:none}
  .hero .round{width:40px;height:40px}
  .hero .ctrls{gap:6px}
}
@media (max-width:560px){
  .hero .c3{display:none}
  .hero .chip{padding:8px 10px;font-size:9.5px}
  .hero .btn{padding:0 20px}
}
@media (prefers-reduced-motion:reduce){
  .hero *,.hero *::before,.hero *::after{animation:none!important;transition-duration:.01ms!important;transition-delay:0s!important}
  .hero .ind.is-active .fill{transform:scaleX(1)}
}</style>
