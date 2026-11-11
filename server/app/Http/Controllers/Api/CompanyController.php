<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $c = app('tenant')->load('plan');

        return ['data' => array_replace($c->toArray(), ['settings' => $c->settingsFor($request->user())])];
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'sometimes|string|size:2',
            'currency' => 'sometimes|string|size:3',
            'tax_id' => 'nullable|string|max:100',
            'trade_register' => 'nullable|string|max:100',
            'settings' => 'sometimes|array',
            'settings.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'settings.payment_terms_days' => 'sometimes|integer|min:0|max:365',
            'settings.purchase_approval_limit' => 'sometimes|numeric|min:0',
            'settings.purchase_receipt_tolerance' => 'sometimes|numeric|min:0|max:100',
            'settings.intervention_hourly_rate' => 'sometimes|numeric|min:0',
            'settings.intervention_travel_fee' => 'sometimes|numeric|min:0',
            'settings.rdv_enabled' => 'sometimes|boolean',
            'settings.rdv_slot_step' => 'sometimes|integer|in:5,10,15,20,30,45,60',
            'settings.rdv_min_notice_hours' => 'sometimes|numeric|min:0|max:720',
            'settings.rdv_max_days' => 'sometimes|integer|min:1|max:365',
            'settings.rdv_cancel_notice_hours' => 'sometimes|numeric|min:0|max:720',
            'settings.rdv_reminder_hours' => 'sometimes|numeric|min:0|max:168',
            'settings.rdv_auto_confirm' => 'sometimes|boolean',
            'settings.rdv_agenda_sync' => 'sometimes|boolean',
            'settings.rdv_agenda_busy' => 'sometimes|boolean',
            'settings.rdv_intro' => 'nullable|string|max:1000',
            'settings.mrp_labour_rate' => 'sometimes|numeric|min:0',
            'settings.fid_enabled' => 'sometimes|boolean',
            'settings.fid_per_amount' => 'sometimes|numeric|min:1',
            'settings.fid_point_value' => 'sometimes|numeric|min:0.01',
            'settings.fid_min_redeem' => 'sometimes|integer|min:0',
            'settings.fid_expiry_months' => 'sometimes|integer|min:0|max:120',
            'settings.fid_welcome_points' => 'sometimes|integer|min:0',
            'settings.fid_auto_enroll' => 'sometimes|boolean',
            'settings.fid_tiers' => 'sometimes|array|max:6',
            'settings.fid_tiers.*.name' => 'required|string|max:40',
            'settings.fid_tiers.*.min' => 'required|integer|min:0',
            'settings.fid_tiers.*.multiplier' => 'required|numeric|min:1|max:5',
            'settings.gift_card_months' => 'sometimes|integer|min:0|max:120',
            'settings.invoice_footer' => 'nullable|string|max:2000',
            'settings.vat_subject' => 'sometimes|boolean',
            'settings.tax_regime' => 'nullable|string|max:80',
            'settings.invoice_receipt_box' => 'sometimes|boolean',
            'settings.invoice_amount_words' => 'sometimes|boolean',
        ]);

        $company = app('tenant');
        if (isset($data['settings'])) {
            // Seuls les réglages prévus ici : les quotas de la plateforme et les réglages protégés par d'autres droits
            // (paie, clôture comptable, gestionnaires…) ne passent pas par cette route.
            $allowed = ['tax_rate', 'payment_terms_days', 'purchase_approval_limit', 'purchase_receipt_tolerance', 'intervention_hourly_rate', 'intervention_travel_fee',
                'rdv_enabled', 'rdv_slot_step', 'rdv_min_notice_hours', 'rdv_max_days', 'rdv_cancel_notice_hours', 'rdv_reminder_hours', 'rdv_auto_confirm', 'rdv_agenda_sync',
                'rdv_agenda_busy', 'rdv_intro', 'mrp_labour_rate', 'fid_enabled', 'fid_per_amount', 'fid_point_value', 'fid_min_redeem', 'fid_expiry_months', 'fid_welcome_points',
                'fid_auto_enroll', 'fid_tiers', 'gift_card_months', 'invoice_footer', 'vat_subject', 'tax_regime', 'invoice_receipt_box', 'invoice_amount_words'];
            $data['settings'] = array_merge($company->settings ?? [], \Illuminate\Support\Arr::only($data['settings'], $allowed));
        }
        $company->update($data);
        ActivityLog::record('company.updated', $company);

        return ['data' => $company->fresh()];
    }
}
