@push('head')
<style>
.qty{display:inline-flex;align-items:center;border:1px solid color-mix(in srgb,var(--d) 16%,transparent);border-radius:999px;overflow:hidden}
.qty button{width:34px;height:34px;border:0;background:#fff;font-size:1.1rem;cursor:pointer;color:var(--d)}.qty span{min-width:26px;text-align:center;font-weight:700}
.add{flex:1;justify-content:center;padding:10px 14px}
.drawer{position:fixed;inset:0;z-index:80;display:none}.drawer.on{display:block}
.drawer .bg{position:absolute;inset:0;background:rgba(10,15,30,.45);animation:fd .25s}
.drawer .pn{position:absolute;right:0;top:0;bottom:0;width:min(440px,100%);background:#fff;display:flex;flex-direction:column;animation:sl .3s cubic-bezier(.2,.8,.2,1);box-shadow:-20px 0 40px -20px rgba(0,0,0,.4)}
@keyframes sl{from{transform:translateX(40px);opacity:.4}to{transform:none;opacity:1}}@keyframes fd{from{opacity:0}}
.pn header{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid var(--line)}.pn header h3{margin:0}
.pn .bd{flex:1;overflow:auto;padding:16px 20px}.pn footer{padding:16px 20px;border-top:1px solid var(--line);display:grid;gap:10px}
.ci{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.ci .nm{flex:1;font-weight:600}.ci .pr{font-weight:700;white-space:nowrap}
.row2{display:flex;justify-content:space-between;gap:12px}.tot{font-size:1.25rem;font-weight:800;color:var(--d)}
.opt{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--line);border-radius:calc(var(--r) * .6 + 4px);cursor:pointer;margin-bottom:8px}.opt input{margin-top:4px}
.opt input{width:auto;flex:none;margin-top:5px}.opt>span{flex:1}
.opt:has(input:checked){border-color:var(--p);background:color-mix(in srgb,var(--p) 6%,#fff)}
.x{border:0;background:none;font-size:1.6rem;cursor:pointer;line-height:1;color:var(--mut)}
.empty{text-align:center;color:var(--mut);padding:40px 10px}
.fab{position:fixed;right:20px;bottom:20px;z-index:60;box-shadow:0 14px 30px -10px rgba(0,0,0,.45)}
</style>
@endpush
<button class="btn fab" type="button" onclick="openCart()" id="fab" style="display:none">{!! $ic('bag', 18) !!} Panier · <span id="fabTot">0</span></button>
<div class="drawer" id="drawer"><div class="bg" onclick="closeCart()"></div><div class="pn">
  <header><h3 id="dTitle">Votre panier</h3><button class="x" type="button" onclick="closeCart()" aria-label="Fermer">×</button></header>
  <div class="bd" id="cartView"></div>
  <form class="bd f" id="checkout" method="post" action="{{ $orderAction }}" style="display:none" onsubmit="return submitOrder()">
    <input class="hp" name="website" tabindex="-1" autocomplete="off"><input type="hidden" name="cart" id="cartField"><input type="hidden" name="cart_token" id="cartToken"><input type="hidden" name="coupon" id="couponField">
    <div><label for="o-name">Nom et prénom</label><input id="o-name" name="name" required maxlength="160" autocomplete="name"></div>
    <div class="two"><div><label for="o-phone">Téléphone (WhatsApp)</label><input id="o-phone" name="phone" required maxlength="40" inputmode="tel" autocomplete="tel"></div><div><label for="o-email">E-mail (facultatif)</label><input id="o-email" name="email" type="email" maxlength="190" autocomplete="email"></div></div>
    <div><label>Réception</label>
      @if($shop['pickup'])<label class="opt"><input type="radio" name="delivery" value="pickup" onchange="upd()" checked><span><b>Retrait en boutique</b> — gratuit<br><span class="muted" style="font-size:.88rem">{{ $shop['pickup_address'] ?: $address }}</span></span></label>@endif
      @foreach($shop['zones'] as $i => $z)<label class="opt"><input type="radio" name="delivery" value="{{ $z['name'] }}" data-fee="{{ $z['fee'] }}" onchange="upd()" @checked(! $shop['pickup'] && $i === 0)><span><b>{{ $z['name'] }}</b> — {{ $z['fee'] > 0 ? number_format($z['fee'], 0, ',', ' ').' '.$currency : 'gratuit' }}</span></label>@endforeach
      @if($shop['free_from'])<div class="muted" style="font-size:.88rem">Livraison offerte dès {{ number_format($shop['free_from'], 0, ',', ' ') }} {{ $currency }}.</div>@endif
    </div>
    <div id="addrBox"><label for="o-addr">Adresse de livraison</label><textarea id="o-addr" name="address" rows="2" maxlength="300" placeholder="Commune, quartier, repère…"></textarea></div>
    <div><label>Paiement</label>
      @if($online)<label class="opt"><input type="radio" name="payment" value="online" checked><span><b>Payer maintenant</b><br><span class="muted" style="font-size:.88rem">Mobile Money ou carte, paiement sécurisé</span></span></label>@endif
      @if($shop['payment_cod'])<label class="opt"><input type="radio" name="payment" value="cod" @checked(! $online)><span><b>Payer à la livraison</b><br><span class="muted" style="font-size:.88rem">Espèces ou Mobile Money à la réception</span></span></label>@endif
      @if($shop['payment_momo'])<label class="opt"><input type="radio" name="payment" value="momo" @checked(! $online && ! $shop['payment_cod'])><span><b>Transfert Mobile Money</b>@if($shop['momo_instructions'])<br><span class="muted" style="font-size:.88rem">{{ $shop['momo_instructions'] }}</span>@endif</span></label>@endif
    </div>
    @if($hasCoupons)<div><label for="o-coupon">Code promo</label><div style="display:flex;gap:8px"><input id="o-coupon" maxlength="40" style="text-transform:uppercase" placeholder="Ex. : BIENVENUE"><button class="btn alt" type="button" style="color:var(--p);padding:10px 16px" onclick="applyCoupon()">Appliquer</button></div><div id="couponMsg" style="font-size:.88rem;margin-top:6px"></div></div>@endif
    <div><label for="o-note">Précisions (facultatif)</label><textarea id="o-note" name="note" rows="2" maxlength="1000"></textarea></div>
  </form>
  <footer>
    <div class="row2"><span class="muted">Sous-total</span><b id="sub">0</b></div>
    <div class="row2" id="discRow" style="display:none;color:#0B5E31"><span id="discLbl">Remise</span><b id="disc">0</b></div>
    <div class="row2" id="feeRow" style="display:none"><span class="muted">Livraison</span><b id="fee">0</b></div>
    <div class="row2"><span>Total</span><span class="tot" id="tot">0</span></div>
    <div class="muted" id="minMsg" style="font-size:.88rem;display:none"></div>
    <button class="btn" type="button" id="goBtn" onclick="step()" style="justify-content:center">Commander {!! $ic('arrow', 18) !!}</button>
  </footer>
</div></div>
@push('scripts')
<script>
var RESTORE=@json($restore),CPN=null,CURL=@json($couponUrl),SAVEURL=@json($cartUrl);
var P={!! json_encode((object) $buyables, JSON_HEX_TAG | JSON_HEX_AMP) !!},CUR=@json(' '.$currency),MIN={{ (float) $shop['min_order'] }},FREE={{ (float) ($shop['free_from'] ?? 0) }},KEY='cart-{{ $site->slug }}',cart={},stage=0;
try{cart=JSON.parse(localStorage.getItem(KEY)||'{}')||{}}catch(e){cart={}}
if(RESTORE){cart=RESTORE.items||{};try{localStorage.setItem(KEY+'-token',RESTORE.token)}catch(e){}}
for(var k in cart){if(!P[k])delete cart[k]}
function tok(){try{return localStorage.getItem(KEY+'-token')||''}catch(e){return ''}}
function disc(){if(!CPN||CPN.free_shipping)return 0;return Math.min(sub(),CPN.discount)}
function applyCoupon(){var c=document.getElementById('o-coupon').value.trim(),m=document.getElementById('couponMsg');if(!c){CPN=null;document.getElementById('couponField').value='';upd();return}
  fetch(CURL+'?'+new URLSearchParams({code:c,total:sub(),phone:document.getElementById('o-phone').value,email:document.getElementById('o-email').value})).then(function(r){return r.json()}).then(function(j){
    if(j.ok){CPN=j;document.getElementById('couponField').value=j.code;m.style.color='#0B5E31';m.textContent='Code '+j.code+' appliqué : '+j.label}else{CPN=null;document.getElementById('couponField').value='';m.style.color='#B42318';m.textContent=j.message}upd()}).catch(function(){})}
function saveCart(){var ph=document.getElementById('o-phone').value,em=document.getElementById('o-email').value;if(ph.replace(/\D/g,'').length<8&&em.indexOf('@')<0)return;var items=[];for(var k in cart)items.push({id:k,quantity:cart[k]});
  fetch(SAVEURL,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({token:tok(),name:document.getElementById('o-name').value,phone:ph,email:em,items:items})}).then(function(r){return r.json()}).then(function(j){if(j.ok&&j.token){try{localStorage.setItem(KEY+'-token',j.token)}catch(e){}}}).catch(function(){})}
function fmt(n){return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')+CUR}
function save(){try{localStorage.setItem(KEY,JSON.stringify(cart))}catch(e){}upd()}
function add(id,n,open){n=n||1;if(!P[id])return;var q=(cart[id]||0)+n;if(P[id].s!==null&&q>P[id].s){alert('Stock disponible : '+P[id].s);return}cart[id]=q;save();if(open)openCart();var b=document.getElementById('fab');b.animate([{transform:'scale(1)'},{transform:'scale(1.12)'},{transform:'scale(1)'}],{duration:300})}
function setq(id,d){var q=(cart[id]||0)+d;if(P[id].s!==null&&q>P[id].s)return;if(q<=0)delete cart[id];else cart[id]=q;save()}
function sub(){var s=0;for(var k in cart)s+=P[k].p*cart[k];return s}
function fee(){var r=document.querySelector('input[name=delivery]:checked');if(!r||r.value==='pickup')return 0;var f=+r.dataset.fee||0;return FREE&&sub()>=FREE?0:f}
function upd(){var n=0;for(var k in cart)n+=cart[k];var c=document.getElementById('cartCount');if(c)c.textContent=n;
  var fb=document.getElementById('fab');fb.style.display=n?'inline-flex':'none';document.getElementById('fabTot').textContent=fmt(sub());
  var h='';for(var k in cart){h+='<div class="ci"><span class="nm">'+P[k].n.replace(/</g,'&lt;')+'</span><span class="qty"><button type="button" onclick="setq(\''+k+'\',-1)">−</button><span>'+cart[k]+'</span><button type="button" onclick="setq(\''+k+'\',1)">+</button></span><span class="pr">'+fmt(P[k].p*cart[k])+'</span></div>'}
  document.getElementById('cartView').innerHTML=h||'<div class="empty">Votre panier est vide.</div>';
  var r=document.querySelector('input[name=delivery]:checked'),zone=r&&r.value!=='pickup';document.getElementById('addrBox').style.display=zone?'block':'none';document.getElementById('o-addr').required=!!zone;
  var f=stage?fee():0;if(stage&&CPN&&CPN.free_shipping)f=0;var dsc=stage?disc():0;document.getElementById('discRow').style.display=dsc||(stage&&CPN&&CPN.free_shipping)?'flex':'none';document.getElementById('disc').textContent=dsc?'−'+fmt(dsc):'livraison offerte';if(CPN)document.getElementById('discLbl').textContent='Code '+CPN.code;
  document.getElementById('sub').textContent=fmt(sub());document.getElementById('fee').textContent=f?fmt(f):'offerte';document.getElementById('feeRow').style.display=stage&&r&&zone?'flex':'none';
  document.getElementById('tot').textContent=fmt(sub()-dsc+f);var low=MIN&&sub()<MIN;var m=document.getElementById('minMsg');m.style.display=low?'block':'none';m.textContent=low?'Commande minimum : '+fmt(MIN):'';
  document.getElementById('goBtn').disabled=!n||low;document.getElementById('goBtn').style.opacity=(!n||low)?.5:1}
function openCart(){document.getElementById('drawer').classList.add('on');upd()}
function closeCart(){document.getElementById('drawer').classList.remove('on');stage=0;document.getElementById('cartView').style.display='block';document.getElementById('checkout').style.display='none';document.getElementById('dTitle').textContent='Votre panier';document.getElementById('goBtn').innerHTML='Commander →';upd()}
function step(){if(!stage){stage=1;document.getElementById('cartView').style.display='none';document.getElementById('checkout').style.display='grid';document.getElementById('dTitle').textContent='Vos coordonnées';document.getElementById('goBtn').innerHTML='Valider la commande';upd();return}
  var f=document.getElementById('checkout');if(f.reportValidity()){if(submitOrder())f.submit()}}
function submitOrder(){var items=[];for(var k in cart)items.push({id:k,quantity:cart[k]});document.getElementById('cartField').value=JSON.stringify(items);document.getElementById('cartToken').value=tok();return true}
['o-phone','o-email'].forEach(function(id){document.getElementById(id).addEventListener('change',saveCart)});
if(RESTORE){['name','phone','email'].forEach(function(k){if(RESTORE[k])document.getElementById('o-'+k).value=RESTORE[k]});save();openCart()}
upd();
</script>
@endpush
