<?php

namespace App\Filament\Resources\Invoices;

use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Billing\Money;

/** Regels en totalen als tabel op de bekijkpagina. */
class LinesTable
{
    public static function html(Invoice|Quote $doc): string
    {
        $locale = app()->getLocale();
        $m = fn ($a) => e(Money::format($a, $doc->currency, $locale));
        $th = 'style="text-align:left;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.35);font-weight:600;font-size:13px"';
        $thr = 'style="text-align:right;padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.35);font-weight:600;font-size:13px"';
        $td = 'style="padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);vertical-align:top"';
        $tdr = 'style="padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);text-align:right;white-space:nowrap;vertical-align:top"';

        $html = '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr>'
            ."<th {$th}>".e(__('Omschrijving'))."</th><th {$thr}>".e(__('Aantal'))."</th><th {$thr}>".e(__('Prijs'))
            ."</th><th {$thr}>".e(__('Btw'))."</th><th {$thr}>".e(__('Bedrag')).'</th></tr></thead><tbody>';
        foreach ($doc->lines as $line) {
            $qty = Money::number($line->quantity, 3, $locale).($line->unit ? ' '.e(__($line->unit)) : '');
            $disc = (float) $line->discount_percent > 0 ? '<br><small>'.e(__('Korting')).' '.Money::number($line->discount_percent, 2, $locale).'%</small>' : '';
            $html .= "<tr><td {$td}>".nl2br(e($line->description)).$disc."</td><td {$tdr}>{$qty}</td><td {$tdr}>".$m($line->unit_price)
                ."</td><td {$tdr}>".e($line->vat_label ?? Money::number($line->vat_rate, 2, $locale).'%')."</td><td {$tdr}>".$m($line->line_total).'</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $rows = '<tr><td style="padding:3px 8px">'.e(__('Subtotaal'))."</td><td style=\"padding:3px 8px;text-align:right\">".$m($doc->subtotal).'</td></tr>';
        foreach ($doc->vatBreakdown() as $v) {
            $rows .= '<tr><td style="padding:3px 8px">'.e($v['label']).'</td><td style="padding:3px 8px;text-align:right">'.$m($v['vat']).'</td></tr>';
        }
        $rows .= '<tr><td style="padding:6px 8px;font-weight:700">'.e(__('Totaal')).'</td><td style="padding:6px 8px;text-align:right;font-weight:700">'.$m($doc->total).'</td></tr>';

        return $html.'<table style="margin:10px 0 0 auto;font-size:14px">'.$rows.'</table>';
    }
}
