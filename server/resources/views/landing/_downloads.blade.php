<section class="sec dl-band" id="telecharger">
  <div class="wrap dl-grid">
    <div>
      <div class="sec-head" style="margin-bottom:30px">
        <h2>Le même compte sur tous vos écrans.</h2>
        <p>Connectez-vous avec votre e-mail et votre mot de passe : vos données vous suivent du bureau au téléphone.</p>
        @if($appsVersion)<p class="dl-ver">Version {{ $appsVersion }}</p>@endif
      </div>
      <ul class="dl-list">
        @foreach($platforms as $k => $label)
          @php $url = $downloads[$k] ?? null; @endphp
          <li data-os="{{ $k }}" class="rv" style="--d:{{ $loop->index * .08 }}s">
            <span class="ic">{!! $osIcons[$k] !!}</span>
            <span><b>{{ $label }}</b><small>{{ $osHint[$k] }}</small></span>
            @php $store = $dlStores[$k] ?? null; $file = $dlFiles[$k] ?? null; @endphp
            <span class="dl-act">
              @if($url)<a class="btn btn-gold btn-sm" href="/telecharger/{{ $k }}" rel="nofollow">{{ $store ? ($storeNames[$k] ?? 'Télécharger') : 'Télécharger' }}</a>@else<span class="soon">Bientôt disponible</span>@endif
              @if($store && $file)<a class="dl-alt" href="/telecharger/{{ $k }}/fichier" rel="nofollow">ou le {{ $fileNames[$k] ?? 'fichier' }}</a>@endif
            </span>
          </li>
        @endforeach
      </ul>
      <div class="dl-web">
        <p>Pas envie d'installer ? Utilisez la version web dans Chrome, Edge, Safari ou Firefox.</p>
        <a class="btn btn-ghost btn-sm" href="/app/">Ouvrir la version web</a>
      </div>
      @if($sourceRepo)
        <p class="dl-src">Logiciel libre (licence AGPL) : <a href="/telecharger/github" rel="nofollow">le code source est sur {{ str_contains($sourceRepo, 'gitlab') ? 'GitLab' : (str_contains($sourceRepo, 'github') ? 'GitHub' : 'notre dépôt') }}</a>.</p>
      @endif
    </div>
    <div class="orbit" aria-hidden="true">
      <div class="ring r1"></div><div class="ring r2"></div>
      <div class="core"><img src="/brand/mark.svg" alt=""><span>Vos données</span></div>
      @foreach(['web' => 'Navigateur', 'windows' => 'Windows', 'mac' => 'Mac', 'linux' => 'Linux', 'android' => 'Android', 'ios' => 'iPhone'] as $k => $l)
        <div class="node" style="--i:{{ $loop->index }}"><span class="beam"></span><span class="dev">{!! $osIcons[$k] !!}<em>{{ $l }}</em></span></div>
      @endforeach
    </div>
  </div>
</section>
