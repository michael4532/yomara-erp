@section('footer', 'Portail fournisseurs')
<style>
  .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; } .tabs a { padding:8px 14px; border-radius:20px; background:#fff; border:1px solid var(--line); color:var(--night); font-weight:600; font-size:14px; }
  .tabs a.on { background:var(--night); color:#fff; border-color:var(--night); }
  table { width:100%; border-collapse:collapse; font-size:14px; } th, td { text-align:left; padding:8px 6px; border-bottom:1px solid #EEF1F6; vertical-align:top; } th { color:var(--muted); font-weight:600; font-size:13px; }
  td.r, th.r { text-align:right; } .item { display:flex; gap:10px; align-items:center; padding:12px 0; border-bottom:1px solid #EEF1F6; color:inherit; } .item:last-child { border:0; } .item .sp { flex:1; }
  .p-ok { background:#E7F6EE; color:var(--ok); } .p-wait { background:#FFF6DB; color:#9A6B00; } .p-bad { background:#FCEAEA; color:var(--bad); } .p-info { background:#E8F0FE; color:var(--blue); }
  input.price { text-align:right; padding:8px; } select { width:100%; padding:12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#fff; }
  .two { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; } @media (max-width:560px) { .two { grid-template-columns:1fr; } }
</style>
<p class="muted" style="margin:0 0 8px">Espace fournisseur de <b>{{ $partner->name }}</b></p>
<nav class="tabs">
  <a href="{{ url('/fournisseur/'.$token) }}" class="{{ $tab === 'home' ? 'on' : '' }}">Accueil</a>
  <a href="{{ url('/fournisseur/'.$token.'/ao') }}" class="{{ in_array($tab, ['tenders', 'tender']) ? 'on' : '' }}">Appels d'offres</a>
  <a href="{{ url('/fournisseur/'.$token.'/commandes') }}" class="{{ in_array($tab, ['orders', 'order']) ? 'on' : '' }}">Commandes</a>
  <a href="{{ url('/fournisseur/'.$token.'/factures') }}" class="{{ $tab === 'invoices' ? 'on' : '' }}">Factures</a>
  <a href="{{ url('/fournisseur/'.$token.'/paiements') }}" class="{{ $tab === 'payments' ? 'on' : '' }}">Paiements</a>
</nav>
@if($ok)<div class="flash ok">{{ $ok }}</div>@endif
@if($error)<div class="flash bad">{{ $error }}</div>@endif
@if($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
