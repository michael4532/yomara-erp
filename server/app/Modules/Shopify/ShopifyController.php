<?php

namespace App\Modules\Shopify;

use App\Modules\Woocommerce\WooController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Connecteur Shopify : mêmes écrans et mêmes données que WooCommerce, filtrés sur les boutiques Shopify. */
class ShopifyController extends WooController
{
    protected string $platform = 'shopify';

    public function __construct(ShopifySync $sync)
    {
        parent::__construct($sync);
    }

    protected function productUrl(object $s, object $l): string
    {
        return $s->url.'/admin/products/'.$l->remote_parent_id;
    }

    protected function orderUrl(object $s, object $l): string
    {
        return $s->url.'/admin/orders/'.$l->remote_id;
    }

    public function setStatus(Request $r, int $id, int $linkId)
    {
        $r->validate(['status' => 'required|in:paid,processing,completed,cancelled']);

        return parent::setStatus($r->merge(['status' => $r->input('status') === 'paid' ? 'processing' : $r->input('status')]), $id, $linkId);
    }

    public function webhook(Request $r, string $token)
    {
        $s = DB::table('woo_stores')->where('token', $token)->where('platform', 'shopify')->first();
        if (! $s) {
            return response()->json(['ok' => false], 404);
        }
        try {
            $ok = $this->sync->webhook($s, $r->getContent(), $r->header('X-Shopify-Hmac-Sha256'), $r->header('X-Shopify-Topic'));
        } catch (\Throwable $e) {
            $this->sync->log($s, 'Webhook : '.$e->getMessage(), 'error');

            return response()->json(['ok' => false], 200);
        }

        return response()->json(['ok' => $ok], $ok ? 200 : 401);
    }
}
