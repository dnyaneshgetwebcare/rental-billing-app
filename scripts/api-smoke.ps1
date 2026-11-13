# End-to-end smoke test against the local Docker API (http://localhost:8082).
# Creates a demo company with tenants, units and an agreement, then bills, receives, posts and reports.
# Usage: powershell -File scripts/api-smoke.ps1 [-Base http://localhost:8082/api/v1] [-Email admin@example.com] [-Password admin12345]
param(
    [string]$Base = 'http://localhost:8082/api/v1',
    [string]$Email = 'admin@example.com',
    [string]$Password = 'admin12345'
)
$ErrorActionPreference = 'Stop'
$script:failures = 0
$script:tok = $null
$script:cid = $null

function Call([string]$Method, [string]$Path, $Body = $null, [switch]$AllowError) {
    $h = @{ Authorization = "Bearer $script:tok" }
    if ($script:cid) { $h['X-Company-Id'] = "$script:cid" }
    $json = if ($null -ne $Body) { $Body | ConvertTo-Json -Depth 10 } else { $null }
    try {
        $r = Invoke-RestMethod -Method $Method -Uri "$Base$Path" -Headers $h -ContentType 'application/json' -Body $json
        return $r.data
    } catch {
        $msg = $_.ErrorDetails.Message
        if ($AllowError) { return ($msg | ConvertFrom-Json) }
        Write-Host "FAIL $Method $Path -> $msg" -ForegroundColor Red
        $script:failures++
        return $null
    }
}

function Check([string]$Name, [bool]$Ok, $Detail = '') {
    if ($Ok) { Write-Host "ok   $Name" -ForegroundColor Green }
    else { Write-Host "FAIL $Name $Detail" -ForegroundColor Red; $script:failures++ }
}

$login = Invoke-RestMethod -Method Post -Uri "$Base/auth/login" -ContentType 'application/json' -Body (@{ email = $Email; password = $Password } | ConvertTo-Json)
$script:tok = $login.data.access_token
Check 'login' ($null -ne $script:tok)

$stamp = Get-Date -Format 'HHmmss'
$company = Call Post '/companies' @{ name = "Smoke $stamp"; gstin = '27AABCA1234F1Z5'; invoice_prefix = 'SMK'; bank_details = 'HDFC 123'; address = 'Bhiwandi' }
$script:cid = $company.id
Check 'company created' ($company.state_code -eq '27')

$wh = Call Post '/warehouses' @{ name = 'Bhiwandi WH-1'; state_code = '27'; city = 'Bhiwandi' }
$unit = Call Post '/units' @{ warehouse_id = $wh.id; code = 'A-101'; area_sqft = 5000 }
$unit2 = Call Post '/units' @{ warehouse_id = $wh.id; code = 'A-102'; area_sqft = 3000 }
$local = Call Post '/tenants' @{ name = 'Local Traders'; gstin = '27AAACL1234A1Z1'; phone = '9876543210' }
$outside = Call Post '/tenants' @{ name = 'Gujarat Movers'; gstin = '24AAACG1234A1Z1'; whatsapp = '9123456780' }
Check 'masters created' ($wh.id -and $unit.id -and $local.id -and $outside.id)

$start = (Get-Date).AddMonths(-13).ToString('yyyy-MM-01')
$ag = Call Post '/agreements' @{ tenant_id = $outside.id; unit_id = $unit.id; start_date = $start; rent_amount = '50000'; billing_day = 1; escalation_pct = '5'; deposit_amount = '150000'; charges = @(@{ description = 'Maintenance'; amount = '2500' }) }
Check 'agreement created with escalation' ($ag.current_rent -eq '52500.00') $ag.current_rent
$dup = Call Post '/agreements' @{ tenant_id = $local.id; unit_id = $unit.id; start_date = $start; rent_amount = '1000' } -AllowError
Check 'unit cannot be let twice' ($dup.error.fields.unit_id.Count -gt 0)

# Manual intra-state invoice
$inv = Call Post '/invoices' @{ tenant_id = $local.id; lines = @(@{ description = 'Storage charges'; qty = '1'; rate = '25000.50'; gst_rate = '18' }) }
Check 'manual invoice CGST/SGST' ($inv.cgst -eq '2250.05' -and $inv.sgst -eq '2250.05' -and $inv.igst -eq '0.00') "$($inv.cgst) $($inv.sgst) $($inv.igst)"
Check 'invoice round-off to rupee' ($inv.total -eq '29501.00' -and $inv.round_off -eq '0.40') "$($inv.total) $($inv.round_off)"
Check 'invoice number format' ($inv.number -match '^SMK/\d\d-\d\d/0001$') $inv.number

# Out-of-state tenant, property in Maharashtra: place of supply = property state -> CGST/SGST
$inv2 = Call Post '/invoices' @{ tenant_id = $outside.id; agreement_id = $ag.id; lines = @(@{ description = 'Extra storage'; rate = '1000'; gst_rate = '18' }) }
Check 'rent POS is property state' ($inv2.place_of_supply -eq '27' -and $inv2.igst -eq '0.00') "$($inv2.place_of_supply) igst=$($inv2.igst)"
# No agreement, out-of-state tenant -> IGST
$inv3 = Call Post '/invoices' @{ tenant_id = $outside.id; lines = @(@{ description = 'Handling'; rate = '1000'; gst_rate = '18' }) }
Check 'IGST for other state' ($inv3.igst -eq '180.00' -and $inv3.cgst -eq '0.00') "igst=$($inv3.igst)"

$bad = Call Post '/invoices' @{ tenant_id = $local.id; lines = @(@{ description = ''; rate = 'abc' }) } -AllowError
Check 'invoice line validation' ($bad.error.fields.'lines.0.rate'.Count -gt 0)

# Share link + public PDF
$share = Call Post "/invoices/$($inv.id)/share-link"
Check 'share link' ($share.url -match '/api/share/invoice/' -and $share.whatsapp_url -match '^https://wa.me/') $share.url
$pdf = Invoke-WebRequest -Uri ($share.url -replace '^https?://[^/]+', 'http://localhost:8082') -UseBasicParsing
Check 'public PDF' ($pdf.Headers['Content-Type'] -eq 'application/pdf' -and $pdf.RawContentLength -gt 1000)
$tampered = try { Invoke-WebRequest -Uri (($share.url -replace '^https?://[^/]+', 'http://localhost:8082') + 'x') -UseBasicParsing; 200 } catch { $_.Exception.Response.StatusCode.value__ }
Check 'tampered link rejected' ($tampered -eq 410) $tampered

# Auto billing (current month + last month catch-up)
$upcoming = Call Get '/agreements/upcoming-bills'
Check 'upcoming bills' ($upcoming.due_now.Count -ge 1)
$run = Call Post '/agreements/run-billing'
Check 'auto billing created 2 periods' ($run.created.Count -eq 2) ($run | ConvertTo-Json -Depth 4 -Compress)
$run2 = Call Post '/agreements/run-billing'
Check 'auto billing is idempotent' ($run2.created.Count -eq 0)

# Receipt allocated to the first invoice
$cash = (Call Get '/accounts?per_page=200').items | Where-Object { $_.system_key -eq 'BANK' }
$rcpt = Call Post '/receipts' @{ tenant_id = $local.id; amount = '10000'; mode = 'upi'; account_id = $cash.id; allocations = @(@{ invoice_id = $inv.id; amount = '10000' }) }
$invAfter = Call Get "/invoices/$($inv.id)"
Check 'receipt makes invoice partial' ($invAfter.status -eq 'partial' -and $invAfter.balance -eq '19501.00') "$($invAfter.status) $($invAfter.balance)"
$cancelBlocked = Call Post "/invoices/$($inv.id)/cancel" @{ reason = 'test' } -AllowError
Check 'cannot cancel paid invoice' ($cancelBlocked.error.code -eq 'conflict')

# Credit note on the IGST invoice
$cn = Call Post '/credit-notes' @{ invoice_id = $inv3.id; reason = 'Discount'; lines = @(@{ description = 'Discount'; rate = '100'; gst_rate = '18' }) }
Check 'credit note IGST' ($cn.igst -eq '18.00' -and $cn.total -eq '118.00') "$($cn.igst) $($cn.total)"

# Vendor purchase + payment + expense
$vendor = Call Post '/vendors' @{ name = 'Power Co'; state_code = '27' }
$elec = (Call Get '/accounts?q=Electricity').items[0]
$pur = Call Post '/purchases' @{ vendor_id = $vendor.id; bill_no = "E-$stamp"; bill_date = (Get-Date).ToString('yyyy-MM-dd'); lines = @(@{ account_id = $elec.id; description = 'Electricity Oct'; rate = '10000'; gst_rate = '18' }) }
Check 'purchase input GST' ($pur.cgst -eq '900.00' -and $pur.total -eq '11800.00') "$($pur.cgst) $($pur.total)"
$pay = Call Post '/vendor-payments' @{ vendor_id = $vendor.id; purchase_id = $pur.id; amount = '11800'; account_id = $cash.id; mode = 'bank' }
$purAfter = Call Get "/purchases/$($pur.id)"
Check 'vendor payment marks purchase paid' ($purAfter.status -eq 'paid') $purAfter.status
$cashAcc = (Call Get '/accounts?per_page=200').items | Where-Object { $_.system_key -eq 'CASH' }
$exp = Call Post '/expenses' @{ expense_date = (Get-Date).ToString('yyyy-MM-dd'); account_id = $elec.id; paid_from_account_id = $cashAcc.id; description = 'Diesel for genset'; amount = '2000'; gst_rate = '0' }
Check 'expense' ($exp.total -eq '2000.00')

# Opening balance journal
$capital = (Call Get '/accounts?per_page=200').items | Where-Object { $_.system_key -eq 'CAPITAL' }
$jv = Call Post '/vouchers' @{ voucher_date = (Get-Date).ToString('yyyy-MM-dd'); narration = 'Opening bank balance'; lines = @(@{ account_id = $cash.id; debit = '100000' }, @{ account_id = $capital.id; credit = '100000' }) }
Check 'journal voucher' ($jv.id -gt 0)
$unbalanced = Call Post '/vouchers' @{ voucher_date = (Get-Date).ToString('yyyy-MM-dd'); narration = 'x'; lines = @(@{ account_id = $cash.id; debit = '10' }, @{ account_id = $capital.id; credit = '9' }) } -AllowError
Check 'unbalanced journal rejected' ($unbalanced.error.code -eq 'validation_failed')

# Reports
$tb = Call Get '/reports/trial-balance'
Check 'trial balance balances' ($tb.total_debit -eq $tb.total_credit -and [decimal]$tb.total_debit -gt 0) "$($tb.total_debit) / $($tb.total_credit)"
$debtors = (Call Get '/accounts?per_page=200').items | Where-Object { $_.system_key -eq 'DEBTORS' }
$ledger = Call Get "/reports/ledger?account_id=$($debtors.id)"
Check 'debtors ledger' ($ledger.lines.Count -ge 5)
$out = Call Get '/reports/outstanding'
Check 'outstanding' ($out.rows.Count -ge 1)
$gst = Call Get "/reports/gst-summary?month=$((Get-Date).ToString('yyyy-MM'))"
Check 'gst summary' ([decimal]$gst.gstr3b.output.total -gt 0 -and [decimal]$gst.gstr3b.itc.total -gt 0) ($gst.gstr3b | ConvertTo-Json -Compress -Depth 4)
$dash = Call Get '/dashboard'
Check 'dashboard' ($null -ne $dash.receivable)
$hdr = @{ Authorization = "Bearer $script:tok"; 'X-Company-Id' = "$script:cid" }
$xlsx = Invoke-WebRequest -UseBasicParsing -Uri "$Base/reports/trial-balance?format=xlsx" -Headers $hdr
Check 'excel export' ($xlsx.Headers['Content-Type'] -like '*spreadsheetml*' -and $xlsx.RawContentLength -gt 1000)

# Photo of the expense bill: uploaded JPEG becomes WebP + thumbnail
Add-Type -AssemblyName System.Drawing
$jpg = Join-Path $env:TEMP "smoke-$stamp.jpg"
$bmp = New-Object System.Drawing.Bitmap 1600, 1200
[System.Drawing.Graphics]::FromImage($bmp).Clear([System.Drawing.Color]::SteelBlue)
$bmp.Save($jpg, [System.Drawing.Imaging.ImageFormat]::Jpeg); $bmp.Dispose()
$up = curl.exe -s -H "Authorization: Bearer $script:tok" -H "X-Company-Id: $script:cid" -F owner_type=expense -F "owner_id=$($exp.id)" -F "file=@$jpg;type=image/jpeg" "$Base/attachments" | ConvertFrom-Json
Check 'attachment upload' ($up.data.mime -eq 'image/webp' -and $up.data.thumb_url -and $up.data.width -eq 1600) ($up | ConvertTo-Json -Compress -Depth 4)
$badFile = Join-Path $env:TEMP "smoke-$stamp.txt"; Set-Content $badFile '<?php echo 1;'
$bad = curl.exe -s -H "Authorization: Bearer $script:tok" -H "X-Company-Id: $script:cid" -F owner_type=expense -F "owner_id=$($exp.id)" -F "file=@$badFile;type=image/jpeg" "$Base/attachments" | ConvertFrom-Json
Check 'non-image upload rejected' ($bad.error.code -eq 'validation_failed')
$thumbBase = $Base -replace '/api/v1$', ''
$thumb = Invoke-WebRequest -UseBasicParsing -Uri "$thumbBase$($up.data.thumb_url)"
Check 'thumbnail served' ($thumb.StatusCode -eq 200 -and $thumb.RawContentLength -lt 20000)
Remove-Item $jpg, $badFile -ErrorAction SilentlyContinue

# Cancel flow reverses the voucher and keeps the trial balance equal
Call Post "/receipts/$($rcpt.id)/cancel" | Out-Null
Call Post "/invoices/$($inv.id)/cancel" @{ reason = 'Wrong amount' } | Out-Null
$tb2 = Call Get '/reports/trial-balance'
Check 'trial balance after cancellations' ($tb2.total_debit -eq $tb2.total_credit) "$($tb2.total_debit) / $($tb2.total_credit)"

# Company isolation: another company's invoice id must not be visible
$other = Call Post '/companies' @{ name = "Other $stamp"; state_code = '29'; invoice_prefix = 'OTH' }
$saved = $script:cid; $script:cid = $other.id
$leak = Call Get "/invoices/$($inv.id)" -AllowError
Check 'company isolation' ($leak.error.code -eq 'not_found')
$script:cid = $saved

# Role check: staff cannot post journals
Call Post '/users' @{ email = "staff$stamp@example.com"; name = 'Staff'; password = 'staff12345'; role = 'staff' } | Out-Null
$staffTok = (Invoke-RestMethod -Method Post -Uri "$Base/auth/login" -ContentType 'application/json' -Body (@{ email = "staff$stamp@example.com"; password = 'staff12345' } | ConvertTo-Json)).data.access_token
$adminTok = $script:tok; $script:tok = $staffTok
$denied = Call Post '/vouchers' @{ voucher_date = (Get-Date).ToString('yyyy-MM-dd'); narration = 'x'; lines = @() } -AllowError
Check 'staff cannot post journal' ($denied.error.code -eq 'forbidden')
$staffInv = Call Post '/invoices' @{ tenant_id = $local.id; lines = @(@{ description = 'Staff bill'; rate = '500' }) }
Check 'staff can bill' ($staffInv.id -gt 0)
$script:tok = $adminTok

Write-Host ''
if ($script:failures -eq 0) { Write-Host 'ALL CHECKS PASSED' -ForegroundColor Green; exit 0 }
Write-Host "$($script:failures) CHECK(S) FAILED" -ForegroundColor Red
exit 1
