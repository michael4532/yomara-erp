@if($plans->count())
<section class="sec" id="tarifs">
  <div class="wrap">
    <div class="sec-head rv">
      <h2>Tous vos employés inclus. Vous payez les modules.</h2>
      <p>Le nombre d'utilisateurs n'est jamais limité. Commencez gratuitement avec les fonctions de base ; chaque formule ajoute un ensemble de modules, moins cher que de les prendre un par un. Passez à une formule complète quand vous voulez : {{ $trialDays > 0 ? $trialDays.' jours d\'essai, ' : '' }}paiement par Mobile Money, carte ou virement.</p>
    </div>
    <div class="billing rv" role="radiogroup" aria-label="Période de facturation">
      <button type="button" role="radio" aria-checked="true" data-b="m">Au mois</button>
      <button type="button" role="radio" aria-checked="false" data-b="y">À l'année @if($maxYearly > 0)<span>−{{ rtrim(rtrim(number_format($maxYearly, 1, ',', ''), '0'), ',') }} %</span>@endif</button>
    </div>
    <div class="plans">
      @foreach($plans as $p)
        @php $o = $p->offer(0); $best = (bool) $p->badge; @endphp
        <div class="plan rv {{ $best ? 'best' : '' }} {{ $o['is_free'] ? 'free' : '' }}" style="--d:{{ $loop->index * .06 }}s">
          @if($p->badge)<span class="tag">{{ $p->badge }}</span>@endif
          <h3>{{ $p->name }}</h3>
          @if($o['is_free'])
            <div class="price">0 <small>{{ $cur }}</small></div>
            <div class="sub-price">Gratuit, sans limite de durée</div>
          @else
            <div class="price m">{{ $fmt($o['monthly_now']) }} <small>{{ $cur }} / mois</small></div>
            <div class="sub-price m">@if($o['intro_left'] > 0)<s>{{ $fmt($o['monthly']) }}</s> <b class="promo">−{{ rtrim(rtrim(number_format($o['intro_discount'], 1, ',', ''), '0'), ',') }} % les {{ $o['intro_months'] }} premiers mois</b>@else&nbsp;@endif</div>
            <div class="price y" hidden>{{ $fmt($o['yearly'] / 12) }} <small>{{ $cur }} / mois</small></div>
            <div class="sub-price y" hidden>{{ $fmt($o['yearly']) }} {{ $cur }} par an @if($o['yearly_saving'] > 0)<b class="promo">{{ $fmt($o['yearly_saving']) }} économisés</b>@endif</div>
          @endif
          <div class="users">{{ $p->max_users ? 'Jusqu\'à '.$p->max_users.' utilisateur'.($p->max_users > 1 ? 's' : '') : 'Tous vos employés, sans limite' }}</div>
          @php $info = $planInfo[$p->code] ?? null; @endphp
          @if($info && $info['count'])
            <div class="incl">
              <b>{{ $info['base'] ? 'Tout '.$info['base'].', plus :' : $info['count'].' modules inclus :' }}</b>
              <ul>@foreach(array_slice($info['added'], 0, 7) as $n)<li>{{ $n }}</li>@endforeach @if(count($info['added']) > 7)<li class="more">et {{ count($info['added']) - 7 }} autres modules</li>@endif</ul>
              @if($info['saving'] >= 10)<div class="worth">{{ $info['count'] }} modules d'une valeur de {{ $fmt($info['value']) }} {{ $cur }} / mois pris un par un : <b>−{{ $info['saving'] }} %</b></div>@endif
            </div>
          @else
            <p>{{ $p->description }}</p>
          @endif
          <a class="btn {{ $best ? 'btn-gold' : 'btn-ghost' }}" href="/inscription?formule={{ $p->code }}">{{ $o['is_free'] ? 'Commencer gratuitement' : 'Choisir '.$p->name }}</a>
        </div>
      @endforeach
    </div>
    <p class="note">Code promo ? Saisissez-le au moment du paiement, dans « Boutique › Mon abonnement ».@if($minModulePrice) Modules en plus à partir de {{ $fmt($minModulePrice) }} {{ $cur }} par mois.@endif</p>
  </div>
</section>
@endif
