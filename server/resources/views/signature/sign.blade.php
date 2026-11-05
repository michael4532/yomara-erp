@extends('signature.layout')
@section('title', $r->title)
@section('subtitle', 'Signature électronique de document')
@section('style')
  .viewer { background:#E9EDF5; border-radius:12px; max-height:72vh; overflow:auto; padding:10px; }
  .viewer canvas { display:block; width:100%; height:auto; margin:0 auto 10px; box-shadow:0 1px 4px rgba(11,27,58,.15); background:#fff; }
  .pad { position:relative; border:2px dashed #B9C4D8; border-radius:12px; background:#fff; touch-action:none; height:190px; }
  .pad canvas { width:100%; height:100%; display:block; border-radius:12px; cursor:crosshair; }
  .pad .hint { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#9AA6BC; pointer-events:none; font-size:15px; }
  .row { display:flex; gap:10px; justify-content:space-between; align-items:center; flex-wrap:wrap; }
  .signers { list-style:none; padding:0; margin:8px 0 0; } .signers li { display:flex; justify-content:space-between; gap:8px; padding:8px 0; border-top:1px solid var(--line); font-size:14px; }
  details summary { cursor:pointer; color:var(--muted); font-size:14px; margin-top:14px; }
@endsection
@section('content')
@php $active = $r->status === 'pending' && in_array($s->status, ['pending', 'viewed'], true); @endphp
<div class="card">
  <div class="row"><h2 style="margin:0">{{ $r->title }}</h2>
    @if($r->status === 'completed')<span class="pill ok">Signé par toutes les parties</span>
    @elseif($s->status === 'signed')<span class="pill ok">Vous avez signé</span>
    @elseif($r->status === 'pending')<span class="pill warn">En attente de signature</span>
    @else<span class="pill bad">{{ \App\Modules\Signature\Models\SignRequest::STATUSES[$r->status] ?? $r->status }}</span>@endif
  </div>
  <p class="muted" style="margin:6px 0 0">Bonjour <b>{{ $s->name }}</b>, {{ $company->name }} vous demande de signer ce document{{ $s->label ? ' en tant que « '.$s->label.' »' : '' }}.
    @if($r->expires_at && $r->status === 'pending') À signer avant le {{ $r->expires_at->format('d/m/Y') }}.@endif Réf. {{ $r->number }}</p>
  @if($r->message)<p class="text" style="margin:10px 0 0">{{ $r->message }}</p>@endif
</div>

<div class="card">
  <div class="row" style="margin-bottom:10px"><h3 style="margin:0">{{ $r->status === 'completed' && $r->signed_path ? 'Document signé' : 'Document' }}</h3>
    <a class="btn ghost small" href="/signer/{{ $token }}/document?dl=1">Télécharger le PDF</a></div>
  <div class="viewer" id="viewer"><p class="muted" id="loading" style="text-align:center;padding:30px 0">Chargement du document…<br><a href="/signer/{{ $token }}/document" target="_blank">Ouvrir le document dans un nouvel onglet</a></p></div>
</div>

@if($active && $s->status !== 'waiting')
  @if($otp && ! $verified)
  <div class="card">
    <h3>1. Vérifiez votre identité</h3>
    <p class="muted" style="margin:0">Pour sécuriser votre signature, nous envoyons un code à usage unique {{ ['sms' => 'par SMS au', 'whatsapp' => 'par WhatsApp au', 'email' => 'par e-mail à'][$otp] }} <b>{{ $otpTo }}</b>.</p>
    <form method="post" action="/signer/{{ $token }}/code">@csrf<button class="btn ghost" type="submit">{{ $otpSent ? 'Renvoyer un code' : 'Recevoir mon code' }}</button></form>
    @if($otpSent)
    <form method="post" action="/signer/{{ $token }}/verifier" style="margin-top:6px">@csrf
      <label for="code">Code reçu (6 chiffres)</label>
      <input type="text" id="code" name="code" class="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required>
      <button class="btn" type="submit">Valider le code</button>
    </form>
    @endif
  </div>
  @else
  <div class="card">
    <h3>{{ $otp ? '2. ' : '' }}Votre signature</h3>
    <form method="post" action="/signer/{{ $token }}/signer" id="signForm">@csrf
      <div class="pad" id="pad"><canvas id="sig"></canvas><div class="hint" id="hint">Signez ici avec le doigt ou la souris</div></div>
      <div class="row" style="margin-top:6px"><span class="muted">Signataire : <b>{{ $s->name }}</b></span><button type="button" class="btn ghost small" id="clear">Effacer</button></div>
      <input type="hidden" name="signature" id="signature">
      <label class="check"><input type="checkbox" name="agree" value="1" id="agree"> <span>J'ai lu le document et j'accepte de le signer électroniquement. Ma signature a la même valeur qu'une signature manuscrite.</span></label>
      <button class="btn ok" type="submit" id="submit" disabled>Signer le document</button>
    </form>
    <details><summary>Je ne souhaite pas signer</summary>
      <form method="post" action="/signer/{{ $token }}/refuser">@csrf
        <label for="reason">Motif (transmis à {{ $company->name }})</label><textarea id="reason" name="reason" rows="3" maxlength="500"></textarea>
        <button class="btn ghost" type="submit">Refuser de signer</button>
      </form>
    </details>
  </div>
  @endif
@elseif($s->status === 'waiting' && $r->status === 'pending')
  <div class="card"><p style="margin:0">Un autre signataire doit signer avant vous. Vous recevrez un message à votre tour ; vous pouvez déjà lire le document.</p></div>
@elseif($s->status === 'declined')
  <div class="card"><p style="margin:0">Vous avez refusé de signer ce document{{ $s->decline_reason ? ' : « '.$s->decline_reason.' »' : '' }}.</p></div>
@endif

<div class="card">
  <h3>Signataires</h3>
  <ul class="signers">
    @foreach($others as $o)
      <li><span><b>{{ $o->name }}</b>{{ $o->label ? ' — '.$o->label : '' }}</span>
        <span>@if($o->status === 'signed')<span class="pill ok">Signé le {{ $o->signed_at->setTimezone($company->setting('timezone') ?: config('app.timezone'))->format('d/m/Y H:i') }}</span>@elseif($o->status === 'declined')<span class="pill bad">Refusé</span>@else<span class="pill">En attente</span>@endif</span></li>
    @endforeach
  </ul>
</div>
@endsection
@section('script')
<script src="/vendor/pdfjs/pdf.min.js"></script>
<script>
(function () {
  var url = '/signer/{{ $token }}/document';
  var viewer = document.getElementById('viewer');
  if (window.pdfjsLib) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.min.js';
    pdfjsLib.getDocument(url).promise.then(function (pdf) {
      document.getElementById('loading').remove();
      var chain = Promise.resolve();
      for (var i = 1; i <= pdf.numPages; i++) {
        (function (n) {
          chain = chain.then(function () {
            return pdf.getPage(n).then(function (page) {
              var w = viewer.clientWidth - 20, v1 = page.getViewport({ scale: 1 }), scale = Math.min(2.2, (w / v1.width) * (window.devicePixelRatio || 1));
              var vp = page.getViewport({ scale: scale }), c = document.createElement('canvas');
              c.width = vp.width; c.height = vp.height; viewer.appendChild(c);
              return page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
            });
          });
        })(i);
      }
    }).catch(function () {});
  }
  var canvas = document.getElementById('sig');
  if (!canvas) return;
  var ctx = canvas.getContext('2d'), drawing = false, empty = true, last = null;
  var agree = document.getElementById('agree'), submit = document.getElementById('submit'), hint = document.getElementById('hint');
  function size() {
    var r = canvas.getBoundingClientRect(), d = window.devicePixelRatio || 1;
    canvas.width = r.width * d; canvas.height = r.height * d;
    ctx.setTransform(d, 0, 0, d, 0, 0); ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0B1B3A';
    empty = true; hint.style.display = ''; refresh();
  }
  function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  function refresh() { submit.disabled = empty || !agree.checked; }
  canvas.addEventListener('pointerdown', function (e) { drawing = true; last = pos(e); canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last.x, last.y, 1.2, 0, 7); ctx.fill(); e.preventDefault(); });
  canvas.addEventListener('pointermove', function (e) {
    if (!drawing) return; var p = pos(e);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p;
    if (empty) { empty = false; hint.style.display = 'none'; refresh(); }
    e.preventDefault();
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { canvas.addEventListener(t, function () { drawing = false; }); });
  document.getElementById('clear').addEventListener('click', size);
  agree.addEventListener('change', refresh);
  document.getElementById('signForm').addEventListener('submit', function (e) {
    if (empty) { e.preventDefault(); return; }
    var out = document.createElement('canvas'); out.width = canvas.width; out.height = canvas.height;
    var o = out.getContext('2d'); o.fillStyle = '#fff'; o.fillRect(0, 0, out.width, out.height); o.drawImage(canvas, 0, 0);
    document.getElementById('signature').value = out.toDataURL('image/png');
    submit.disabled = true; submit.textContent = 'Signature en cours…';
  });
  window.addEventListener('resize', function () { if (empty) size(); });
  size();
})();
</script>
@endsection
