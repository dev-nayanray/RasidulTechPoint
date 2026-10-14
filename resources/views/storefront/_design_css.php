:root{--navy:#01011D;--navy2:#060614;--blue:#2563EB;--blue-d:#1D4FD8;--green:#16A34A;--green-d:#15803D;--lime:#7AFB01;--lime-d:#5BCC01;--bg:#F1F5F9;--line:#D5DEE9;--muted:#475569;--tint:#E3ECFD;--mono:"IBM Plex Mono",ui-monospace,Menlo,monospace}
body{margin:0;background:#F1F5F9;color:#14243A;font-family:Inter,"Noto Sans Bengali",system-ui,sans-serif;-webkit-font-smoothing:antialiased;line-height:1.5}
*,*::before,*::after{box-sizing:border-box}
a{color:inherit;text-decoration:none}
button{font:inherit;color:inherit}
input{font:inherit}
:focus-visible{outline:3px solid #2563EB;outline-offset:2px}
h1,h2,h3,p{margin:0}
section[id]{scroll-margin-top:16px}
@media(min-width:961px){section[id]{scroll-margin-top:136px}}
.wrap{max-width:1200px;margin:0 auto;padding-inline:16px}
@media(min-width:720px){.wrap{padding-inline:24px}}
.ic{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:none}
.ic-fill{fill:currentColor}
.ic-sm{width:18px;height:18px}
.mono{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-weight:600;letter-spacing:.06em;text-transform:uppercase;font-size:11.5px}
.lp-shadow{fill:#14243A;opacity:.2}.lp-lid{fill:#0F1B2D}.lp-rim{fill:none;stroke:#3A5782;stroke-width:1}.lp-screen{fill:#1D3860}.lp-glow{fill:#2563EB}.lp-card{fill:#2A4B7D}.lp-line{fill:#40649A}.lp-ok{fill:#4ADE80}.lp-base{fill:#C3CEDB}.lp-notch{fill:#8FA0B5}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border-radius:12px;border:1px solid transparent;font-weight:700;font-size:15px;cursor:pointer;white-space:nowrap;text-align:center;transition:background .15s,border-color .15s}
.btn-primary{background:#2563EB;color:#fff;box-shadow:0 8px 18px rgba(37,99,235,.28)}
.btn-primary:hover{background:#1D4FD8}
.btn-ghost{background:#fff;color:#14243A;border-color:#BCC9D8}
.btn-ghost:hover{border-color:#14243A}
.btn-onnavy{background:transparent;color:#fff;border-color:rgba(255,255,255,.5)}
.btn-onnavy:hover{border-color:#fff}
.btn-light{background:#fff;color:#14243A}
.btn-light:hover{background:#E3ECFD}
.btn-sm{min-height:44px;padding:0 12px;font-size:14px;border-radius:10px}
.btn-dis{background:#E2E8F0;color:#475569;cursor:not-allowed}
.btn-block{width:100%}
.icon-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:12px;border:1px solid transparent;background:transparent;cursor:pointer;flex:none}
.icon-btn:hover{background:#EAF0F7}
.count{position:absolute;top:1px;right:-1px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:#2563EB;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.link-btn{background:none;border:0;color:#2563EB;font-weight:700;font-size:14px;min-height:44px;padding:0 8px;cursor:pointer}
.link-btn:hover{text-decoration:underline;text-underline-offset:3px}
.announce{background:#060614;color:#C9D5E4;font-size:13px}
.announce-in{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:2px 16px;padding-block:8px;text-align:center}
.announce a{color:#fff;font-weight:700;text-decoration:underline;text-underline-offset:3px}
.site-hdr{background:#fff;border-bottom:1px solid #D5DEE9;position:relative;z-index:40}
@media(min-width:961px){.site-hdr{position:sticky;top:0}}
.hdr{display:flex;flex-wrap:wrap;align-items:center;gap:10px 18px;padding-block:12px}
.burger{display:none}
.logo{display:flex;align-items:center;gap:10px;flex:none}
.logomark{width:42px;height:42px;border-radius:12px;background:#14243A;color:#fff;display:flex;align-items:center;justify-content:center;position:relative}
.logomark::after{content:"";position:absolute;right:-3px;bottom:-3px;width:12px;height:12px;border-radius:50%;background:#2563EB;border:2px solid #fff}
.rtp-mark{width:42px;height:42px;border-radius:12px;display:block;flex:none}
.rtp-logo--light .wm-a{color:#14243A}
.rtp-logo--light .wm-b{color:#2F7D00}
.rtp-logo--dark .rtp-mark{box-shadow:0 0 0 1.5px rgba(255,255,255,.28)}
.rtp-logo--dark .wm-a{color:#fff}
.rtp-logo--dark .wm-b{color:#7AFB01}
.wm-a{display:block;font-size:19px;font-weight:800;letter-spacing:-.02em;line-height:1.05}
.wm-b{display:block;font-size:10.5px;font-weight:700;letter-spacing:.22em;color:#2563EB;line-height:1.3}
.search{position:relative;flex:1 1 360px;min-width:0;order:2}
.search-box{display:flex;align-items:center;gap:4px;height:54px;background:#F1F5F9;border:1.5px solid transparent;border-radius:26px;padding:0 5px 0 18px}
.search-box:focus-within{border-color:#2563EB;background:#fff}
.search-box input{flex:1;min-width:0;height:100%;border:0;background:transparent;font-size:15px;color:#14243A}
.search-box input:focus{outline:none}
.search-go{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;background:#2563EB;color:#fff;flex:none}
.sugg{position:absolute;left:0;right:0;top:calc(100% + 8px);background:#fff;border:1px solid #C5D1DE;border-radius:16px;box-shadow:0 12px 32px rgba(20,36,58,.16);z-index:45;padding:6px;display:flex;flex-direction:column}
.sugg button{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:48px;padding:6px 12px;border:0;background:transparent;border-radius:10px;text-align:left;cursor:pointer;font-size:14px}
.sugg button:hover{background:#EEF3F9}
.sg-price{font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
.sg-note{padding:12px;color:#475569;font-size:14px}
.sugg a.sg-all{display:flex;align-items:center;min-height:44px;padding:0 12px;color:#2563EB;font-weight:700;font-size:14px}
.icons{order:3;display:flex;align-items:center;gap:2px;flex:none}
.nav-row{border-top:1px solid #E6ECF3}
.nav-desktop{display:flex;gap:4px}
.nav-desktop a{display:flex;align-items:center;min-height:46px;padding:0 14px;font-size:14.5px;font-weight:600;color:#334155;border-bottom:2px solid transparent}
.nav-desktop a:hover{color:#2563EB;border-bottom-color:#2563EB}
.nav-desktop .push{margin-left:auto;color:#475569;font-weight:500}
.nav-mobile{
  position:fixed;inset:0;top:auto;bottom:0;z-index:55;
  display:none;flex-direction:column;
  background:linear-gradient(180deg,#fff 0%,#F8FAFC 100%);
  overflow-y:auto;-webkit-overflow-scrolling:touch;
  padding:0 0 env(safe-area-inset-bottom,0px);
  animation:navSlideUp .25s cubic-bezier(.2,.7,.2,1);
}
@keyframes navSlideUp{from{transform:translateY(100%);opacity:.4}to{transform:translateY(0);opacity:1}}
.nav-mobile.open{display:flex}
.nav-mobile-head{
  position:sticky;top:0;z-index:2;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 18px;background:#fff;border-bottom:1px solid #E6ECF3;
  box-shadow:0 1px 0 rgba(15,23,42,.04);
}
.nav-mobile-head h2{font-size:17px;font-weight:800;letter-spacing:-.02em;color:#14243A;margin:0}
.nav-mobile-close{
  width:40px;height:40px;border-radius:10px;border:0;background:transparent;cursor:pointer;
  display:flex;align-items:center;justify-content:center;color:#64748B;
  transition:background .15s,color .15s;
}
.nav-mobile-close:hover{background:#F1F5F9;color:#14243A}
.nav-mobile-search{padding:14px 18px 6px}
.nav-mobile-search .search-box{height:48px;border-radius:12px}
.nav-mobile-section{padding:8px 0;border-bottom:1px solid #F1F5F9}
.nav-mobile-section:last-child{border-bottom:0}
.nav-mobile-section-title{
  padding:14px 18px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94A3B8;
}
.nav-mobile a{
  display:flex;align-items:center;gap:12px;min-height:50px;padding:0 18px;
  font-weight:600;font-size:15px;color:#1E293B;
  transition:background .12s;color .12s;
}
.nav-mobile a:hover{background:#F1F5F9;color:#2563EB}
.nav-mobile a svg{width:20px;height:20px;color:#64748B;flex:none;transition:color .12s}
.nav-mobile a:hover svg{color:#2563EB}
.nav-mobile-foot{
  margin-top:auto;padding:18px;background:#fff;border-top:1px solid #E6ECF3;
  display:flex;flex-direction:column;gap:8px;
}
.nav-mobile-foot a{font-size:13px;color:#64748B;font-weight:500;display:flex;align-items:center;gap:8px}
.nav-mobile-foot a:hover{color:#2563EB}
@media(max-width:960px){
.burger{display:inline-flex;order:0}
.logo{order:1}
.icons{order:2;margin-left:auto}
.search{order:3;flex:1 1 100%}
.nav-row{display:none}
.nav-mobile.open{display:flex}
}
@media(max-width:480px){.hide-xs{display:none}}
.hero-fb{background-color:#01011D;background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);background-size:36px 36px;color:#fff}
.hero-in{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:48px;align-items:center;padding-block:56px 112px}
@media(max-width:900px){.hero-in{grid-template-columns:1fr;gap:32px;padding-block:36px 96px}}
.stamp{display:inline-flex;align-items:center;gap:8px;min-height:34px;padding:0 14px 0 10px;border:1.5px solid #5F86C9;border-radius:8px;color:#BCD0F5;transform:rotate(-1.5deg)}
.hero-fb h1{font-size:clamp(40px,6.4vw,76px);line-height:.98;letter-spacing:-.04em;font-weight:800;text-wrap:balance}
.hero-fb h1 .hl{color:#7FA4F5}
.lead{font-size:18px;line-height:1.55;color:#D0DAE8;max-width:500px}
.hero-ctas{display:flex;flex-wrap:wrap;gap:12px}
.trust{display:flex;flex-wrap:wrap;gap:10px 22px;list-style:none;margin:0;padding:0}
.trust li{display:flex;align-items:center;gap:8px;font-size:14.5px;font-weight:600;color:#E8EEF7}
.trust .ic{color:#4ADE80}
.art{position:relative;background:#1E3556;border:1px solid #34527F;border-radius:28px;padding:36px 28px 28px;display:flex;flex-direction:column;gap:18px}
.art svg.laptop{width:100%;height:auto;max-height:300px}
.art .qc{position:absolute;top:16px;right:16px;display:inline-flex;align-items:center;gap:6px;min-height:30px;padding:0 12px;border-radius:15px;background:#15803D;color:#fff;font-size:12.5px;font-weight:700}
.tag{background:#fff;color:#14243A;border-radius:14px;padding:14px 16px;display:flex;flex-direction:column;gap:3px;max-width:340px;position:relative}
.tag .mono{color:#475569}
.tag b{font-size:18px;letter-spacing:-.01em}
.tag span.sp{font-size:14px;color:#334155}
.tag .price{font-size:22px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums;margin-top:4px}
.art-note{font-size:12.5px;color:#9FB2CB}
.finder{position:relative;z-index:5;margin-top:-64px;background:#fff;border:1px solid #D5DEE9;border-radius:22px;padding:22px;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) auto;gap:20px 28px;align-items:end;box-shadow:0 18px 44px rgba(20,36,58,.16)}
@media(max-width:1000px){.finder{grid-template-columns:1fr;margin-top:-56px}}
.flabel{display:block;margin-bottom:10px;color:#475569}
.fchips{display:flex;flex-wrap:wrap;gap:8px}
.fchip{min-height:44px;padding:0 15px;border-radius:22px;border:1.5px solid #C5D1DE;background:#fff;font-weight:600;font-size:14px;cursor:pointer;transition:border-color .15s}
.fchip:hover{border-color:#14243A}
.fchip.on{background:#14243A;color:#fff;border-color:#14243A}
.sec{padding-block:64px}
.sec-white{background:#fff}
.sec-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:28px}
.sec h2,.h2{font-size:clamp(28px,3.8vw,42px);line-height:1.08;letter-spacing:-.03em;font-weight:800;text-wrap:balance}
.sub{color:#475569;font-size:16px;max-width:540px;margin-top:10px}
.eyebrow{display:block;margin-bottom:12px;color:#2563EB}
.eyebrow::before{content:"";display:inline-block;width:24px;height:2px;background:currentColor;vertical-align:middle;margin-right:10px}
.bento{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.tile{position:relative;overflow:hidden;border-radius:20px;padding:22px;display:flex;flex-direction:column;justify-content:space-between;gap:28px;min-height:164px;border:1px solid #D5DEE9;background:#fff;cursor:pointer;text-align:left;transition:transform .15s}
.tile:hover{transform:translateY(-3px)}
.tile.big{grid-column:span 2;grid-row:span 2;min-height:340px}
.tile.wide{grid-column:span 2}
.tile.on{box-shadow:0 0 0 3px #fff,0 0 0 6px #2563EB}
.t-navy{background:#14243A;color:#fff;border-color:#14243A}
.t-ink{background:#0E1A2B;color:#fff;border-color:#0E1A2B}
.t-blue{background:#2563EB;color:#fff;border-color:#2563EB}
.t-tint{background:#E3ECFD;color:#14243A;border-color:#CFDCF7}
.t-mint{background:#DDF4E4;color:#14243A;border-color:#BFE6CB}
.t-white{background:#fff;color:#14243A}
.tile-name{position:relative;z-index:1;font-size:21px;font-weight:800;letter-spacing:-.02em;line-height:1.15;max-width:230px}
.tile.big .tile-name{font-size:clamp(28px,3.4vw,40px);max-width:300px}
.tile-go{position:relative;z-index:1;display:inline-flex;align-items:center;gap:6px;opacity:.9}
.tile-mark{position:absolute;right:-8px;bottom:-14px;width:112px;height:112px;opacity:.16;pointer-events:none;display:flex;align-items:center;justify-content:center;color:currentColor}
.tile.big .tile-mark{width:230px;height:230px;right:-14px;bottom:-24px}
.tile-mark svg{width:100%;height:100%;stroke-width:1.2}
.tile-mark b{font-size:92px;font-weight:800;letter-spacing:-.05em;line-height:1}
@media(max-width:900px){.bento{grid-template-columns:repeat(2,minmax(0,1fr))}.tile.big{grid-row:span 1;min-height:220px}}
@media(max-width:420px){.bento{grid-template-columns:1fr}.tile.big,.tile.wide{grid-column:span 1}}
.budget-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:14px}
.bcard{display:flex;flex-direction:column;gap:18px;background:#F1F5F9;border:1.5px solid #D5DEE9;border-radius:20px;padding:22px;cursor:pointer;width:100%;text-align:left;transition:border-color .15s}
.bcard:hover{border-color:#2563EB}
.bcard.on{background:#14243A;color:#fff;border-color:#14243A}
.bl{font-size:clamp(24px,2.6vw,30px);font-weight:800;letter-spacing:-.03em;font-variant-numeric:tabular-nums;line-height:1.1}
.rtrack{position:relative;display:block;height:10px;border-radius:5px;background:#CFD9E5}
.bcard.on .rtrack{background:#34527F}
.rseg{position:absolute;top:0;bottom:0;border-radius:5px;background:#2563EB}
.bcard.on .rseg{background:#7FA4F5}
.rscale{display:flex;justify-content:space-between;margin-top:8px;color:#4F6077;font-size:10.5px}
.bcard.on .rscale{color:#9DB8E8}
.bgo{display:flex;align-items:center;gap:6px;font-weight:700;font-size:14px;color:#2563EB}
.bcard.on .bgo{color:#fff}
.seg{display:inline-flex;background:#E3E9F1;border-radius:14px;padding:4px;gap:2px;flex-wrap:wrap}
.seg button{min-height:44px;padding:0 14px;border:0;border-radius:11px;background:transparent;font-weight:600;font-size:14px;cursor:pointer;color:#334155}
.seg button.on{background:#fff;color:#14243A;box-shadow:0 1px 3px rgba(20,36,58,.18)}
.pill{display:inline-flex;align-items:center;gap:6px;min-height:28px;padding:0 12px;border-radius:14px;font-size:12.5px;font-weight:600;background:#E8EEF5;color:#334155}
.pill-note{background:#FEF3C7;color:#78350F}
.chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:18px}
.chip{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 10px 0 16px;border-radius:22px;border:1.5px solid #14243A;background:#fff;font-size:14px;font-weight:600;cursor:pointer}
.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,262px),1fr));gap:18px}
.pcard{background:#fff;border:1px solid #D5DEE9;border-radius:20px;display:flex;flex-direction:column;overflow:hidden;min-width:0;transition:transform .15s,box-shadow .15s}
.pcard:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(20,36,58,.12)}
.pimg{position:relative;aspect-ratio:4/3;background-color:#E3EAF3;background-image:radial-gradient(circle at 50% 46%,rgba(255,255,255,.95),rgba(255,255,255,0) 58%),radial-gradient(#C3CFDD 1px,transparent 1.5px);background-size:auto,16px 16px;display:flex;align-items:center;justify-content:center}
.pimg svg.laptop{width:74%;height:auto}
.pimg .uid{position:absolute;left:14px;bottom:10px;color:#475569}
.pimg .wish{position:absolute;top:10px;right:10px;background:#fff;border:1px solid #D5DEE9;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#14243A}
.pimg .wish:hover{border-color:#2563EB;color:#2563EB}
.pimg .st{position:absolute;top:14px;left:14px}
.st-ok{display:inline-flex;align-items:center;gap:5px;min-height:28px;padding:0 10px;border-radius:14px;background:#15803D;color:#fff;font-size:12px;font-weight:700}
.st-wait{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:14px;background:#fff;color:#334155;border:1px solid #BCC9D8;font-size:12px;font-weight:600}
.pbody{padding:18px 18px 14px;display:flex;flex-direction:column;gap:12px}
.ptop{display:flex;justify-content:space-between;align-items:center;gap:8px}
.pbrand{color:#2563EB}
.grade{display:inline-flex;align-items:center;min-height:24px;padding:0 9px;border-radius:6px;background:#E3ECFD;color:#1E40AF;font-size:12px;font-weight:700}
.pmodel{font-size:19px;font-weight:800;line-height:1.2;letter-spacing:-.02em}
.specs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.spec{background:#F3F6FA;border-radius:10px;padding:8px 10px;display:flex;flex-direction:column;gap:1px;min-width:0}
.spec .sl{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:10px;font-weight:600;letter-spacing:.08em;color:#4F6077}
.spec .sv{font-size:13.5px;font-weight:600;overflow-wrap:anywhere;line-height:1.3}
.perf{position:relative;height:0;border-top:2px dashed #CBD5E1;margin:6px 0 0}
.perf::before,.perf::after{content:"";position:absolute;top:-12px;width:22px;height:22px;border-radius:50%;background:#F1F5F9;border:1px solid #D5DEE9}
.perf::before{left:-12px}
.perf::after{right:-12px}
.pfoot{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;padding:14px 18px 0}
.plabel{display:block;color:#64748B}
.pprice{display:block;font-size:26px;font-weight:800;letter-spacing:-.03em;font-variant-numeric:tabular-nums;line-height:1.1}
.avail{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700}
.avail i{width:8px;height:8px;border-radius:50%;background:currentColor;display:block}
.av-ok{color:#15803D}
.av-no{color:#B45309}
.pact{display:flex;gap:8px;padding:14px 18px 4px}
.pact .btn{flex:1;padding:0 8px}
.pcmp{padding:0 18px 12px}
.cmp{display:inline-flex;align-items:center;gap:8px;min-height:44px;background:none;border:0;color:#334155;font-weight:600;font-size:13.5px;cursor:pointer;padding:0}
.cmp .bx{width:20px;height:20px;border-radius:6px;border:2px solid #94A3B8;display:flex;align-items:center;justify-content:center;color:#fff}
.cmp.on .bx{background:#2563EB;border-color:#2563EB}
.cmp.on{color:#14243A}
.empty{background:#fff;border:1.5px dashed #A9B8CA;border-radius:20px;padding:44px 20px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px}
.why-in{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:48px;align-items:start}
@media(max-width:900px){.why-in{grid-template-columns:1fr;gap:28px}}
.why-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:560px){.why-grid{grid-template-columns:1fr}}
.why{display:flex;flex-direction:column;gap:12px;padding:20px;border:1px solid #D5DEE9;border-radius:18px;background:#F8FAFC}
.why-ico{width:46px;height:46px;border-radius:12px;background:#E3ECFD;color:#2563EB;display:flex;align-items:center;justify-content:center;flex:none}
.why h3{font-size:16.5px;font-weight:800;line-height:1.25;letter-spacing:-.01em}
.why p{font-size:14.5px;color:#475569;margin-top:4px}
.dark{background-color:#14243A;background-image:linear-gradient(rgba(255,255,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.045) 1px,transparent 1px);background-size:36px 36px;color:#fff}
.dark .sub{color:#C0CCDC}
.steps{position:relative;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:24px;margin-top:44px}
.steps::before{content:"";position:absolute;left:22px;right:22px;top:21px;border-top:2px dashed #5F86C9;z-index:0}
@media(max-width:1000px){.steps{grid-template-columns:repeat(2,minmax(0,1fr));gap:32px 24px}.steps::before{display:none}}
@media(max-width:560px){.steps{grid-template-columns:1fr}}
.stepc{position:relative;z-index:1;display:flex;flex-direction:column;gap:12px;padding-right:8px}
.step-n{width:44px;height:44px;border-radius:50%;background:#2563EB;color:#fff;font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-weight:600;font-size:17px;display:flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums;box-shadow:0 0 0 8px #14243A}
.stepc h3{font-size:18px;font-weight:800;letter-spacing:-.01em;line-height:1.25}
.stepc p{font-size:14.5px;color:#C0CCDC}
.record{margin-top:16px;border:1.5px dashed #5F86C9;border-radius:16px;padding:16px 20px;display:flex;flex-wrap:wrap;gap:8px 28px;align-items:center;color:#D5E0F0;font-size:14.5px}
.record b{color:#fff}
.brand-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 28px}
.brand-list{display:flex;flex-wrap:wrap;gap:8px}
.brand{display:inline-flex;align-items:center;justify-content:center;min-height:56px;min-width:110px;padding:0 22px;border:1.5px solid #D5DEE9;border-radius:14px;background:#fff;font-size:21px;font-weight:800;letter-spacing:-.03em;color:#64748B;cursor:pointer;transition:color .15s,border-color .15s}
.brand:hover{color:#14243A;border-color:#14243A}
.brand.on{background:#14243A;color:#fff;border-color:#14243A}
.deals{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:16px}
@media(max-width:820px){.deals{grid-template-columns:1fr}}
.deal-main{position:relative;overflow:hidden;background:#2563EB;color:#fff;border-radius:24px;padding:36px;display:flex;flex-direction:column;gap:14px;align-items:flex-start;justify-content:center;min-height:300px}
.deal-main h3{font-size:clamp(26px,3.2vw,36px);font-weight:800;letter-spacing:-.03em;line-height:1.08;max-width:340px;position:relative}
.deal-main p{color:#DCE7FF;max-width:340px;position:relative}
.deal-main .btn{position:relative}
.deal-art{position:absolute;right:-60px;bottom:-30px;width:320px;opacity:.9}
@media(max-width:560px){.deal-art{display:none}}
.slot{border:1.5px dashed #94A3B8;border-radius:24px;padding:32px;display:flex;flex-direction:column;gap:10px;justify-content:center;background:#fff}
.slot h3{font-size:19px;font-weight:800;letter-spacing:-.01em}
.slot p{font-size:15px;color:#475569}
.rv-in{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:36px;align-items:center}
@media(max-width:900px){.rv-in{grid-template-columns:1fr}}
.rv-ghosts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:14px}
.ghost{border:1.5px dashed #B3C0D0;border-radius:18px;padding:18px;display:flex;flex-direction:column;gap:10px;background:#F8FAFC}
.gbar{height:9px;border-radius:5px;background:#DCE4EE;display:block}
.gdot{width:34px;height:34px;border-radius:50%;background:#DCE4EE;display:block}
.faq-in{display:grid;grid-template-columns:minmax(0,.75fr) minmax(0,1.25fr);gap:48px;align-items:start}
@media(max-width:900px){.faq-in{grid-template-columns:1fr;gap:24px}}
.help{margin-top:24px;background:#fff;border:1px solid #D5DEE9;border-radius:18px;padding:20px;display:flex;flex-direction:column;gap:12px;align-items:flex-start}
.faq{background:#fff;border:1px solid #D5DEE9;border-radius:20px;padding:6px 24px}
.faq-item{border-bottom:1px solid #E6ECF3}
.faq-item:last-child{border-bottom:0}
.faq-q{display:flex;width:100%;align-items:center;justify-content:space-between;gap:16px;min-height:64px;padding:12px 0;background:none;border:0;text-align:left;font-size:16.5px;font-weight:700;cursor:pointer;letter-spacing:-.01em}
.faq-q .ic{color:#2563EB}
.faq-a{padding:0 0 20px;color:#475569;font-size:15.5px;max-width:640px}
.cta-panel{position:relative;overflow:hidden;border-radius:28px;padding:clamp(28px,5vw,56px);display:flex;flex-direction:column;gap:26px;align-items:flex-start;background:#01011D:26px;align-items:flex-start}
.cta-panel h2{max-width:560px;position:relative;font-size:clamp(30px,4.6vw,52px);line-height:1.04}
.cta-panel .hero-ctas{position:relative}
.cta-art{position:absolute;right:-40px;bottom:-26px;width:min(420px,46%);opacity:.95}
@media(max-width:700px){.cta-art{display:none}}
.foot{background:#01011D;color:#B7C4D6;padding-block:56px 28px;font-size:14.5px}
.foot-grid{display:grid;grid-template-columns:minmax(0,1.5fr) repeat(3,minmax(0,1fr));gap:32px}
@media(max-width:900px){.foot-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.foot-grid{grid-template-columns:1fr}}
.foot h4{margin:0 0 12px;color:#fff}
.foot ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}
.foot li a,.foot li button{display:flex;align-items:center;min-height:40px;color:#B7C4D6;background:none;border:0;padding:0;cursor:pointer;font-size:14.5px;text-align:left}
.foot li a:hover,.foot li button:hover{color:#fff}
.ph{color:#8FA3BC;font-style:italic}
.foot-bottom{margin-top:36px;padding-top:20px;border-top:1px solid #243449;display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px 24px;align-items:center}
.legal{display:flex;flex-wrap:wrap;gap:0 16px}
.legal button{background:none;border:0;color:#B7C4D6;cursor:pointer;min-height:44px;font-size:14px;padding:0}
.legal button:hover{color:#fff}
/* === Mobile bottom tabbar — app-style 5-tab nav === */
.tabbar{
  display:none;position:fixed;left:0;right:0;bottom:0;z-index:50;
  background:rgba(255,255,255,.98);backdrop-filter:saturate(180%) blur(10px);-webkit-backdrop-filter:saturate(180%) blur(10px);
  border-top:1px solid #E2E8F0;box-shadow:0 -4px 24px rgba(15,23,42,.06);
  padding-bottom:env(safe-area-inset-bottom,0px);
  grid-template-columns:repeat(5,minmax(0,1fr));
}
.tab{
  position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;
  min-height:58px;font-size:10.5px;font-weight:600;background:none;border:0;color:#64748B;cursor:pointer;
  transition:color .15s;
}
.tab .ic{width:22px;height:22px;transition:transform .15s,color .15s}
.tab:hover .ic{transform:translateY(-1px)}
.tab.is-active{color:#2563EB}
.tab.is-active .ic{color:#2563EB;transform:translateY(-1px)}
.tab.is-active::before{
  content:"";position:absolute;top:0;left:25%;right:25%;height:3px;border-radius:0 0 3px 3px;
  background:linear-gradient(90deg,#2563EB,#16A34A);
}
.tab .count{
  position:absolute;top:4px;right:calc(50% - 24px);
  min-width:18px;height:18px;padding:0 5px;border-radius:9px;
  background:#2563EB;color:#fff;font-size:10px;font-weight:700;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 2px 6px rgba(37,99,235,.35);
  border:2px solid #fff;
}
.tab .count.wish{background:#dc2626}
.tab-space{display:none;height:calc(72px + env(safe-area-inset-bottom,0px));background:transparent}
@media(max-width:960px){.tabbar{display:grid}.tab-space{display:block}}
/* Account tab special — uses avatar when logged in */
.tab-account-ic{
  width:24px;height:24px;border-radius:50%;background:#14243A;color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;
  border:1.5px solid transparent;transition:border-color .15s;
}
.tab.is-active .tab-account-ic{border-color:#2563EB}
.tray{position:fixed;left:50%;transform:translateX(-50%);bottom:18px;z-index:55;background:#14243A;color:#fff;border-radius:18px;padding:10px 12px 10px 20px;display:flex;align-items:center;gap:10px;box-shadow:0 14px 36px rgba(20,36,58,.4);width:max-content;max-width:calc(100% - 24px);font-size:14px;font-weight:600}
.tray .link-btn{color:#9DB8E8}
@media(max-width:960px){.tray{bottom:calc(80px + env(safe-area-inset-bottom,0px))}}
/* Compare bar should sit above the tabbar on mobile, not overlap it */
@media(max-width:960px){.cmp-bar{bottom:calc(72px + env(safe-area-inset-bottom,0px)) !important}}
.scrim{position:fixed;inset:0;background:rgba(20,36,58,.55);z-index:60;border:0;padding:0;width:100%;cursor:default}
.panel{position:fixed;top:0;right:0;bottom:0;width:min(460px,100%);background:#fff;z-index:61;overflow:auto;padding:22px;display:flex;flex-direction:column;gap:16px}
.panel.wide{width:min(880px,100%)}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.panel-head h2{font-size:24px;font-weight:800;letter-spacing:-.02em}
.line{display:flex;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #E6ECF3}
.line-thumb{width:68px;height:52px;background:#E3EAF3;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:none}
.line-thumb svg.laptop{width:76%;height:auto}
.line-info{flex:1;min-width:0}
.line-info b{display:block;font-size:14.5px;line-height:1.3}
.line-info span{font-size:13px;color:#475569}
.srow{display:grid;grid-template-columns:42% minmax(0,1fr);gap:12px;padding:9px 0;border-bottom:1px solid #E6ECF3;font-size:14.5px}
.sk{font-weight:600;color:#475569}
.sv2{font-weight:600}
.cmp-scroll{overflow-x:auto}
.cmp-inner{min-width:560px}
.crow{display:grid;gap:0 14px;padding:12px 0;border-bottom:1px solid #E6ECF3;font-size:14px;align-items:start}
.chead{font-size:15px;letter-spacing:-.01em}
.ck{font-weight:600;color:#475569}
.cv{font-weight:600}
.modal{position:fixed;z-index:61;left:50%;top:50%;transform:translate(-50%,-50%);width:min(440px,calc(100% - 32px));background:#fff;border-radius:20px;padding:26px;display:flex;flex-direction:column;gap:14px}
.modal h2{font-size:24px;font-weight:800;letter-spacing:-.02em}
.modal p{color:#475569}
.lp-keys{fill:none;stroke:#9FB0C4;stroke-width:5;stroke-dasharray:10 3}
.tn-black .lp-base{fill:#2B3647}
.tn-black .lp-notch{fill:#1B2433}
.tn-black .lp-keys{stroke:#55657C}
.tn-gray .lp-base{fill:#9AA8BA}
.tn-gray .lp-notch{fill:#7C8CA1}
.tn-gray .lp-keys{stroke:#6F8098}
.art::before{content:"";position:absolute;inset:0;border-radius:inherit;background:radial-gradient(circle at 50% 36%,rgba(66,115,214,.5),rgba(66,115,214,0) 62%);pointer-events:none;z-index:0}
.art svg.laptop,.art .tag,.art .art-note,.art-lap{position:relative;z-index:1}
.art-lap{display:block}
.chipf{position:absolute;z-index:2;display:inline-flex;align-items:center;gap:8px;min-height:36px;padding:0 14px;border-radius:18px;background:#fff;color:#14243A;font-size:13.5px;font-weight:700;white-space:nowrap;box-shadow:0 10px 24px rgba(8,16,30,.35)}
.chipf i{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-style:normal;font-size:10.5px;letter-spacing:.08em;color:#2563EB}
.cf1{left:0;top:12%}
.cf2{right:0;top:44%}
.cf3{left:3%;bottom:4%}
@keyframes rise{from{transform:translateY(16px)}to{transform:none}}
.art svg.laptop{animation:rise .7s cubic-bezier(.2,.7,.2,1)}
.chipf{animation:rise .6s .15s cubic-bezier(.2,.7,.2,1) backwards}
.toast{position:fixed;left:50%;transform:translateX(-50%);top:16px;z-index:70;display:flex;align-items:center;gap:10px;background:#14243A;color:#fff;border-radius:14px;padding:6px 8px 6px 16px;font-size:14.5px;font-weight:600;box-shadow:0 14px 36px rgba(20,36,58,.4);max-width:calc(100% - 24px)}
.toast .ic{color:#4ADE80}
.toast .link-btn{color:#9DB8E8;white-space:nowrap}
.foot-mark{margin-top:28px;font-size:clamp(36px,8.4vw,120px);font-weight:800;letter-spacing:-.05em;line-height:.9;color:#16273F;white-space:nowrap;overflow:hidden;user-select:none}
.why{transition:transform .15s,border-color .15s}
.why:hover{transform:translateY(-3px);border-color:#2563EB}
@media(prefers-reduced-motion:reduce){.tile,.pcard,.btn,.brand,.bcard,.why{transition:none}.tile:hover,.pcard:hover,.why:hover{transform:none}.art svg.laptop,.chipf{animation:none}}
/* === Extra component styles (product cards, grids) === */
.pcard{display:flex;flex-direction:column;background:#fff;border:1px solid #D5DEE9;border-radius:16px;overflow:hidden;transition:border-color .2s,box-shadow .2s}
.pcard:hover{border-color:#2563EB;box-shadow:0 8px 24px rgba(37,99,235,.08)}
.pcard-img{position:relative;aspect-ratio:4/3;overflow:hidden;display:block}
.pcard-body{padding:14px;display:flex;flex-direction:column;gap:8px;flex:1}
.pcard-brand{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#2563EB}
.pcard-name{font-size:15px;font-weight:700;color:#14243A;line-height:1.3}
.pcard-specs{display:grid;gap:2px;font-size:12px;margin:0}
.pcard-specs dt{color:#94A3B8;font-size:10px;text-transform:uppercase}
.pcard-specs dd{color:#475569;font-weight:500;margin:0}
.pcard-foot{display:flex;align-items:center;justify-content:space-between;gap:6px;padding-top:8px;border-top:1px solid #E6ECF3}
.price{font-size:18px;font-weight:800;color:#14243A}
.grade{font-size:11px;font-weight:700;padding:3px 8px;border-radius:8px}
.grade-a{background:rgba(22,163,74,.12);color:#16A34A}
.grade-b{background:rgba(37,99,235,.12);color:#2563EB}
.grade-c{background:rgba(245,158,11,.15);color:#92400E}
.pcard-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px}
.qc-badge{position:absolute;top:8px;left:8px;background:rgba(22,163,74,.12);color:#16A34A;font-size:11px;font-weight:700;padding:3px 8px;border-radius:8px;display:inline-flex;align-items:center;gap:3px;backdrop-filter:blur(4px)}
.low-badge{position:absolute;top:8px;right:8px;background:rgba(245,158,11,.15);color:#92400E;font-size:11px;font-weight:700;padding:3px 8px;border-radius:8px}
.oos-badge{position:absolute;top:8px;left:8px;background:rgba(100,116,139,.15);color:#475569;font-size:11px;font-weight:700;padding:3px 8px;border-radius:8px}
.why-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:14px}
.why{padding:20px;background:#fff;border:1px solid #D5DEE9;border-radius:16px;transition:transform .15s,border-color .15s}
.why-ic{width:40px;height:40px;border-radius:10px;background:#E3ECFD;color:#2563EB;display:flex;align-items:center;justify-content:center}
.why-title{font-size:15px;font-weight:700;color:#14243A;margin-top:12px}
.why-text{font-size:13px;color:#475569;margin-top:4px;line-height:1.5}
.steps-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:20px}
.step-card{position:relative;padding:24px 20px;background:#fff;border:1px solid rgba(255,255,255,.1);border-radius:16px}
.step-num{position:absolute;top:-14px;left:20px;width:36px;height:36px;border-radius:50%;background:#2563EB;color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;border:4px solid #F1F5F9}
.step-title{font-size:15px;font-weight:700;color:#14243A;margin-top:12px}
.step-text{font-size:13px;color:#475569;margin-top:6px;line-height:1.5}
.brand-logos{display:flex;flex-wrap:wrap;justify-content:center;gap:12px}
.brand{padding:16px 28px;background:#fff;border:1px solid #D5DEE9;border-radius:12px;font-size:18px;font-weight:800;color:#475569;transition:border-color .2s,color .2s;text-decoration:none}
.brand:hover{border-color:#2563EB;color:#2563EB}

/* === AJAX Search dropdown === */
.sugg{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:60;background:#fff;border:1px solid #D5DEE9;border-radius:14px;box-shadow:0 12px 36px rgba(1,1,29,.14);max-height:420px;overflow-y:auto;display:none}
.sugg.open{display:block}
.s-item{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;transition:background .12s;border-bottom:1px solid #F1F5F9}
.s-item:last-child{border-bottom:0}
.s-item:hover{background:#E3ECFD}
.s-thumb{width:36px;height:36px;border-radius:8px;background:#F1F5F9;display:flex;align-items:center;justify-content:center;flex:none;color:#94A3B8;overflow:hidden}
.s-thumb-img{padding:0;background:#E9EFF8}
.s-thumb-img img{width:100%;height:100%;object-fit:cover;display:block}
.s-info{flex:1;min-width:0}
.s-name{font-size:13.5px;font-weight:600;color:#14243A;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-meta{font-size:11px;color:#64748B;margin-top:1px}
.s-price{font-size:14px;font-weight:800;color:#14243A;white-space:nowrap}
.s-more{display:block;text-align:center;padding:10px;font-size:13px;font-weight:700;color:#2563EB;cursor:pointer}
.s-more:hover{background:#E3ECFD}
.s-empty{padding:24px;text-align:center;color:#64748B;font-size:14px}

/* === Account/cart/wishlist dropdown panels === */
.dd-panel{position:absolute;top:calc(100% + 6px);right:0;z-index:60;background:#fff;border:1px solid #D5DEE9;border-radius:14px;box-shadow:0 12px 36px rgba(1,1,29,.14);min-width:260px;display:none}
.dd-panel.open{display:block}
.dd-head{padding:14px 16px;border-bottom:1px solid #F1F5F9;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748B}
.dd-item{display:flex;align-items:center;gap:10px;padding:10px 16px;cursor:pointer;transition:background .12s}
.dd-item:hover{background:#E3ECFD}
.dd-item-name{font-size:13px;font-weight:600;color:#14243A}
.dd-item-meta{font-size:11px;color:#64748B}
.dd-item-price{font-size:13px;font-weight:700;color:#14243A;margin-left:auto}
.dd-foot{padding:12px 16px;border-top:1px solid #F1F5F9;display:flex;gap:8px}
.dd-link{flex:1;text-align:center;font-size:13px;font-weight:700;padding:8px;border-radius:8px}
.dd-link.primary{background:#2563EB;color:#fff}
.dd-link.ghost{border:1px solid #D5DEE9;color:#14243A}
.dd-empty{padding:24px 16px;text-align:center;color:#64748B;font-size:13px}

/* === Compare bar === */
.cmp-bar{position:fixed;bottom:0;left:0;right:0;z-index:80;background:#01011D;border-top:1px solid rgba(122,251,1,.2);padding:10px 16px;display:none;align-items:center;gap:12px;justify-content:center}
.cmp-bar.open{display:flex}
.cmp-items{display:flex;gap:8px;align-items:center}
.cmp-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:6px 10px;font-size:12px;color:#fff}
.cmp-chip button{background:none;border:0;color:#7AFB01;cursor:pointer;font-size:16px;line-height:1}
.cmp-btn{background:#7AFB01;color:#01011D;font-weight:800;font-size:13px;padding:8px 18px;border-radius:8px;border:0;cursor:pointer;white-space:nowrap}
.cmp-close{background:none;border:0;color:rgba(255,255,255,.5);cursor:pointer;font-size:20px}

/* === Customer dashboard premium === */
.dash-hero{background:linear-gradient(135deg,#01011D,#0A1628);border-radius:24px;padding:clamp(24px,4vw,40px);color:#fff;position:relative;overflow:hidden}
.dash-hero::before{content:"";position:absolute;right:-60px;bottom:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(122,251,1,.15),transparent 70%)}
.dash-greeting{font-size:clamp(22px,3.5vw,32px);font-weight:800;letter-spacing:-.02em}
.dash-greeting .name{color:#7AFB01}
.dash-sub{color:#94A3B8;font-size:14px;margin-top:6px}
.dash-quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
.dash-quick a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#fff;transition:background .15s}
.dash-quick a:hover{background:rgba(122,251,1,.12);border-color:rgba(122,251,1,.3)}
.dash-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,140px),1fr));gap:12px;margin-top:20px}
.dash-stat{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px}
.dash-stat-num{font-size:clamp(24px,4vw,36px);font-weight:800;letter-spacing:-.02em}
.dash-stat-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748B;margin-top:2px}

/* === (Old v1 hero slider CSS removed — v2 lives in hero/_slider_css.php) === */

/* ============================================================
   v2 POLISH — fixes + refinements for the marketing site
   (additive — overrides happen via specificity or !important
   where a v1 rule is broken/insufficient)
   ============================================================ */

/* Fix broken .cta-panel — the v1 rule has a stray ":26px" token that
   invalidates the background declaration. Use a real gradient + glow. */
.cta-panel{
  background:linear-gradient(135deg,#01011D 0%,#14243A 60%,#1E3A6A 100%) !important;
  background-image:
    radial-gradient(circle at 88% 18%,rgba(37,99,235,.32),transparent 55%),
    radial-gradient(circle at 12% 88%,rgba(22,163,74,.18),transparent 55%),
    linear-gradient(135deg,#01011D 0%,#14243A 60%,#1E3A6A 100%) !important;
  border:1px solid rgba(122,251,1,.15);
  box-shadow:0 24px 60px rgba(1,1,29,.32);
  position:relative;
}
.cta-panel::before{
  content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;
  background:radial-gradient(circle at 50% 0%,rgba(255,255,255,.06),transparent 50%);
}
.cta-panel > *{position:relative;z-index:1}
.cta-panel h2{color:#fff;text-shadow:0 2px 12px rgba(0,0,0,.3)}
.cta-panel p{color:#B7C4D6 !important}

/* Missing budget-card classes — the v1 CSS only defines .bcard.
   home.php uses .budget-card which is undefined. */
.budget-card{
  display:flex;flex-direction:column;justify-content:space-between;gap:14px;
  background:#fff;border:1.5px solid #D5DEE9;border-radius:18px;padding:22px 24px;
  cursor:pointer;text-align:left;min-height:140px;
  transition:transform .15s,border-color .15s,box-shadow .15s;
  position:relative;overflow:hidden;
}
.budget-card::before{
  content:"";position:absolute;left:0;top:0;bottom:0;width:4px;
  background:linear-gradient(to bottom,#2563EB,#16A34A);
  opacity:0;transition:opacity .2s;
}
.budget-card:hover{
  transform:translateY(-2px);border-color:#2563EB;
  box-shadow:0 12px 28px rgba(37,99,235,.12);
}
.budget-card:hover::before{opacity:1}
.budget-label{
  font-size:clamp(22px,2.6vw,30px);font-weight:800;letter-spacing:-.03em;
  color:#14243A;font-variant-numeric:tabular-nums;line-height:1.1;
}
.budget-range{
  font-size:13px;font-weight:600;color:#64748B;
  display:inline-flex;align-items:center;gap:6px;
}
.budget-range::before{
  content:"";width:6px;height:6px;border-radius:50%;background:#16A34A;
}

/* FAQ chevron should rotate when open — matches /faq and /contact behavior */
.faq-item[open] .faq-ic{transform:rotate(180deg)}
.faq-ic{transition:transform .25s ease}

/* Reviews skeleton — make the placeholder feel intentional, not stub-ish */
.rv-empty{
  background:#fff;border:1.5px dashed #B3C0D0;border-radius:24px;
  padding:48px 24px;text-align:center;display:flex;flex-direction:column;
  align-items:center;gap:14px;
}
.rv-empty-ic{
  width:56px;height:56px;border-radius:50%;background:#E3ECFD;color:#2563EB;
  display:flex;align-items:center;justify-content:center;
}
.rv-empty h3{font-size:20px;font-weight:800;color:#14243A;letter-spacing:-.01em}
.rv-empty p{color:#475569;font-size:14.5px;max-width:420px;line-height:1.55}
.rv-empty .btn{margin-top:8px}

/* Deals section — actually look like deals, not duplicate featured cards.
   Add a "deal" accent: top-right corner badge + accent border. */
.pcard.deal{border-color:rgba(37,99,235,.35);position:relative}
.pcard.deal::after{
  content:"DEAL";position:absolute;top:10px;right:10px;z-index:2;
  background:#2563EB;color:#fff;font-size:10px;font-weight:800;
  letter-spacing:.08em;padding:4px 8px;border-radius:6px;
  box-shadow:0 4px 12px rgba(37,99,235,.35);
}

/* Product card image — replace the ugly inline-style gradient placeholder
   with the proper .pimg dotted pattern, applied via .pcard-img > .pcard-img-ph */
.pcard-img-ph{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background-color:#E3EAF3;
  background-image:
    radial-gradient(circle at 50% 46%,rgba(255,255,255,.95),rgba(255,255,255,0) 58%),
    radial-gradient(#C3CFDD 1px,transparent 1.5px);
  background-size:auto,16px 16px;
  color:#94A3B8;
}
.pcard-img-ph svg{width:64px;height:64px}

/* Section heading polish — clearer eyebrow + tighter alignment */
.sec-head{align-items:flex-end}
.sec-head .eyebrow{font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.sec h2{position:relative}
.sec-head--center{justify-content:center;text-align:center}
.sec-head--center .sub{margin-left:auto;margin-right:auto}

/* Tile description — text color adapts to tile background (white vs tint vs dark) */
.tile-desc{position:relative;z-index:1;font-size:13px;color:#475569;line-height:1.4;max-width:200px}
.tile-desc--muted{color:#64748B}
.t-navy .tile-desc,.t-ink .tile-desc{color:#C0CCDC}
.t-tint .tile-desc,.t-mint .tile-desc{color:#475569}
.t-navy .tile-go,.t-ink .tile-go{color:#7AFB01}
.t-tint .tile-go,.t-mint .tile-go{color:#2563EB}
.t-navy .tile-mark,.t-ink .tile-mark{color:rgba(255,255,255,.18)}
.t-tint .tile-mark,.t-mint .tile-mark{color:rgba(20,36,58,.18)}

/* Why-choose cards — add a subtle top accent line on hover */
.why{position:relative;overflow:hidden}
.why::after{
  content:"";position:absolute;left:0;right:0;top:0;height:3px;
  background:linear-gradient(to right,#2563EB,#16A34A);
  transform:scaleX(0);transform-origin:left;transition:transform .3s ease;
}
.why:hover::after{transform:scaleX(1)}

/* Step cards — improve the dark section's card visibility + step number ring */
.dark .step-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1)}
.dark .step-card .step-num{box-shadow:0 0 0 6px #14243A,0 0 0 8px rgba(122,251,1,.35)}
.dark .step-title{color:#fff}
.dark .step-text{color:#C0CCDC}

/* Brand cards — add icon support + better hover */
.brand{position:relative;overflow:hidden;min-width:120px;min-height:64px}
.brand::after{
  content:"";position:absolute;left:0;right:0;bottom:0;height:2px;
  background:#2563EB;transform:scaleX(0);transform-origin:left;
  transition:transform .25s ease;
}
.brand:hover::after{transform:scaleX(1)}

/* Footer watermark on home.php — was missing (only on public.php).
   Use .foot-mark class (already defined) by rendering it in home.php. */
/* Footer brand column + logo + tagline (replace inline styles) */
.foot-brand{display:flex;flex-direction:column;gap:14px;align-items:flex-start}
.foot-logomark{background:#fff;padding:2px;overflow:hidden}
.foot-logo-img{width:100%;height:100%;object-fit:cover;border-radius:10px;display:block}
.foot .wm-a{color:#fff}
.foot .wm-b{color:#7AFB01}
.foot-tagline{max-width:300px;color:#B7C4D6;line-height:1.55}
.legal a{color:#B7C4D6 !important;background:none;border:0;min-height:40px;display:inline-flex;align-items:center;font-size:14px;cursor:pointer;text-decoration:none}
.legal a:hover{color:#fff}

/* Customer dashboard — extract inline styles from account/dashboard.php.
   Tokens for the recent-orders list + quick-link cards + status badges. */
.dash-section{display:flex;flex-direction:column;gap:18px;margin-top:24px}
.dash-section-title{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748B}
.dash-orders{background:#fff;border:1px solid #D5DEE9;border-radius:16px;overflow:hidden}
.dash-orders-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #E6ECF3}
.dash-orders-link{font-size:13px;font-weight:700;color:#2563EB;text-decoration:none}
.dash-orders-link:hover{text-decoration:underline;text-underline-offset:3px}
.dash-orders-empty{padding:24px;text-align:center;color:#64748B;font-size:14px}
.dash-order{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #E6ECF3;transition:background .12s;text-decoration:none;color:inherit;flex-wrap:wrap}
.dash-order:last-child{border-bottom:0}
.dash-order:hover{background:#F8FAFC}
.dash-order-ref{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:13px;font-weight:600;color:#14243A;min-width:120px}
.dash-order-date{font-size:12px;color:#64748B;margin-left:auto}
.dash-order-total{font-size:14px;font-weight:700;color:#14243A}
.dash-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.02em}
.dash-status-ok{background:rgba(22,163,74,.12);color:#16A34A}
.dash-status-wait{background:rgba(245,158,11,.15);color:#92400E}
.dash-status-info{background:rgba(37,99,235,.12);color:#2563EB}
.dash-status-off{background:rgba(100,116,139,.15);color:#475569}
.dash-num-lime{color:#7AFB01}
.dash-num-amber{color:#fbbf24}
.dash-num-green{color:#4ade80}
.dash-welcome{
  margin-top:20px;padding:36px 28px;text-align:center;
  display:flex;flex-direction:column;align-items:center;gap:12px;
  background:#fff;border:1.5px dashed #B3C0D0;border-radius:18px;
}
.dash-welcome svg{color:#2563EB}
.dash-welcome h3{font-size:22px;font-weight:800;color:#14243A;letter-spacing:-.02em}
.dash-welcome p{color:#475569;font-size:14.5px;max-width:420px;line-height:1.55}
.dash-quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:12px;margin-top:16px}
.dash-quick-card{
  display:flex;align-items:center;gap:14px;padding:16px 18px;
  background:#fff;border:1px solid #D5DEE9;border-radius:14px;
  text-decoration:none;color:#14243A;transition:border-color .15s,box-shadow .15s,transform .15s;
}
.dash-quick-card:hover{border-color:#2563EB;box-shadow:0 8px 20px rgba(37,99,235,.1);transform:translateY(-2px)}
.dash-quick-ic{
  width:42px;height:42px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;flex:none;
}
.dash-quick-text-block{display:flex;flex-direction:column;gap:2px}
.dash-quick-text{font-size:14px;font-weight:700;color:#14243A}
.dash-quick-sub{font-size:12px;color:#64748B}
.dash-ic-blue{background:rgba(37,99,235,.12);color:#2563EB}
.dash-ic-green{background:rgba(22,163,74,.12);color:#16A34A}
.dash-ic-lime{background:rgba(122,251,1,.12);color:#7AFB01}
.dash-ic-amber{background:rgba(251,191,36,.15);color:#fbbf24}

/* Form polish — unify .input class on storefront forms */
.input,.input:focus{outline:none}
.input{
  width:100%;min-height:44px;padding:10px 14px;
  background:#fff;border:1.5px solid #D5DEE9;border-radius:10px;
  font-size:14.5px;color:#14243A;transition:border-color .15s,box-shadow .15s;
}
.input::placeholder{color:#94A3B8}
.input:focus{border-color:#2563EB;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.input-error{border-color:#dc2626 !important;box-shadow:0 0 0 3px rgba(220,38,38,.12) !important}
.field-error{color:#dc2626;font-size:12px;margin-top:4px;display:block}

/* Empty-state unification — use .empty class consistently (already defined)
   with a recommended structure: .empty > svg + h3 + p + .btn */
.empty svg{color:#94A3B8}
.empty h3{font-size:18px;font-weight:800;color:#14243A;letter-spacing:-.01em}
.empty p{color:#475569;font-size:14.5px;max-width:380px;line-height:1.55}

/* Focus-visible polish — consistent accent outline on all interactive elements */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,.btn:focus-visible,.icon-btn:focus-visible,.tile:focus-visible,.brand:focus-visible,.budget-card:focus-visible,.tab:focus-visible{
  outline:3px solid #2563EB;outline-offset:2px;border-radius:6px;
}

/* Responsive section padding — tighter on mobile */
@media(max-width:720px){
  .sec{padding-block:44px}
  .sec-head{margin-bottom:20px}
  .sec h2{font-size:clamp(24px,5vw,32px)}
  .sub{font-size:14.5px;margin-top:6px}
  .cta-panel{padding:24px 20px}
  .cta-panel h2{font-size:clamp(26px,6vw,36px)}
}

/* Print + reduced motion hygiene */
@media(prefers-reduced-motion:reduce){
  .budget-card,.why,.brand,.pcard,.dash-quick-card,.step-card,.tile{transition:none !important;transform:none !important}
  .why::after,.brand::after{transition:none !important}
  .faq-ic{transition:none !important}
}

/* ============================================================
   v3 — HOME STOREFRONT REFRESH
   Premium product cards (Featured + Deals), category tiles with
   icon badges, and brand cards with live stock counts.
   (additive layer — appended last so it wins the cascade)
   ============================================================ */

/* ---- Featured product cards ---- */
.pgrid{gap:20px}
.pcard{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #D5DEE9;border-radius:18px;overflow:hidden;min-width:0;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
.pcard:hover{transform:translateY(-4px);border-color:#B9CDEB;box-shadow:0 18px 40px rgba(20,36,58,.13)}
.pcard-media{position:relative;display:block;aspect-ratio:4/3;overflow:hidden}
.pcard-img-el{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s ease}
.pcard:hover .pcard-img-el,.dcard:hover .pcard-img-el{transform:scale(1.04)}
.pcard-media-ph{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background-color:#E9EFF8;
  background-image:
    radial-gradient(circle at 50% 40%,rgba(255,255,255,.95),rgba(255,255,255,0) 52%),
    radial-gradient(circle at 85% 90%,rgba(37,99,235,.10),transparent 52%),
    radial-gradient(circle at 10% 92%,rgba(22,163,74,.09),transparent 46%),
    radial-gradient(#C6D2E0 1px,transparent 1.5px);
  background-size:auto,auto,auto,16px 16px;
  color:#93A7C0;
}
.pcard-media-ph svg{width:88px;height:88px;stroke-width:1.4;filter:drop-shadow(0 14px 16px rgba(20,36,58,.16));transition:transform .25s ease}
.pcard:hover .pcard-media-ph svg,.dcard:hover .pcard-media-ph svg{transform:translateY(-4px) scale(1.05)}
.pcard-badges{position:absolute;top:10px;left:10px;right:10px;display:flex;align-items:flex-start;justify-content:space-between;gap:6px;pointer-events:none}
.badge-qc,.badge-low,.badge-oos{display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.92);backdrop-filter:blur(4px);font-size:11px;font-weight:800;padding:4px 9px;border-radius:999px;box-shadow:0 2px 8px rgba(20,36,58,.10)}
.badge-qc{color:#15803D}
.badge-qc .ic{width:13px;height:13px}
.badge-low{color:#B45309}
.badge-oos{color:#475569}
.pcard-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:10px;flex:1}
.pcard-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.pcard-brand{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#1D4FD8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pcard-brand::before{content:"";flex:none;width:6px;height:6px;border-radius:2px;background:#2563EB}
.pcard-stock{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:#15803D;white-space:nowrap}
.pcard-stock i{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 0 3px rgba(22,163,74,.14)}
.pcard-stock--low{color:#B45309}
.pcard-stock--low i{box-shadow:0 0 0 3px rgba(245,158,11,.16)}
.pcard-name{font-size:16px;font-weight:800;letter-spacing:-.01em;line-height:1.25;color:#14243A;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;transition:color .15s}
.pcard-name:hover{color:#1D4FD8}
.spec-chips{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.spec-chip{display:flex;flex-direction:column;gap:2px;min-width:0;background:#F4F7FB;border:1px solid #E8EEF5;border-radius:10px;padding:7px 9px 6px}
.spec-chip i{font-style:normal;font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:9px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#7C8DA5}
.spec-chip b{font-size:12.5px;font-weight:700;color:#26364D;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pcard-foot{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:10px;padding-top:12px;border-top:1px solid #EAF0F6}
.pcard-price{margin:0;display:flex;flex-direction:column;gap:1px}
.pcard-price small{font-family:"IBM Plex Mono",ui-monospace,Menlo,monospace;font-size:9.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:#8494A9}
.pcard-price strong{font-size:21px;font-weight:800;letter-spacing:-.02em;color:#14243A;font-variant-numeric:tabular-nums;line-height:1.1}
.pcard-cta{white-space:nowrap}
.pcard-cta .ic{width:16px;height:16px;transition:transform .18s ease}
.pcard-cta:hover .ic{transform:translateX(3px)}

/* ---- Special Deals: horizontal deal cards ---- */
.deal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,500px),1fr));gap:20px}
.dcard{position:relative;display:grid;grid-template-columns:212px minmax(0,1fr);background:#fff;border:1px solid #D5DEE9;border-radius:18px;overflow:hidden;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
.dcard::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;z-index:2;background:linear-gradient(90deg,#2563EB 0%,#16A34A 100%)}
.dcard:hover{transform:translateY(-4px);border-color:#B9CDEB;box-shadow:0 18px 40px rgba(20,36,58,.13)}
.dcard-media{position:relative;display:block;overflow:hidden}
.dcard-media .pcard-media-ph{position:absolute;inset:0}
.dcard-pill{position:absolute;left:12px;bottom:12px;display:inline-flex;align-items:center;gap:5px;background:rgba(37,99,235,.92);color:#fff;font-size:11px;font-weight:800;letter-spacing:.04em;padding:5px 11px;border-radius:999px;backdrop-filter:blur(4px);box-shadow:0 6px 16px rgba(37,99,235,.35)}
.dcard-pill .ic{width:13px;height:13px}
.dcard-body{padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;min-width:0}
@media(max-width:560px){
  .dcard{grid-template-columns:1fr}
  .dcard-media{aspect-ratio:4/3}
}

/* ---- Category tiles: icon badge + bottom-anchored copy ---- */
.tile{display:flex;flex-direction:column;justify-content:flex-start;gap:0;min-height:158px;padding:20px;border-radius:18px;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
.tile:hover{transform:translateY(-4px);border-color:#9FB6DE;box-shadow:0 16px 34px rgba(20,36,58,.11)}
.t-navy:hover,.t-ink:hover{border-color:#34527F;box-shadow:0 16px 34px rgba(1,1,29,.45)}
.tile-ic{position:relative;z-index:1;width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;flex:none;margin-bottom:16px;transition:transform .2s ease}
.tile-ic .ic{width:22px;height:22px}
.tile:hover .tile-ic{transform:scale(1.08) rotate(-3deg)}
.t-ic-blue{background:#E3ECFD;color:#1D4FD8}
.t-ic-lime{background:rgba(122,251,1,.16);color:#7AFB01}
.t-ic-green{background:#DCF3E2;color:#15803D}
.t-ic-amber{background:#FDF0D7;color:#B45309}
.t-ic-violet{background:#ECE7FD;color:#6D28D9}
.t-ic-cyan{background:#DBF3F7;color:#0E7490}
.t-ic-rose{background:#FBE4EA;color:#BE185D}
.t-mint .tile-ic.t-ic-green,.t-tint .tile-ic.t-ic-violet{background:#fff}
.tile-copy{position:relative;z-index:1;margin-top:auto;display:flex;flex-direction:column;gap:4px}
.tile-name{font-size:17.5px}
.tile-go{font-size:10.5px}
.tile-go .ic{transition:transform .2s ease}
.tile:hover .tile-go .ic{transform:translateX(3px)}

/* ---- Brand cards: monogram + live stock count ---- */
.brand-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,225px),1fr));gap:14px}
.brand-card{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid #D5DEE9;border-radius:16px;position:relative;overflow:hidden;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
.brand-card:hover{transform:translateY(-3px);border-color:#2563EB;box-shadow:0 12px 28px rgba(37,99,235,.12)}
.brand-mono{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;flex:none;letter-spacing:-.02em}
.brand-logo{width:52px;height:40px;border-radius:10px;background:#fff;border:1px solid #E1E8F0;display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden;padding:4px}
.brand-logo img{max-width:100%;max-height:100%;object-fit:contain;display:block}
.brand-info{display:flex;flex-direction:column;gap:2px;min-width:0}
.brand-name{font-size:15px;font-weight:800;color:#14243A;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand-sub{font-size:12px;color:#64748B;font-weight:500}
.brand-arrow{width:18px;height:18px;margin-left:auto;color:#B3C0D0;flex:none;transition:color .16s ease,transform .16s ease}
.brand-card:hover .brand-arrow{color:#2563EB;transform:translateX(3px)}
.brand-cards .brand-card:nth-child(6n+1) .brand-mono{background:#E3ECFD;color:#1D4FD8}
.brand-cards .brand-card:nth-child(6n+2) .brand-mono{background:#DCF3E2;color:#15803D}
.brand-cards .brand-card:nth-child(6n+3) .brand-mono{background:#FDF0D7;color:#B45309}
.brand-cards .brand-card:nth-child(6n+4) .brand-mono{background:#ECE7FD;color:#6D28D9}
.brand-cards .brand-card:nth-child(6n+5) .brand-mono{background:#DBF3F7;color:#0E7490}
.brand-cards .brand-card:nth-child(6n+6) .brand-mono{background:#FBE4EA;color:#BE185D}

/* ---- Customer review cards (v4) ---- */
.rv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
.rv-card{margin:0;background:#fff;border:1px solid #D5DEE9;border-radius:18px;padding:22px;display:flex;flex-direction:column;gap:10px;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
.rv-card:hover{transform:translateY(-3px);border-color:#B9CDEB;box-shadow:0 12px 28px rgba(20,36,58,.10)}
.rv-stars{display:flex;gap:2px}
.rv-star{width:17px;height:17px;fill:#D7DFEA;stroke:none}
.rv-star--on{fill:#F5A623}
.rv-title{font-size:15.5px;font-weight:800;letter-spacing:-.01em;color:#14243A;line-height:1.3}
.rv-body{margin:0;font-size:14px;color:#475569;line-height:1.6}
.rv-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto;padding-top:10px;border-top:1px solid #EAF0F6}
.rv-who{font-size:13px;font-weight:700;color:#14243A}
.rv-demo-badge{display:inline-flex;align-items:center;min-height:22px;padding:0 9px;border-radius:999px;background:#FDF0D7;color:#92400E;font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;border:1px solid #F3D9A4}
.rv-product{font-size:11px;color:#2563EB;margin-top:2px}
.rv-product:hover{text-decoration:underline;text-underline-offset:3px}

/* ---- Header dropdown positioning (v4 fix) ----
   .dd-wrap must be the positioning context so every panel opens directly
   below ITS OWN icon; previously panels anchored to the sticky header and
   stacked up on its right edge. Panels right-align with their icon, so on
   mobile they extend leftwards into the viewport (never overflow). */
.dd-wrap{position:relative}
.dd-panel{right:0;min-width:min(280px,calc(100vw - 24px))}

/* ---- Product compare (v4: shop listings) ---- */
.compare-toggle{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:34px;padding:0 10px;border-radius:8px;border:1px solid #C9D5E2;background:#fff;color:#475569;font-size:12px;font-weight:700;cursor:pointer;transition:border-color .15s,color .15s,background .15s}
.compare-toggle:hover{border-color:#2563EB;color:#2563EB}
.compare-toggle[aria-pressed="true"]{background:#14243A;border-color:#14243A;color:#fff}
.cmp-modal{position:fixed;inset:0;z-index:75;background:rgba(20,36,58,.6);display:none;align-items:center;justify-content:center;padding:16px}
.cmp-modal.open{display:flex}
.cmp-modal-card{background:#fff;border-radius:20px;width:min(960px,100%);max-height:calc(100vh - 32px);overflow:auto;padding:22px 22px 26px;box-shadow:0 30px 70px rgba(1,1,29,.4)}
.cmp-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.cmp-modal-head h2{font-size:22px;font-weight:800;letter-spacing:-.02em;color:#14243A}
.cmp-modal .cmp-close{font-size:26px;line-height:1;background:none;border:0;color:#475569;cursor:pointer;min-width:40px;min-height:40px}
.cmp-modal .cmp-close:hover{color:#14243A}
.cmp-modal-note{margin:6px 0 16px;font-size:12.5px;color:#64748B}
.cmp-modal .cmp-scroll{overflow-x:auto}
.cmp-modal .cmp-inner{min-width:560px;border-collapse:collapse;width:100%}
.cmp-modal .cmp-inner th,.cmp-modal .cmp-inner td{padding:10px 12px;border-bottom:1px solid #E6ECF3;text-align:left;font-size:13.5px;vertical-align:top}
.cmp-modal .cmp-inner .ck{width:110px;color:#64748B;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.cmp-modal .cmp-inner .cv{font-weight:600;color:#26364D;min-width:130px}
.cmp-modal .chead{vertical-align:bottom;min-width:150px}
.cmp-modal .chead img{width:100%;max-width:150px;aspect-ratio:4/3;object-fit:cover;border-radius:10px;display:block;margin-bottom:8px;border:1px solid #E6ECF3}
.cmp-modal .chead .cmp-noimg{display:flex;align-items:center;justify-content:center;width:150px;aspect-ratio:4/3;border-radius:10px;background:#F1F5F9;color:#94A3B8;font-size:11px;font-weight:700;margin-bottom:8px}
.cmp-modal .chead b{display:block;font-size:13.5px;color:#14243A;line-height:1.3;margin-bottom:4px}
.cmp-modal .chead a{font-size:12px;color:#2563EB;font-weight:700}
.cmp-modal .chead a:hover{text-decoration:underline;text-underline-offset:3px}
.cmp-modal .cmp-price{color:#14243A;font-size:15px}
.cmp-modal .cmp-ok{color:#15803D;font-weight:700}
.cmp-modal .cmp-no{color:#B45309;font-weight:700}
.cmp-toast{position:fixed;left:50%;transform:translateX(-50%) translateY(8px);bottom:88px;z-index:80;background:#14243A;color:#fff;border-radius:12px;padding:10px 18px;font-size:13.5px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s}
.cmp-toast.open{opacity:1;transform:translateX(-50%) translateY(0)}
/* Bar sits above the mobile tab bar; toast above the bar */
@media(max-width:960px){.cmp-bar{bottom:72px}.cmp-toast{bottom:132px}}
@media(prefers-reduced-motion:reduce){
  .pcard,.dcard,.brand-card,.tile,.tile-ic,.pcard-cta .ic,.tile-go .ic,.brand-arrow,.pcard-media-ph svg,.rv-card{transition:none !important;transform:none !important}
}

