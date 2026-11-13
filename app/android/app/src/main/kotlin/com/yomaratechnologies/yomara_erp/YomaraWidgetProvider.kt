package com.yomaratechnologies.yomara_erp

import android.appwidget.AppWidgetManager
import android.content.Context
import android.content.SharedPreferences
import android.view.View
import android.widget.RemoteViews
import es.antonborri.home_widget.HomeWidgetLaunchIntent
import es.antonborri.home_widget.HomeWidgetProvider

/**
 * Widget « Yomara ERP » : ventes du jour, reste à encaisser, factures en retard.
 * Les chiffres sont calculés par l'application à chaque synchronisation (lisibles sans Internet).
 */
class YomaraWidgetProvider : HomeWidgetProvider() {
    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        widgetData: SharedPreferences,
    ) {
        for (id in appWidgetIds) {
            val views = RemoteViews(context.packageName, R.layout.yomara_widget)
            val allowed = widgetData.getString("allowed", "false") == "true"
            val company = widgetData.getString("company", "") ?: ""
            views.setTextViewText(R.id.widget_company, if (company.isEmpty()) "Yomara ERP" else company)
            views.setTextViewText(R.id.widget_sales, widgetData.getString("sales_today", "—"))
            val n = widgetData.getString("invoices_today", "0") ?: "0"
            views.setTextViewText(R.id.widget_sales_label, if (n == "1") "Ventes du jour · 1 facture" else "Ventes du jour · $n factures")
            views.setTextViewText(R.id.widget_collect, widgetData.getString("to_collect", "—"))
            val overdue = widgetData.getString("overdue", "0") ?: "0"
            views.setTextViewText(R.id.widget_overdue, overdue)
            views.setTextViewText(R.id.widget_updated, "Mis à jour à " + (widgetData.getString("updated", "--:--") ?: "--:--"))
            views.setViewVisibility(R.id.widget_figures, if (allowed) View.VISIBLE else View.GONE)
            views.setViewVisibility(R.id.widget_open_hint, if (allowed) View.GONE else View.VISIBLE)
            // Toucher le widget ouvre l'application
            views.setOnClickPendingIntent(R.id.widget_root, HomeWidgetLaunchIntent.getActivity(context, MainActivity::class.java))
            appWidgetManager.updateAppWidget(id, views)
        }
    }
}
