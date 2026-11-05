<?php

use App\Http\Controllers\Api\EntityController;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\Product;
use App\Modules\Core\Models\ProductCategory;

EntityController::routes('partners', Partner::class);
EntityController::routes('product-categories', ProductCategory::class);
EntityController::routes('products', Product::class);
EntityController::routes('payment-terms', PaymentTerm::class);
