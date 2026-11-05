/* ---------- Hero ---------- */
.hero{background:var(--night);color:#fff;position:relative;overflow:hidden;padding:72px 0 0}
.hero:after{content:"";position:absolute;width:900px;height:900px;right:-260px;top:-280px;background:radial-gradient(closest-side,rgba(255,197,49,.22),transparent 70%);pointer-events:none}
.hero .wrap{position:relative;z-index:1}
.hero-top{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:40px;align-items:end}
.hero h1{font-size:clamp(44px,7.4vw,96px);font-weight:800;line-height:.96;letter-spacing:-.018em;max-width:13ch}
.hero h1 em{font-style:normal;color:var(--gold)}
.hero-side{max-width:380px;padding-bottom:10px}
.hero-side p{color:#C9D3EA;font-size:18px;margin:0 0 24px}
.hero-cta{display:flex;gap:12px;flex-wrap:wrap}
.platforms{display:flex;flex-wrap:wrap;gap:8px 22px;margin:38px 0 0;padding:0;list-style:none;color:#AEB9D3;font-weight:600;font-size:14.5px}
.platforms li{display:flex;align-items:center;gap:8px}
.platforms svg{color:#fff;opacity:.9}
.platforms li{animation:platIn .6s cubic-bezier(.2,.8,.2,1) both;animation-delay:calc(.9s + var(--i) * .09s)}
.platforms li span{transition:color .3s}
.platforms li:before{content:"";position:absolute}
@keyframes platIn{from{opacity:0;transform:translateY(12px)}}
.platforms li.lit{color:var(--gold)}
.platforms li.lit svg{color:var(--gold);transform:scale(1.15)}
.platforms svg{transition:transform .3s,color .3s}
.stage{position:relative;margin-top:56px;height:min(56vw,640px);overflow:hidden;margin-left:-24px;margin-right:-24px;padding:0 24px}
.screen{position:absolute;left:4%;right:9%;top:0;border-radius:16px 16px 0 0;overflow:hidden;background:#fff;box-shadow:0 -10px 80px -20px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.08);animation:rise 1s cubic-bezier(.2,.8,.2,1) both .1s}
.screen .bar{height:34px;background:#E9EDF5;display:flex;align-items:center;gap:7px;padding:0 14px}
.screen .bar i{width:11px;height:11px;border-radius:50%;background:#C9D0DE}
.screen .bar span{margin-left:14px;flex:0 1 360px;height:20px;border-radius:6px;background:#fff;font:500 11.5px/20px "Instrument Sans",sans-serif;color:var(--mute);padding:0 10px;overflow:hidden;white-space:nowrap}
.phone{position:absolute;right:2%;top:14%;width:min(23%,250px);aspect-ratio:390/844;border-radius:36px;background:#000;padding:9px;box-shadow:0 30px 80px -20px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.12);animation:rise 1s cubic-bezier(.2,.8,.2,1) both .45s}
.phone img{width:100%;height:100%;object-fit:cover;object-position:top;border-radius:28px}
.chip-live{position:absolute;left:0;top:30%;background:#fff;color:var(--ink);border-radius:14px;padding:12px 16px;box-shadow:var(--shadow);font-size:13.5px;font-weight:600;display:flex;gap:10px;align-items:center;animation:rise 1s cubic-bezier(.2,.8,.2,1) both .8s}
.chip-live b{display:block;font-size:15px}
.chip-live .dot{width:10px;height:10px;border-radius:50%;background:var(--green);box-shadow:0 0 0 5px rgba(18,183,106,.18)}
@keyframes rise{from{opacity:0;transform:translateY(40px)}}
@media (max-width:900px){.hero-top{grid-template-columns:1fr}.hero-side{max-width:none}.phone{width:30%}.chip-live{display:none}.stage{height:62vw}}
@media (max-width:560px){.screen{left:0;right:14%}.phone{width:38%;right:0;top:22%;border-radius:24px;padding:6px}.phone img{border-radius:19px}}

/* Apparition au défilement (désactivée si l'utilisateur limite les animations) */
.rv{opacity:0;transform:translateY(26px);transition:opacity .7s cubic-bezier(.2,.8,.2,1),transform .7s cubic-bezier(.2,.8,.2,1);transition-delay:var(--d,0s)}
.rv.in{opacity:1;transform:none}
@media (prefers-reduced-motion:reduce){.rv{opacity:1;transform:none}}
.viewer img{transform:scale(1.015);transition:opacity .45s,transform .6s}
.viewer img.on{transform:none}
.dl-list li{transition:background .2s}
.dl-list li:hover .ic{transform:translateY(-3px) rotate(-6deg)}
.dl-list .ic{transition:transform .25s,background .25s}
.mod li{transition:transform .15s,border-color .15s}
.mod li:hover{transform:translateY(-2px);border-color:var(--ink)}
/* ---------- Bandeau de preuves ---------- */
.proof{background:#fff;border-bottom:1px solid var(--line)}
.proof .wrap{display:grid;grid-template-columns:repeat(4,1fr)}
.proof .wrap>div{padding:30px 20px 30px 0}
.proof .wrap>div+div{padding-left:24px;border-left:1px solid var(--line)}
.proof b{display:block;font:800 34px/1 var(--display);letter-spacing:-.014em}
.proof span{color:var(--mute);font-size:14.5px}
@media (max-width:760px){.proof .wrap{grid-template-columns:1fr 1fr}.proof .wrap>div{padding:22px 12px 22px 0}.proof .wrap>div:nth-child(even){padding-left:16px}.proof .wrap>div:nth-child(3){border-left:0}}

/* ---------- Sections ---------- */
.sec{padding:110px 0}
.sec-head{max-width:720px;margin-bottom:52px}
.sec-head h2{font-size:clamp(34px,4.6vw,58px);font-weight:800;letter-spacing:-.014em}
.sec-head p{color:var(--ink2);font-size:18.5px;margin:18px 0 0;max-width:60ch}

/* ---------- Visite du logiciel ---------- */
.tour{display:grid;grid-template-columns:330px minmax(0,1fr);gap:40px;align-items:start}
.tabs{display:flex;flex-direction:column;gap:4px;position:sticky;top:96px}
.tab{appearance:none;border:0;background:transparent;text-align:left;padding:16px 18px;border-radius:14px;cursor:pointer;font:inherit;color:var(--ink2);transition:background .2s,color .2s}
.tab b{display:block;font:700 19px/1.2 var(--display);color:var(--ink);letter-spacing:-.01em}
.tab span{display:block;font-size:14.5px;margin-top:4px}
.tab:hover{background:#E9EDF5}
.tab[aria-selected="true"]{background:var(--night);color:#C9D3EA}
.tab[aria-selected="true"] b{color:#fff}
.tab ul{display:none;margin:12px 0 2px;padding:0;list-style:none}
.tab[aria-selected="true"] ul{display:block}
.tab li{font-size:14px;padding:4px 0 4px 22px;position:relative;color:#DCE3F2}
.tab li:before{content:"";position:absolute;left:2px;top:12px;width:9px;height:9px;border-radius:3px;background:var(--gold)}
.viewer{position:relative;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 40px 90px -40px rgba(10,22,51,.55),0 0 0 1px var(--line);aspect-ratio:1440/934}
.viewer .bar{height:34px;background:#EEF1F7;display:flex;align-items:center;gap:7px;padding:0 14px}
.viewer .bar i{width:11px;height:11px;border-radius:50%;background:#CDD3E0}
.viewer img{position:absolute;left:0;right:0;top:34px;width:100%;opacity:0;transition:opacity .35s}
.viewer img.on{opacity:1}
@media (max-width:980px){.tour{grid-template-columns:1fr}.tabs{position:static;flex-direction:row;overflow-x:auto;gap:8px;padding-bottom:6px;scrollbar-width:none}.tab{flex:0 0 auto;padding:10px 16px;border:1px solid var(--line);background:#fff}.tab span,.tab ul{display:none!important}.tab b{font-size:15.5px}}

/* ---------- Pensé pour ici ---------- */
.local{background:var(--night);color:#fff}
.local .sec-head p{color:#C9D3EA}
.facts{display:grid;grid-template-columns:repeat(2,1fr);border-top:1px solid rgba(255,255,255,.14)}
.fact{padding:36px 36px 36px 0;border-bottom:1px solid rgba(255,255,255,.14)}
.fact:nth-child(even){padding-left:36px;border-left:1px solid rgba(255,255,255,.14)}
.fact h3{font-size:clamp(26px,3vw,36px);font-weight:700}
.fact p{color:#AEB9D3;margin:12px 0 0;max-width:46ch}
.fact .tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.fact .tags span{border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:5px 12px;font-size:13.5px;font-weight:600;color:#E6EBF6}
@media (max-width:760px){.facts{grid-template-columns:1fr}.fact,.fact:nth-child(even){padding:28px 0;border-left:0}}

/* ---------- Modules ---------- */
.mods{columns:3 280px;column-gap:40px}
.mod{break-inside:avoid;padding:0 0 30px}
.mod h3{font:700 20px/1.2 var(--display);display:flex;align-items:baseline;gap:10px;padding-bottom:12px;border-bottom:2px solid var(--ink);margin-bottom:12px}
.mod h3 small{font:600 13px "Instrument Sans",sans-serif;color:var(--mute)}
.mod ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px}
.mod li{font-size:14px;font-weight:500;background:#fff;border:1px solid var(--line);border-radius:8px;padding:4px 10px}

/* ---------- Téléchargements ---------- */
.dl-band{background:#fff;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
.dl-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:56px;align-items:center}
.dl-list{list-style:none;margin:0;padding:0;border-top:1px solid var(--line)}
.dl-list li{display:grid;grid-template-columns:44px 1fr auto;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
.dl-list .ic{width:44px;height:44px;border-radius:12px;background:var(--chalk);display:grid;place-items:center;color:var(--ink)}
.dl-list b{display:block;font-size:16px}
.dl-list small{color:var(--mute);font-size:13.5px}
.dl-list .soon{font-size:13.5px;color:var(--mute);font-weight:600}
.dl-act{display:flex;flex-direction:column;align-items:flex-end;gap:4px;text-align:right}
.dl-alt{font-size:12.5px;color:var(--mute);text-decoration:underline;text-underline-offset:2px}
.dl-alt:hover{color:var(--blue)}
.dl-ver{margin:8px 0 0;font-size:13.5px;color:var(--mute);font-weight:600}
.dl-src{margin:14px 0 0;font-size:14px;color:var(--ink2)}
.dl-src a{color:var(--blue);font-weight:600;text-decoration:underline;text-underline-offset:2px}
.dl-list li.mine .ic{background:var(--gold)}
.dl-list li.mine small:after{content:" · votre appareil";color:var(--blue);font-weight:700}
.dl-web{margin-top:22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.dl-web p{margin:0;color:var(--ink2);flex:1;min-width:220px}
/* Orbite des plateformes : chaque appareil reçoit les données synchronisées */
.orbit{position:relative;aspect-ratio:1;max-width:520px;width:100%;margin:0 auto;--r:41%}
.orbit .ring{position:absolute;inset:9%;border-radius:50%;border:1.5px dashed #CBD3E3}
.orbit .ring.r2{inset:27%;border-style:solid;border-color:#E2E6EF;animation:spin 40s linear infinite reverse}
.orbit .r1{animation:spin 60s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.orbit .core{position:absolute;left:50%;top:50%;width:30%;aspect-ratio:1;transform:translate(-50%,-50%);border-radius:28%;background:var(--night);display:grid;place-items:center;align-content:center;gap:8px;box-shadow:0 30px 60px -20px rgba(10,22,51,.6);animation:breathe 3.2s ease-in-out infinite}
.orbit .core img{width:48%}
.orbit .core span{color:#C9D3EA;font-size:12.5px;font-weight:600}
@keyframes breathe{50%{box-shadow:0 30px 60px -20px rgba(10,22,51,.6),0 0 0 14px rgba(255,197,49,.12)}}
.orbit .node{position:absolute;left:50%;top:50%;width:0;height:0;transform:rotate(calc(var(--i) * 60deg - 90deg))}
.orbit .beam{position:absolute;left:0;top:-1px;height:2px;width:var(--len,190px);transform-origin:left center;background:linear-gradient(90deg,rgba(255,197,49,0),rgba(255,197,49,.9),rgba(255,197,49,0));background-size:40% 100%;background-repeat:no-repeat;animation:beam 3s linear infinite;animation-delay:calc(var(--i) * .5s);opacity:.9}
@keyframes beam{from{background-position:-40% 0}to{background-position:140% 0}}
.orbit .dev{position:absolute;left:var(--len,190px);top:0;transform:translate(-50%,-50%) rotate(calc(90deg - var(--i) * 60deg));display:grid;justify-items:center;gap:6px;width:92px}
.orbit .dev svg{width:54px;height:54px;padding:14px;border-radius:16px;background:#fff;color:var(--night);box-shadow:0 14px 30px -14px rgba(10,22,51,.45),0 0 0 1px var(--line);animation:pop 3s ease-in-out infinite;animation-delay:calc(var(--i) * .5s + 1.5s)}
.orbit .dev em{font-style:normal;font-size:12.5px;font-weight:700;color:var(--ink2)}
@keyframes pop{0%,100%{transform:none;background:#fff}10%{transform:scale(1.14);background:var(--gold)}25%{transform:none;background:#fff}}
@media (max-width:1200px){.orbit{--len:160px}}
@media (max-width:900px){.dl-grid{grid-template-columns:1fr}.orbit{max-width:420px;margin-top:20px}.orbit .node{--len:150px}}
@media (max-width:460px){.orbit .node{--len:118px}.orbit .dev{width:70px}.orbit .dev svg{width:44px;height:44px;padding:11px}}

/* ---------- Tarifs ---------- */
.billing{display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:999px;padding:5px;margin:-20px 0 30px}
.billing button{border:0;background:none;padding:10px 20px;border-radius:999px;font:600 15px "Instrument Sans",sans-serif;cursor:pointer;color:var(--ink2);display:flex;gap:8px;align-items:center;transition:background .2s,color .2s}
.billing button span{background:#DCFAE6;color:#067647;border-radius:999px;padding:2px 8px;font-size:12.5px}
.billing button[aria-checked="true"]{background:var(--night);color:#fff}
.sub-price{font-size:13.5px;color:var(--mute);min-height:21px;margin-bottom:6px}
.sub-price s{opacity:.8}
.plan .promo{color:#067647;font-weight:700}
.plan.best .promo{color:#6CE9A6}
.plan.best .sub-price{color:#AEB9D3}
.incl{flex:1;margin-bottom:20px;font-size:14px}
.incl b{display:block;font-size:13.5px;margin-bottom:8px}
.incl ul{list-style:none;margin:0;padding:0;display:grid;gap:5px}
.incl li{position:relative;padding-left:20px;color:var(--ink2)}
.incl li:before{content:"✓";position:absolute;left:0;color:#067647;font-weight:800}
.incl li.more{color:var(--mute);font-style:italic}
.incl li.more:before{content:"+"}
.incl .worth{margin-top:12px;font-size:12.5px;color:var(--mute);border-top:1px dashed var(--line);padding-top:10px}
.plan.best .incl li{color:#DCE3F2}.plan.best .incl li:before{color:#6CE9A6}.plan.best .incl .worth{color:#AEB9D3;border-color:rgba(255,255,255,.2)}
.plan.free{background:linear-gradient(180deg,#FFF8E1,#fff 60%)}
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));border:1px solid var(--line);border-radius:22px;overflow:hidden;background:#fff}
.plan{padding:32px 28px;display:flex;flex-direction:column;border-left:1px solid var(--line)}
.plan:first-child{border-left:0}
.plan.best{background:var(--night);color:#fff;border-left-color:var(--night)}
.plan h3{font:700 22px/1.2 var(--display)}
.plan .tag{font-size:13px;font-weight:700;color:var(--night);background:var(--gold);border-radius:999px;padding:3px 10px;align-self:flex-start;margin-bottom:12px}
.price{font:800 36px/1 var(--display);letter-spacing:-.014em;margin:18px 0 6px;white-space:nowrap}
.price small{display:block;margin-top:6px;font:600 13.5px "Instrument Sans",sans-serif;color:var(--mute)}
.plan .btn{white-space:normal;text-align:center;padding-left:14px;padding-right:14px}
.plan{padding:30px 22px}
.plan.best .price small{color:#AEB9D3}
.plan .users{font-weight:600;font-size:14.5px;color:var(--blue);margin-bottom:14px}
.plan.best .users{color:var(--gold)}
.plan p{color:var(--ink2);font-size:14.5px;flex:1;margin:0 0 22px}
.plan.best p{color:#C9D3EA}
.plan.best .btn-ghost{border-color:rgba(255,255,255,.3);color:#fff}
.note{color:var(--mute);font-size:15px;margin-top:20px}
@media (max-width:760px){.plan{border-left:0;border-top:1px solid var(--line)}.plan:first-child{border-top:0}}

/* ---------- Licence ---------- */
.lic{display:grid;grid-template-columns:1fr 1.15fr;gap:64px;align-items:start}
.lic ol{list-style:none;margin:0;padding:0;counter-reset:s}
.lic li{counter-increment:s;display:grid;grid-template-columns:54px 1fr;gap:16px;padding:22px 0;border-top:1px solid var(--line)}
.lic li:before{content:counter(s);width:42px;height:42px;border-radius:12px;background:var(--night);color:var(--gold);display:grid;place-items:center;font:800 19px var(--display)}
.lic li b{display:block;font-size:17px;margin-bottom:2px}
.lic li span span{color:var(--ink2);font-size:15px}
@media (max-width:900px){.lic{grid-template-columns:1fr;gap:20px}}

/* ---------- Sur mesure + contact ---------- */
.custom{background:var(--gold);color:var(--night)}
.custom .grid{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:start}
.custom h2{font-size:clamp(34px,4.6vw,58px);font-weight:800;letter-spacing:-.014em}
.custom p.lead{font-size:18.5px;margin:18px 0 26px;max-width:46ch}
.custom .kinds{display:flex;flex-wrap:wrap;gap:8px}
.custom .kinds span{border:1.5px solid rgba(10,22,51,.3);border-radius:999px;padding:6px 14px;font-weight:600;font-size:14.5px}
.reach{margin-top:34px;display:grid;gap:4px}
.reach a,.reach div{display:flex;gap:12px;align-items:baseline;font-size:16px}
.reach small{width:92px;flex:none;color:rgba(10,22,51,.65);font-weight:600}
.reach a b{text-decoration:underline;text-decoration-thickness:1.5px;text-underline-offset:3px}
.cform{background:#fff;border-radius:22px;padding:30px;box-shadow:0 30px 60px -30px rgba(10,22,51,.45);position:relative}
.cform h3{font:700 24px/1.2 var(--display);margin-bottom:6px}
.cform label{display:block;font-weight:600;font-size:14px;margin:14px 0 6px;color:var(--ink)}
.cform input,.cform select,.cform textarea{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;font:inherit;font-size:15.5px;background:#fff;color:var(--ink)}
.cform input:focus,.cform select:focus,.cform textarea:focus{outline:none;border-color:var(--night)}
.cform .r2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cform .btn{width:100%;margin-top:18px;background:var(--night);color:#fff}
.cform .btn:hover{background:var(--night2)}
.okmsg{background:#ECFDF3;border:1px solid #A6F4C5;color:#05603A;border-radius:12px;padding:12px 14px;font-weight:600}
.errmsg{background:#FEF3F2;border:1px solid #FECDCA;color:#B42318;border-radius:12px;padding:12px 14px;font-size:14.5px}
@media (max-width:900px){.custom .grid{grid-template-columns:1fr}}
@media (max-width:520px){.cform .r2{grid-template-columns:1fr}.cform{padding:22px}}

/* ---------- FAQ + fin ---------- */
.faq{max-width:860px}
.faq details{border-bottom:1px solid var(--line);padding:20px 0}
.faq summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;gap:20px;font:700 19px/1.3 var(--display)}
.faq summary::-webkit-details-marker{display:none}
.faq summary:after{content:"+";font:500 26px/1 "Instrument Sans",sans-serif;color:var(--mute)}
.faq details[open] summary:after{content:"−"}
.faq p{color:var(--ink2);margin:12px 0 0;max-width:68ch}
.end{background:var(--night);color:#fff;text-align:center;padding:110px 0}
.end h2{font-size:clamp(40px,6vw,76px);font-weight:800;letter-spacing:-.018em;max-width:14ch;margin:0 auto}
.end p{color:#C9D3EA;font-size:18.5px;margin:20px auto 30px;max-width:52ch}
.end .hero-cta{justify-content:center}
