@extends('sites.layout')
@php
  extract(\App\Modules\Sites\SiteView::vars(get_defined_vars()));
  $hero = $t['hero'] ?? 'gradient';
  if ($hero_image && $hero === 'centered') { $hero = 'image'; }
  if ($hero === 'image' && ! $hero_image) { $hero = 'centered'; }
@endphp
@section('main')
@foreach($sections as $sec)
  @switch($sec)
  @case('hero')
    @if($hero === 'gradient')
      <section class="hero h-gradient">
        <svg class="art" viewBox="0 0 1200 600" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><circle cx="1050" cy="80" r="260" fill="{{ $t['accent'] }}" opacity=".18"/><circle cx="160" cy="560" r="220" fill="#fff" opacity=".07"/><path d="M0 520 C 300 440 520 620 820 520 S 1200 460 1200 460 V600 H0z" fill="#fff" opacity=".06"/></svg>
        <div class="wrap hero-grid">
          <div class="reveal"><div class="kicker">{{ $company->city ?: $template['sector_name'] }}</div><h1>{{ $c['hero_title'] }}</h1><p class="lead">{{ $c['hero_subtitle'] }}</p>
            <div class="acts"><a class="btn light" href="{{ $cta }}">{{ $c['cta_label'] ?? 'Nous contacter' }} {!! $ic('arrow', 18) !!}</a>@if($whatsapp && ($c['cta_target'] ?? '') !== 'whatsapp')<a class="btn alt" href="https://wa.me/{{ $whatsapp }}">WhatsApp</a>@endif</div>
            <div class="badges">@foreach(array_slice($c['services'] ?? [], 0, 3) as $s)<span class="badge">{!! $ic($s['icon'] ?? 'check', 16) !!} {{ $s['title'] }}</span>@endforeach</div>
          </div>
          <div class="reveal">@if($hero_image)<div class="hero-card"><img src="{{ $hero_image }}" alt=""></div>@else
            <svg class="deco" viewBox="0 0 420 380" style="width:100%;height:auto" aria-hidden="true"><rect x="40" y="30" width="300" height="300" rx="{{ min(60, (int) $t['radius'] * 3) }}" fill="#fff" opacity=".12" transform="rotate(-8 190 180)"/><rect x="80" y="60" width="300" height="290" rx="{{ min(60, (int) $t['radius'] * 3) }}" fill="#fff" opacity=".95"/><circle cx="230" cy="175" r="70" fill="{{ $t['primary'] }}" opacity=".14"/><g transform="translate(190 135) scale(3.4)" style="color:{{ $t['primary'] }}">{!! $ic($template['icon']) !!}</g><rect x="130" y="275" width="200" height="12" rx="6" fill="{{ $t['dark'] }}" opacity=".15"/><rect x="160" y="300" width="140" height="10" rx="5" fill="{{ $t['accent'] }}" opacity=".7"/></svg>@endif</div>
        </div>
      </section>
    @elseif($hero === 'split')
      <section class="hero h-split">
        <div class="cols">
          <div class="txt reveal"><div class="kicker">{{ $template['sector_name'] }}</div><h1>{{ $c['hero_title'] }}</h1><p class="lead">{{ $c['hero_subtitle'] }}</p>
            <div class="acts"><a class="btn" href="{{ $cta }}">{{ $c['cta_label'] ?? 'Nous contacter' }} {!! $ic('arrow', 18) !!}</a>@if($whatsapp && ($c['cta_target'] ?? '') !== 'whatsapp')<a class="btn alt" style="color:var(--d)" href="https://wa.me/{{ $whatsapp }}">WhatsApp</a>@endif</div></div>
          <div class="panel">@if($hero_image)<img src="{{ $hero_image }}" alt="">@else
            <svg class="art" viewBox="0 0 600 600" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><defs><pattern id="gd" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0V40" fill="none" stroke="#fff" stroke-opacity=".08"/></pattern></defs><rect width="600" height="600" fill="url(#gd)"/><circle cx="420" cy="200" r="150" fill="{{ $t['primary'] }}" opacity=".85"/><rect x="120" y="300" width="230" height="230" fill="{{ $t['accent'] }}" opacity=".9" transform="rotate(12 235 415)"/><g transform="translate(372 152) scale(4)" style="color:#fff">{!! $ic($template['icon']) !!}</g></svg>@endif</div>
        </div>
      </section>
    @elseif($hero === 'image')
      <section class="hero h-image"><img class="bgimg" src="{{ $hero_image }}" alt=""><div class="shade"></div>
        <div class="wrap" style="position:relative"><div class="reveal" style="max-width:660px"><div class="kicker">{{ $template['sector_name'] }}</div><h1>{{ $c['hero_title'] }}</h1><p class="lead">{{ $c['hero_subtitle'] }}</p>
          <div class="acts"><a class="btn" href="{{ $cta }}">{{ $c['cta_label'] ?? 'Nous contacter' }}</a>@if($whatsapp && ($c['cta_target'] ?? '') !== 'whatsapp')<a class="btn alt" href="https://wa.me/{{ $whatsapp }}">WhatsApp</a>@endif</div></div></div>
      </section>
    @else
      <section class="hero h-centered">
        <svg class="art" viewBox="0 0 1200 600" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><circle cx="600" cy="300" r="250" fill="none" stroke="{{ $t['accent'] }}" stroke-opacity=".35"/><circle cx="600" cy="300" r="330" fill="none" stroke="{{ $t['primary'] }}" stroke-opacity=".12"/><circle cx="600" cy="300" r="410" fill="none" stroke="{{ $t['primary'] }}" stroke-opacity=".07"/></svg>
        <div class="wrap reveal" style="position:relative"><div class="kicker">{{ $template['sector_name'] }}</div><h1>{{ $c['hero_title'] }}</h1><div class="orn"></div><p class="lead">{{ $c['hero_subtitle'] }}</p>
          <div class="acts"><a class="btn" href="{{ $cta }}">{{ $c['cta_label'] ?? 'Nous contacter' }}</a><a class="btn alt" style="color:var(--d)" href="#{{ in_array('services', $sections, true) ? 'services' : 'contact' }}">Découvrir</a></div></div>
      </section>
    @endif
    @break
  @case('about')
    <section id="apropos" class="alt-bg"><div class="wrap about">
      <div class="reveal"><div class="kicker">{{ $name }}</div><h2>{{ $c['about_title'] }}</h2><p class="lead" style="white-space:pre-line">{{ $c['about_text'] }}</p>
        <ul class="ticks">@foreach(array_slice($c['services'] ?? [], 0, 4) as $s)<li>{!! $ic('check', 18) !!}<span><b>{{ $s['title'] }}</b> — {{ $s['text'] }}</span></li>@endforeach</ul></div>
      <div class="pic reveal">@if($about_image)<img src="{{ $about_image }}" alt="">@elseif(count($gallery))<img src="{{ $gallery[0] }}" alt="">@else<span class="mono">{{ $initial }}</span>@endif</div>
    </div></section>
    @break
  @case('services')
    <section id="services"><div class="wrap">
      <div class="center reveal"><div class="kicker">{{ $c['services_title'] ?? 'Nos services' }}</div><h2>Ce que nous faisons pour vous</h2></div>
      <div class="grid g{{ min(4, max(2, count($c['services'] ?? []))) === 4 ? 4 : 3 }}" style="margin-top:34px">
        @foreach($c['services'] ?? [] as $i => $s)<div class="card reveal" style="transition-delay:{{ $i * 70 }}ms"><div class="ico">{!! $ic($s['icon'] ?? 'check') !!}</div><h3>{{ $s['title'] }}</h3><p class="muted" style="margin:0">{{ $s['text'] }}</p></div>@endforeach
      </div></div></section>
    @break
  @case('stats')
    <section class="stats"><div class="wrap grid g4">@foreach($c['stats'] as $s)<div class="reveal"><div class="n">{{ $s['value'] }}</div><p>{{ $s['label'] }}</p></div>@endforeach</div></section>
    @break
  @case('products')
    <section id="produits" class="alt-bg"><div class="wrap">
      <div class="reveal"><div class="kicker">Catalogue</div><h2>Nos produits</h2><p class="lead">Disponibles maintenant — commandez en un message.</p></div>
      <div class="grid g4" style="margin-top:30px">@foreach($products as $p)
        <div class="card prod reveal"><div class="im">@if($p['image'])<img src="{{ $p['image'] }}" alt="">@else{!! $ic($template['icon'], 40) !!}@endif</div>
          <div class="bd"><h3 style="margin:0">{{ $p['name'] }}</h3>@if($p['description'])<span class="muted" style="font-size:.92rem">{{ $p['description'] }}</span>@endif<span class="price">@if(! empty($p['from']))<small class="muted" style="font-size:.8rem;font-weight:600">À partir de</small> @endif{{ number_format($p['price'], 0, ',', ' ') }} {{ $currency }}</span>
            <a href="{{ $shopOn && ! empty($p['id']) ? ($base ?? '').'/produit/'.$p['id'].'/'.$p['slug'].$qs : ($shopOn ? $shopUrl : $waText($p['name'])) }}">{{ $shopOn ? (! empty($p['is_template']) ? 'Choisir' : 'Voir le produit') : 'Commander' }} {!! $ic('arrow', 16) !!}</a></div></div>
      @endforeach</div>
      @if($shopOn)<div class="center" style="margin-top:30px"><a class="btn" href="{{ $shopUrl }}">Voir toute la boutique {!! $ic('arrow', 18) !!}</a></div>@endif</div></section>
    @break
  @case('blog')
    <section id="blog"><div class="wrap">
      <div class="reveal" style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap"><div><div class="kicker">Actualités</div><h2 style="margin:0">Derniers articles</h2></div><a class="btn alt" style="color:var(--p)" href="{{ $blogUrl }}">Tous les articles</a></div>
      <div class="grid g3" style="margin-top:30px">@foreach($posts as $po)
        <a class="card prod reveal" style="text-decoration:none" href="{{ $base }}/blog/{{ $po['slug'] }}{{ $qs }}"><div class="im">@if($po['cover'])<img src="{{ $po['cover'] }}" alt="">@else{!! $ic('book', 40) !!}@endif</div>
          <div class="bd"><span class="muted" style="font-size:.85rem">{{ $po['date']?->locale('fr')->isoFormat('D MMMM YYYY') }}</span><h3 style="margin:0">{{ $po['title'] }}</h3><span class="muted" style="font-size:.93rem">{{ $po['excerpt'] }}</span><span style="margin-top:auto;font-weight:700;color:var(--p)">Lire l'article →</span></div></a>
      @endforeach</div></div></section>
    @break
  @case('events')
    <section id="evenements"><div class="wrap">
      <div class="reveal"><div class="kicker">Agenda</div><h2>Nos prochains événements</h2></div>
      <div class="grid g2" style="margin-top:30px">@foreach($events as $e)
        <div class="card ev reveal"><div class="date"><b>{{ $e['date']->format('d') }}</b><span>{{ $e['date']->locale('fr')->isoFormat('MMM YYYY') }}</span></div>
          <div style="flex:1"><h3 style="margin-bottom:4px">{{ $e['name'] }}</h3><div class="muted" style="font-size:.92rem">{{ $e['venue'] }}</div>@if($e['url'])<a class="btn" style="margin-top:12px;padding:9px 16px" href="{{ $e['url'] }}">S'inscrire</a>@endif</div></div>
      @endforeach</div></div></section>
    @break
  @case('gallery')
    <section id="galerie" class="alt-bg"><div class="wrap"><div class="center reveal"><div class="kicker">Galerie</div><h2>En images</h2></div>
      <div class="gal" style="margin-top:30px">@foreach($gallery as $g)<img class="reveal" src="{{ $g }}" alt="" loading="lazy">@endforeach</div></div></section>
    @break
  @case('testimonials')
    <section><div class="wrap"><div class="center reveal"><div class="kicker">Ils nous font confiance</div><h2>Ce que disent nos clients</h2>@if(($rating['count'] ?? 0) > 0)<p class="lead"><span style="color:#E8A317">{{ str_repeat('★', (int) round($rating['average'])) }}</span> {{ number_format($rating['average'], 1, ',', '') }}/5 · {{ $rating['count'] }} avis vérifiés</p>@endif</div>
      <div class="grid g3" style="margin-top:30px">
        @foreach($reviews as $q)<div class="card reveal"><div style="color:#E8A317;letter-spacing:2px">{{ str_repeat('★', $q['rating']) }}</div><div class="quote" style="margin-top:6px">{{ $q['text'] }}</div><div class="who">{{ $q['name'] }}<span>✓ Achat vérifié</span></div>@if($q['reply'])<div class="muted" style="margin-top:10px;font-size:.9rem;border-left:3px solid var(--p);padding-left:10px">{{ $q['reply'] }}</div>@endif</div>@endforeach
        @foreach($c['testimonials'] ?? [] as $q)<div class="card reveal"><div class="quote">{{ $q['text'] }}</div><div class="who">{{ $q['name'] }}@if(! empty($q['role']))<span>{{ $q['role'] }}</span>@endif</div></div>@endforeach
      </div></div></section>
    @break
  @case('faq')
    <section id="faq" class="alt-bg"><div class="wrap" style="max-width:820px"><div class="center reveal"><div class="kicker">FAQ</div><h2>Questions fréquentes</h2></div>
      <div style="margin-top:20px">@foreach($c['faq'] as $q)<details class="q reveal"><summary>{{ $q['q'] }}</summary><p>{{ $q['a'] }}</p></details>@endforeach</div></div></section>
    @break
  @case('booking')
    <section><div class="wrap"><div class="band reveal"><div><h2>Réservez en ligne en 1 minute</h2><p>Choisissez la prestation, le jour et l'heure : confirmation immédiate.</p></div><a class="btn light" href="{{ $booking }}">Prendre rendez-vous {!! $ic('arrow', 18) !!}</a></div></div></section>
    @break
  @case('jobs')
    <section style="padding-top:0"><div class="wrap"><div class="band reveal" style="background:linear-gradient(120deg,var(--d),var(--p))"><div><h2>Nous recrutons</h2><p>{{ $jobs['count'] }} offre(s) d'emploi ouverte(s) — postulez en ligne.</p></div><a class="btn light" href="{{ $jobs['url'] }}">Voir les offres</a></div></div></section>
    @break
  @case('contact')
    <section id="contact" class="{{ $loop->iteration % 2 ? '' : 'alt-bg' }}"><div class="wrap contact">
      <div class="reveal"><div class="kicker">Contact</div><h2>Parlons de votre besoin</h2><p class="lead">Écrivez-nous, nous vous répondons rapidement.</p>
        <div class="info" style="margin-top:26px">
          @if($address)<div class="row"><span class="ico">{!! $ic('map', 20) !!}</span><div><b>Adresse</b><br><a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($address.', '.$company->country) }}" target="_blank" rel="noopener">{{ $address }}</a></div></div>@endif
          @if($company->phone)<div class="row"><span class="ico">{!! $ic('phone', 20) !!}</span><div><b>Téléphone</b><br><a href="tel:{{ preg_replace('/[^\d+]/', '', $company->phone) }}">{{ $company->phone }}</a></div></div>@endif
          @if($company->email)<div class="row"><span class="ico">{!! $ic('mail', 20) !!}</span><div><b>E-mail</b><br><a href="mailto:{{ $company->email }}">{{ $company->email }}</a></div></div>@endif
          @if(! empty($c['hours']))<div class="row"><span class="ico">{!! $ic('clock', 20) !!}</span><div><b>Horaires</b><br><span style="white-space:pre-line">{{ $c['hours'] }}</span></div></div>@endif
        </div>
        @if($socials)<div class="socials" style="margin-top:22px;color:var(--p)">@foreach($socials as $k => $u)<a href="{{ $u }}" target="_blank" rel="noopener">{{ ucfirst($k === 'tiktok' ? 'TikTok' : ($k === 'linkedin' ? 'LinkedIn' : ($k === 'youtube' ? 'YouTube' : $k))) }}</a>@endforeach</div>@endif
      </div>
      <div class="card reveal" style="padding:30px">
        @if($sent)<div class="flash ok">Merci ! Votre message a bien été envoyé, nous revenons vers vous très vite.</div>@else
        @if($error)<div class="flash bad" style="margin-bottom:14px">{{ $error }}</div>@endif
        <form class="f" method="post" action="{{ $action }}" @if(! empty($template_preview)) onsubmit="return false" @endif>
          <input class="hp" name="website" tabindex="-1" autocomplete="off">
          <div><label for="cf-name">Nom</label><input id="cf-name" name="name" required maxlength="160" autocomplete="name"></div>
          <div class="two"><div><label for="cf-email">E-mail</label><input id="cf-email" name="email" type="email" maxlength="190" autocomplete="email"></div><div><label for="cf-phone">Téléphone</label><input id="cf-phone" name="phone" maxlength="40" inputmode="tel" autocomplete="tel"></div></div>
          <div><label for="cf-subject">Objet</label><input id="cf-subject" name="subject" maxlength="190"></div>
          <div><label for="cf-msg">Message</label><textarea id="cf-msg" name="message" rows="5" required minlength="5" maxlength="5000"></textarea></div>
          @if($marketing)<label class="chk"><input type="checkbox" name="consent" value="1"> J'accepte de recevoir les actualités et offres de {{ $company->name }}.</label>@endif
          <button class="btn" type="submit">Envoyer le message {!! $ic('arrow', 18) !!}</button>
        </form>@endif
      </div>
    </div></section>
    @break
  @endswitch
@endforeach
@endsection
