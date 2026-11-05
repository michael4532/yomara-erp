@php
  // Balises de suivi (Google Analytics 4, Tag Manager, Google Ads, Meta, TikTok, LinkedIn, Microsoft Clarity) et événements de conversion.
  $tk = array_filter((array) ($tracking ?? []));
  $ev = (array) ($events ?? []);
  $val = isset($ev['value']) ? (float) $ev['value'] : null;
  $cur = $ev['currency'] ?? 'XOF';
@endphp
@if(! empty($tk['gsc']))<meta name="google-site-verification" content="{{ $tk['gsc'] }}">@endif
@if(! empty($tk['meta_domain']))<meta name="facebook-domain-verification" content="{{ $tk['meta_domain'] }}">@endif
@if(! empty($tk['gtm']))
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@json($tk['gtm']));</script>
@endif
@if(! empty($tk['ga4']) || ! empty($tk['google_ads']))
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $tk['ga4'] ?? $tk['google_ads'] }}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());@if(! empty($tk['ga4']))gtag('config',@json($tk['ga4']));@endif @if(! empty($tk['google_ads']))gtag('config',@json($tk['google_ads']));@endif
@if(in_array('lead', $ev, true))gtag('event','generate_lead');@if(! empty($tk['google_ads_lead']))gtag('event','conversion',{send_to:@json($tk['google_ads'].'/'.$tk['google_ads_lead'])});@endif @endif
@if(isset($ev['purchase']))gtag('event','purchase',{value:{{ $val ?? 0 }},currency:@json($cur),transaction_id:@json((string) $ev['purchase'])});@if(! empty($tk['google_ads_purchase']))gtag('event','conversion',{send_to:@json($tk['google_ads'].'/'.$tk['google_ads_purchase']),value:{{ $val ?? 0 }},currency:@json($cur),transaction_id:@json((string) $ev['purchase'])});@endif @endif
</script>
@endif
@if(! empty($tk['meta_pixel']))
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init',@json($tk['meta_pixel']));fbq('track','PageView');@if(in_array('lead', $ev, true))fbq('track','Lead',{},{eventID:@json($ev['event_id'] ?? null)});@endif @if(isset($ev['purchase']))fbq('track','Purchase',{value:{{ $val ?? 0 }},currency:@json($cur)},{eventID:@json('order-'.$ev['purchase'])});@endif</script>
@endif
@if(! empty($tk['tiktok_pixel']))
<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
ttq.load(@json($tk['tiktok_pixel']));ttq.page();@if(in_array('lead', $ev, true))ttq.track('SubmitForm');@endif @if(isset($ev['purchase']))ttq.track('CompletePayment',{value:{{ $val ?? 0 }},currency:@json($cur)});@endif}(window,document,'ttq');</script>
@endif
@if(! empty($tk['linkedin']))
<script>_linkedin_partner_id=@json($tk['linkedin']);window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";s.parentNode.insertBefore(b,s);})(window.lintrk);</script>
@endif
@if(! empty($tk['clarity']))
<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script",@json($tk['clarity']));</script>
@endif
{{-- Origine du visiteur (utm_*, gclid, fbclid, ttclid) gardée 90 jours et ajoutée à chaque formulaire envoyé --}}
<script>(function(){try{var K='yo_attr',p=new URLSearchParams(location.search),keys=['utm_source','utm_medium','utm_campaign','utm_content','utm_term','gclid','fbclid','ttclid'],a=null;
keys.forEach(function(k){var v=p.get(k);if(v){a=a||{};a[k]=v.slice(0,150);}});
if(!a&&document.referrer&&document.referrer.indexOf(location.host)<0){var h=new URL(document.referrer).hostname.replace('www.','');a={utm_source:h,utm_medium:/google|bing|yahoo|duckduckgo/.test(h)?'organic':(/facebook|instagram|tiktok|linkedin|t\.co|whatsapp/.test(h)?'social':'referral')};}
var old=JSON.parse(localStorage.getItem(K)||'null');if(a&&(!old||a.utm_campaign||a.gclid||a.fbclid||a.ttclid||Date.now()-old.t>7776e6)){a.t=Date.now();if(a.fbclid)a.fbc='fb.1.'+Date.now()+'.'+a.fbclid;localStorage.setItem(K,JSON.stringify(a));}
document.addEventListener('submit',function(e){var f=e.target,d=JSON.parse(localStorage.getItem(K)||'null');if(!d||!f||f.method.toLowerCase()!=='post')return;Object.keys(d).forEach(function(k){if(k==='t'||f.querySelector('[name="'+k+'"]'))return;var i=document.createElement('input');i.type='hidden';i.name=k;i.value=d[k];f.appendChild(i);});},true);}catch(e){}})();</script>
