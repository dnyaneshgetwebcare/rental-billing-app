<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Shared\AmountInWords;
use App\Shared\Settings;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * GST tax invoice / credit note PDF. "Rs." is used instead of the rupee sign so the built-in fonts work.
 */
final class InvoicePdf
{
    private const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
        '97' => 'Other Territory',
    ];

    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $doc invoice or credit note detail (with lines and tenant)
     */
    public function render(array $company, array $doc, bool $creditNote = false): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $fontCache = dirname(__DIR__, 2) . '/runtime/font-cache';
        if (!is_dir($fontCache)) {
            mkdir($fontCache, 0775, true);
        }
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setChroot([Settings::uploadDir(), dirname(__DIR__, 2), $fontCache]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($company, $doc, $creditNote), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    private static function periodLabel(string $period, bool $both): string
    {
        $ts = strtotime($period . '-01');
        $en = $ts === false ? $period : date('F Y', $ts);
        if (!$both || $ts === false) {
            return $en;
        }
        $months = [1 => 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
        return $en . ' / ' . ($months[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }

    private function fontFace(bool $both): string
    {
        if (!$both) {
            return '';
        }
        $path = dirname(__DIR__, 2) . '/resources/fonts/NotoSansDevanagari-Regular.ttf';
        if (!is_file($path)) {
            return '';
        }
        $url = str_replace('\\', '/', $path);
        return '<style>@font-face{font-family:NotoDev;src:url("' . $url . '") format("truetype");}</style>';
    }

    public static function stateName(?string $code): string
    {
        return $code === null ? '' : (self::STATES[$code] ?? '') . ' (' . $code . ')';
    }

    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $doc
     */
    public function html(array $company, array $doc, bool $creditNote = false): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $m = static fn(mixed $v): string => number_format((float) $v, 2);
        $tenant = $doc['tenant'] ?? [];
        $inter = (bool) $doc['is_inter_state'];
        $both = ($company['invoice_print_language'] ?? 'en') === 'en_mr';
        $label = static function (string $en, string $mr) use ($both): string {
            $en = htmlspecialchars($en, ENT_QUOTES, 'UTF-8');
            if (!$both) {
                return $en;
            }
            return $en . '<div class="mr">' . htmlspecialchars($mr, ENT_QUOTES, 'UTF-8') . '</div>';
        };
        $title = $label($creditNote ? 'CREDIT NOTE' : 'TAX INVOICE', $creditNote ? 'क्रेडिट नोट' : 'कर बीजक');
        $date = $creditNote ? $doc['note_date'] : $doc['invoice_date'];

        $logo = '';
        if (!empty($company['logo_path'])) {
            $path = Settings::uploadDir() . '/' . ltrim((string) $company['logo_path'], '/');
            if (is_file($path)) {
                $logo = '<img src="' . $e($path) . '" style="max-height:60px;max-width:160px">';
            }
        }

        $rows = '';
        foreach ($doc['lines'] as $i => $l) {
            $tax = $inter
                ? '<td class="r">' . $m($l['gst_rate']) . '%</td><td class="r">' . $m($l['igst']) . '</td>'
                : '<td class="r">' . $m((float) $l['gst_rate'] / 2) . '%</td><td class="r">' . $m($l['cgst']) . '</td>'
                  . '<td class="r">' . $m((float) $l['gst_rate'] / 2) . '%</td><td class="r">' . $m($l['sgst']) . '</td>';
            $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($l['description']) . '</td><td>' . $e($l['sac'])
                . '</td><td class="r">' . rtrim(rtrim((string) $l['qty'], '0'), '.') . '</td><td class="r">' . $m($l['rate'])
                . '</td><td class="r">' . $m($l['taxable_amount']) . '</td>' . $tax . '<td class="r">' . $m($l['total']) . '</td></tr>';
        }
        $taxHead = $inter
            ? '<th>' . $label('IGST %', 'आयजीएसटी %') . '</th><th>' . $label('IGST', 'आयजीएसटी') . '</th>'
            : '<th>' . $label('CGST %', 'सीजीएसटी %') . '</th><th>' . $label('CGST', 'सीजीएसटी') . '</th><th>' . $label('SGST %', 'एसजीएसटी %') . '</th><th>' . $label('SGST', 'एसजीएसटी') . '</th>';
        $taxTotals = $inter
            ? '<tr><td>' . $label('IGST', 'आयजीएसटी') . '</td><td class="r">' . $m($doc['igst']) . '</td></tr>'
            : '<tr><td>' . $label('CGST', 'सीजीएसटी') . '</td><td class="r">' . $m($doc['cgst']) . '</td></tr><tr><td>' . $label('SGST', 'एसजीएसटी') . '</td><td class="r">' . $m($doc['sgst']) . '</td></tr>';

        $ref = '';
        if ($creditNote && isset($doc['invoice'])) {
            $ref = '<div>' . $label('Against invoice', 'बीजकाविरुद्ध') . ': <b>' . $e($doc['invoice']['number']) . '</b> ' . $label('dated', 'दिनांक') . ' ' . $e($doc['invoice']['invoice_date']) . '</div>'
                . '<div>' . $label('Reason', 'कारण') . ': ' . $e($doc['reason']) . '</div>';
        } elseif (!$creditNote) {
            if (!empty($doc['period_label'])) {
                $ref .= '<div>' . $label('Rent period', 'भाडे कालावधी') . ': <b>' . $e(self::periodLabel((string) $doc['period_label'], $both)) . '</b></div>';
            }
            if (!empty($doc['unit'])) {
                $ref .= '<div>' . $label('Premises', 'जागा') . ': ' . $e($doc['unit']['warehouse_name']) . ', ' . $label('Unit', 'युनिट') . ' ' . $e($doc['unit']['code'])
                    . (!empty($doc['unit']['area_sqft']) ? ' (' . $e($doc['unit']['area_sqft']) . ' ' . $label('sq ft', 'चौ. फूट') . ')' : '') . '</div>';
            }
            if (!empty($doc['due_date'])) {
                $ref .= '<div>' . $label('Due date', 'देय तारीख') . ': <b>' . $e($doc['due_date']) . '</b></div>';
            }
        }
        $cols = $inter ? 9 : 11;

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 24px 28px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #222; }
  h1 { font-size: 16px; margin: 0; letter-spacing: 1px; }
  table { border-collapse: collapse; width: 100%; }
  .grid td { vertical-align: top; padding: 4px 6px; }
  .items th { background: #f0f3f8; border: 1px solid #ccd; padding: 4px; font-size: 9px; }
  .items td { border: 1px solid #ccd; padding: 4px; }
  .r { text-align: right; }
  .muted { color: #666; }
  .mr { font-family: 'NotoDev', 'DejaVu Sans', sans-serif; font-size: 8px; color: #555; font-weight: normal; line-height: 1.25; }
  .box { border: 1px solid #ccd; }
  .totals td { padding: 3px 6px; }
  .grand td { font-weight: bold; font-size: 12px; border-top: 1px solid #333; }
</style></head>
<body>
{$this->fontFace($both)}
<table class="grid"><tr>
  <td style="width:60%">{$logo}
    <div style="font-size:14px;font-weight:bold">{$e($company['legal_name'] ?: $company['name'])}</div>
    <div>{$e($company['address'])} {$e($company['city'])} {$e($company['pincode'])}</div>
    <div>{$label('GSTIN', 'जीएसटीआयएन')}: <b>{$e($company['gstin'])}</b> &nbsp; {$label('State', 'राज्य')}: {$e(self::stateName($company['state_code']))}</div>
    <div class="muted">{$e($company['phone'])} {$e($company['email'])}</div>
  </td>
  <td style="width:40%" class="r">
    <h1>{$title}</h1>
    <div>{$label('No.', 'क्रमांक')}: <b>{$e($doc['number'])}</b></div>
    <div>{$label('Date', 'दिनांक')}: <b>{$e($date)}</b></div>
    <div>{$label('Place of supply', 'पुरवठ्याचे ठिकाण')}: {$e(self::stateName($doc['place_of_supply']))}</div>
    <div class="muted">{$label('Reverse charge', 'रिव्हर्स चार्ज')}: {$label('No', 'नाही')}</div>
  </td>
</tr></table>

<table class="grid box" style="margin-top:8px"><tr>
  <td style="width:55%">
    <div class="muted">{$label('Bill to', 'बिल प्राप्तकर्ता')}</div>
    <div style="font-weight:bold;font-size:12px">{$e($tenant['name'] ?? '')}</div>
    <div>{$e($tenant['address'] ?? '')}</div>
    <div>{$label('GSTIN', 'जीएसटीआयएन')}: {$e(($tenant['gstin'] ?? '') ?: ($both ? 'Unregistered / नोंदणीकृत नाही' : 'Unregistered'))} &nbsp; {$label('State', 'राज्य')}: {$e(self::stateName($tenant['state_code'] ?? null))}</div>
  </td>
  <td style="width:45%">{$ref}</td>
</tr></table>

<table class="items" style="margin-top:8px">
  <thead><tr><th>#</th><th>{$label('Description', 'तपशील')}</th><th>{$label('SAC', 'एसएसी')}</th><th>{$label('Qty', 'प्रमाण')}</th><th>{$label('Rate', 'दर')}</th><th>{$label('Taxable', 'करपात्र')}</th>{$taxHead}<th>{$label('Total', 'एकूण')}</th></tr></thead>
  <tbody>{$rows}</tbody>
  <tfoot><tr><td colspan="{$cols}" class="r muted">Amounts in Rs.</td></tr></tfoot>
</table>

<table style="margin-top:8px"><tr>
  <td style="width:58%;vertical-align:top">
    <div><b>{$label('Amount in words', 'अक्षरी रक्कम')}:</b> {$e(AmountInWords::rupees((string) $doc['total']))}</div>
    <div style="margin-top:8px;white-space:pre-line"><b>{$label('Bank details', 'बँक तपशील')}</b><br>{$e($company['bank_details'])}</div>
    <div style="margin-top:8px;white-space:pre-line" class="muted">{$e($company['invoice_terms'])}</div>
  </td>
  <td style="width:42%;vertical-align:top">
    <table class="totals">
      <tr><td>{$label('Taxable value', 'करपात्र रक्कम')}</td><td class="r">{$m($doc['taxable_amount'])}</td></tr>
      {$taxTotals}
      <tr><td>{$label('Round off', 'राउंड ऑफ')}</td><td class="r">{$m($doc['round_off'])}</td></tr>
      <tr class="grand"><td>{$label('Total (Rs.)', 'एकूण (रु.)')}</td><td class="r">{$m($doc['total'])}</td></tr>
    </table>
    <div style="margin-top:40px" class="r">{$label('For', 'साठी')} {$e($company['legal_name'] ?: $company['name'])}<br><br><br>{$label('Authorised signatory', 'अधिकृत सही')}</div>
  </td>
</tr></table>
<div class="muted" style="margin-top:12px;text-align:center">{$label('This is a computer-generated document.', 'हा संगणकावर तयार केलेला दस्तऐवज आहे.')}</div>
</body></html>
HTML;
    }
}
