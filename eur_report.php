<?php
/* Copyright (C) 2026 Michael Plas
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    eur/eur_report.php
 * \ingroup eur
 * \brief   Anlage EÜR report: form lines, checks, manual values, PDF and CSV export
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
// Module linked into htdocs/custom (symlink/junction): realpath() points outside htdocs, so walk up the unresolved script path
if (!$res && !empty($_SERVER['SCRIPT_FILENAME'])) {
	for ($dir = dirname($_SERVER['SCRIPT_FILENAME']); !$res && $dir !== dirname($dir); $dir = dirname($dir)) {
		if (file_exists($dir.'/main.inc.php')) {
			$res = @include $dir.'/main.inc.php';
		}
	}
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/report.lib.php';
dol_include_once('/eur/class/eur.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("compta", "accountancy", "eur@eur"));

if (!isModEnabled('eur') || !$user->hasRight('eur', 'report', 'read')) {
	accessforbidden();
}
$canwrite = $user->hasRight('eur', 'report', 'write');

$action = GETPOST('action', 'aZ09');
$tab = GETPOST('tab', 'aZ09') ?: 'form';
$code = GETPOST('code', 'aZ09');
$zehntage = GETPOSTISSET('zehntage') ? (bool) GETPOSTINT('zehntage') : (bool) getDolGlobalInt('EUR_ZEHNTAGE_DEFAULT', 1);

/**
 * Period date from the date picker fields (xxxday/xxxmonth/xxxyear) or a Y-m-d parameter
 *
 * @param string $picker Name of the date picker
 * @param string $plain  Name of the Y-m-d parameter
 * @return string Y-m-d or '' if missing/invalid
 */
function eur_getdate($picker, $plain)
{
	if (GETPOSTISSET($picker.'year')) {
		$y = GETPOSTINT($picker.'year');
		$m = GETPOSTINT($picker.'month');
		$d = GETPOSTINT($picker.'day');
	} else {
		$v = GETPOST($plain, 'alpha');
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $reg)) {
			return '';
		}
		list(, $y, $m, $d) = $reg;
	}
	return ((int) $y >= 1990 && (int) $y <= 2100 && checkdate((int) $m, (int) $d, (int) $y)) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
}

// Period: exact dates, or a whole year (default: previous calendar year)
$year = GETPOSTINT('year');
if ($year < 1990 || $year > 2100) {
	if ($year) {
		setEventMessages('Ungültiges Jahr – es wird das Vorjahr angezeigt.', null, 'warnings');
	}
	$year = (int) dol_print_date(dol_now(), '%Y') - 1;
}
$from = eur_getdate('date_start', 'from');
$to = eur_getdate('date_end', 'to');
if (!$from || !$to) {
	if (GETPOSTISSET('date_startyear') || GETPOSTISSET('date_endyear') || GETPOSTISSET('from') || GETPOSTISSET('to')) {
		setEventMessages('Ungültiger Zeitraum – es wird das Kalenderjahr '.$year.' angezeigt.', null, 'warnings');
	}
	$from = $year.'-01-01';
	$to = $year.'-12-31';
}
if ($from > $to) {
	setEventMessages('Das Startdatum liegt nach dem Enddatum – die Daten wurden getauscht.', null, 'warnings');
	list($from, $to) = array($to, $from);
}
$year = (int) substr($to, 0, 4);
$iscalendaryear = ($from == $year.'-01-01' && $to == $year.'-12-31');
$periodlabel = $iscalendaryear ? (string) $year : dol_print_date(dol_stringtotime($from.' 12:00:00'), 'day').' – '.dol_print_date(dol_stringtotime($to.' 12:00:00'), 'day');
$filesuffix = $iscalendaryear ? (string) $year : $from.'_'.$to;
$param = 'from='.$from.'&to='.$to.'&zehntage='.((int) $zehntage);

/**
 * Format an amount (German style, 2 decimals)
 *
 * @param float $v Amount
 * @return string
 */
function eur_amount($v)
{
	global $langs;
	return price(round((float) $v, 2), 0, $langs, 0, 2, 2);
}


/*
 * Actions
 */

if (in_array($action, array('addmanual', 'deletemanual')) && (!$canwrite || GETPOST('token', 'alpha') !== currentToken())) {
	accessforbidden();
}
if ($action == 'addmanual') {
	$mcode = GETPOST('mcode', 'aZ09');
	$rawamount = trim(GETPOST('amount', 'alpha'));
	$amount = price2num($rawamount, 'MT');
	$note = GETPOST('note', 'alphanohtml');
	$form = Eur::loadForm($year);
	if (empty($form['codes'][$mcode]['line'])) {
		setEventMessages('Unbekannter oder nicht im Formular vorhandener Code', null, 'errors');
	} elseif (!preg_match('/^-?\s*[0-9][0-9.,\s]*$/', $rawamount) || !is_numeric($amount)) {
		// price2num() turns "abc" into 0, so the raw input is checked first
		setEventMessages('Ungültiger Betrag: '.dol_escape_htmltag($rawamount), null, 'errors');
	} else {
		// One value per code and year: replace an existing one
		$db->query("DELETE FROM ".$db->prefix()."eur_manual WHERE entity = ".((int) $conf->entity)." AND year = ".((int) $year)." AND code = '".$db->escape($mcode)."'");
		$sql = "INSERT INTO ".$db->prefix()."eur_manual (entity, year, code, amount, note) VALUES (".((int) $conf->entity).", ".((int) $year).", '".$db->escape($mcode)."', ".((float) $amount).", '".$db->escape($note)."')";
		if (!$db->query($sql)) {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"].'?'.$param.'&tab=manual');
	exit;
}
if ($action == 'deletemanual') {
	$db->query("DELETE FROM ".$db->prefix()."eur_manual WHERE entity = ".((int) $conf->entity)." AND rowid = ".GETPOSTINT('rowid'));
	header("Location: ".$_SERVER["PHP_SELF"].'?'.$param.'&tab=manual');
	exit;
}

$eur = new Eur($db);
$eur->compute($year, $zehntage, $from, $to);
$labels = $eur->codeLabels();

// Rows of the form table: line => [label, abz, nabz, codes contributing]
$lineCodes = array();
$values = $eur->codes;
foreach ($eur->manual as $m) {
	$values[$m['code']] = ($values[$m['code']] ?? 0) + $m['amount'];
}
foreach ($values as $c => $v) {
	$def = $eur->form['codes'][$c] ?? null;
	if (!empty($def['line'])) {
		$l = $def['line'];
		$lineCodes[$l][$c] = $v;
	}
}
$sections = array('E' => '1. Betriebseinnahmen', 'A' => '2. Betriebsausgaben', 'G' => '3. Ermittlung des Gewinns', 'P' => '4. Entnahmen und Einlagen');

if ($action == 'csv') {
	$filename = 'Anlage_EUER_'.$filesuffix.'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	$num = function ($v) {
		return number_format((float) $v, 2, ',', '');
	};
	// Neutralise cells a spreadsheet would evaluate as formula (=, +, -, @, tab, CR)
	$txt = function ($v) {
		$v = (string) $v;
		return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
	};
	fputcsv($out, array('Anlage EÜR '.$periodlabel, $txt($mysoc->name), '10-Tage-Regel: '.($eur->zehntage ? 'angewendet' : 'nicht angewendet'), $eur->ku ? 'Kleinunternehmer' : 'Regelbesteuerung', $eur->isPreliminary() ? 'VORLÄUFIG' : ''), ';');
	fputcsv($out, array('Zeile', 'Bezeichnung', 'Betrag / abziehbar', 'nicht abziehbar'), ';');
	foreach ($eur->form['lines'] as $line => $def) {
		fputcsv($out, array($line, $txt($def[0]), $num($eur->lines[$line]['abz']), empty($def['twocol']) ? '' : $num($eur->lines[$line]['nabz'])), ';');
	}
	fputcsv($out, array(), ';');
	fputcsv($out, array('Code', 'Bezeichnung', 'Datum', 'Quelle', 'Beleg', 'Rechnung', 'Konto', 'Betrag', 'Hinweis'), ';');
	foreach ($eur->details as $c => $rows) {
		foreach ($rows as $d) {
			fputcsv($out, array($c, $txt($labels[$c] ?? $c), dol_print_date($d['date'], 'day'), $txt($d['source']), $txt($d['ref']), $txt($d['doc'] ?? ''), $txt($d['account'] ?? ''), $num($d['amount']), $txt($d['note'] ?? '')), ';');
		}
	}
	foreach ($eur->manual as $m) {
		fputcsv($out, array($m['code'], $txt($labels[$m['code']] ?? $m['code']), '', 'Manueller Wert', '', '', '', $num($m['amount']), $txt($m['note'])), ';');
	}
	fclose($out);
	exit;
}

/**
 * HTML table of the form lines (screen and PDF)
 *
 * @param Eur                  $eur       Computation
 * @param array<string,string> $sections  Section titles
 * @param array<int,array>     $lineCodes line => code => value
 * @param bool                 $forpdf    Plain HTML for TCPDF
 * @param string               $param     URL params
 * @return string
 */
function eur_form_table($eur, $sections, $lineCodes, $forpdf, $param)
{
	global $langs;

	$html = '';
	$current = '';
	if ($forpdf) {
		$html .= '<table cellpadding="2" border="0.2">';
	} else {
		$html .= '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	}
	foreach ($eur->form['lines'] as $line => $def) {
		if ($def[1] != $current) {
			$current = $def[1];
			$html .= $forpdf ? '<tr bgcolor="#dddddd"><td width="8%"><b>'.$langs->trans('EurLine').'</b></td><td width="58%"><b>'.$sections[$current].'</b></td><td width="17%" align="right"><b>'.($current == 'A' ? 'EUR (abziehbar)' : 'EUR').'</b></td><td width="17%" align="right"><b>'.($current == 'A' ? 'nicht abziehbar' : '').'</b></td></tr>'
				: '<tr class="liste_titre"><td class="width50">'.$langs->trans('EurLine').'</td><td>'.$sections[$current].'</td><td class="right">'.($current == 'A' ? $langs->trans('EurDeductible') : 'EUR').'</td><td class="right">'.($current == 'A' ? $langs->trans('EurNonDeductible') : '').'</td></tr>';
		}
		$abz = $eur->lines[$line]['abz'];
		$nabz = $eur->lines[$line]['nabz'];
		$issum = !empty($def['sum']);
		$empty = (abs($abz) < 0.005 && abs($nabz) < 0.005);
		if ($empty && !$issum && $forpdf) {
			continue;
		}
		$label = dol_escape_htmltag($def[0]);
		if (!$forpdf && !empty($lineCodes[$line])) {
			$links = array();
			foreach ($lineCodes[$line] as $c => $v) {
				$links[] = '<a href="'.$_SERVER['PHP_SELF'].'?'.$param.'&tab=form&code='.urlencode($c).'#details">'.dol_escape_htmltag($c).'</a>';
			}
			$label .= ' <span class="opacitymedium small">('.implode(', ', $links).')</span>';
		}
		if (!$forpdf && !empty($def['manual']) && $empty) {
			$label .= ' <span class="opacitymedium small">– manuell</span>';
		}
		$b1 = $issum ? '<b>' : '';
		$b2 = $issum ? '</b>' : '';
		if ($forpdf) {
			$html .= '<tr><td width="8%">'.$line.'</td><td width="58%">'.$b1.$label.$b2.'</td><td width="17%" align="right">'.$b1.eur_amount($abz).$b2.'</td><td width="17%" align="right">'.(empty($def['twocol']) ? '' : eur_amount($nabz)).'</td></tr>';
		} else {
			$html .= '<tr class="oddeven'.($empty && !$issum ? ' opacitymedium' : '').'"><td>'.$line.'</td><td>'.$b1.$label.$b2.'</td><td class="right nowraponall">'.$b1.eur_amount($abz).$b2.'</td><td class="right nowraponall">'.(empty($def['twocol']) ? '' : eur_amount($nabz)).'</td></tr>';
		}
	}
	$html .= $forpdf ? '</table>' : '</table></div>';
	return $html;
}

if ($action == 'pdf') {
	require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
	$outputlangs = $langs;
	$pdf = pdf_getInstance('A4');
	$pdf->SetTitle('Anlage EÜR '.$periodlabel);
	$pdf->SetAuthor($mysoc->name);
	$pdf->SetCreator('Dolibarr '.DOL_VERSION.' / Modul EÜR');
	$pdf->setPrintHeader(false);
	$pdf->setPrintFooter(false);
	$pdf->SetMargins(12, 12, 12);
	$pdf->SetAutoPageBreak(true, 12);
	$pdf->SetFont(pdf_getPDFFont($outputlangs), '', 8);
	$pdf->AddPage();

	$head = '<h2>Anlage EÜR '.$periodlabel.'</h2>';
	$head .= '<p>'.dol_escape_htmltag($mysoc->name).($mysoc->idprof1 ? ' · St.-Nr. '.dol_escape_htmltag($mysoc->idprof1) : '').'<br>';
	$head .= 'Ermittlung nach Zahlungen (§ 4 Abs. 3 EStG) · '.($eur->ku ? 'Kleinunternehmer (§ 19 UStG)' : 'Regelbesteuerung').' · 10-Tage-Regel: '.($eur->zehntage ? 'angewendet' : 'nicht angewendet').'<br>';
	$head .= 'Erstellt am '.dol_print_date(dol_now(), 'dayhour').'</p>';
	if ($eur->isPreliminary()) {
		$head .= '<p style="color:#b00000"><b>'.$langs->trans('EurPreliminary').'</b></p>';
	}
	$pdf->writeHTML($head, true, false, true, false, '');
	$pdf->writeHTML(eur_form_table($eur, $sections, $lineCodes, true, $param), true, false, true, false, '');

	$failed = '';
	foreach ($eur->checks as $id => $c) {
		if (!$c['ok']) {
			$failed .= '<li><b>'.$id.' '.dol_escape_htmltag($c['title']).'</b>'.($c['items'] ? '<br>'.implode('<br>', array_map('dol_escape_htmltag', array_slice($c['items'], 0, 20))) : '').'</li>';
		}
	}
	if ($failed) {
		$pdf->writeHTML('<h3>Prüfungen</h3><ul>'.$failed.'</ul>', true, false, true, false, '');
	}
	if ($eur->manual) {
		$m = '<h3>Manuelle Werte</h3><table cellpadding="2" border="0.2">';
		foreach ($eur->manual as $row) {
			$m .= '<tr><td width="20%">'.$row['code'].'</td><td width="60%">'.dol_escape_htmltag(($labels[$row['code']] ?? '').($row['note'] ? ' – '.$row['note'] : '')).'</td><td width="20%" align="right">'.eur_amount($row['amount']).'</td></tr>';
		}
		$pdf->writeHTML($m.'</table>', true, false, true, false, '');
	}
	$pdf->writeHTML('<p style="color:#666666">'.dol_escape_htmltag($langs->trans('EurDisclaimer')).'</p>', true, false, true, false, '');
	$pdf->Output('Anlage_EUER_'.$filesuffix.'.pdf', 'D');
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('EurReport'), '', '', 0, 0, '', '', '', 'mod-eur page-report');

$formhtml = new Form($db);
$period = $formhtml->selectDate(dol_stringtotime($from.' 12:00:00'), 'date_start', 0, 0, 0, '', 1, 0);
$period .= ' – '.$formhtml->selectDate(dol_stringtotime($to.' 12:00:00'), 'date_end', 0, 0, 0, '', 1, 0);
// Presets: calendar year and its quarters
$presets = array('Jahr '.$year => array($year.'-01-01', $year.'-12-31'));
foreach (array(1 => array('01-01', '03-31'), 2 => array('04-01', '06-30'), 3 => array('07-01', '09-30'), 4 => array('10-01', '12-31')) as $qn => $qd) {
	$presets['Q'.$qn] = array($year.'-'.$qd[0], $year.'-'.$qd[1]);
}
$links = array();
foreach ($presets as $label => $p) {
	$active = ($p[0] == $from && $p[1] == $to);
	$links[] = '<a href="'.$_SERVER['PHP_SELF'].'?from='.$p[0].'&to='.$p[1].'&zehntage='.((int) $zehntage).'&tab='.$tab.'"'.($active ? ' class="bold"' : '').'>'.$label.'</a>';
}
$period .= '<br><span class="small">'.implode(' · ', $links).'</span>';
$periodlink = '<a href="'.$_SERVER['PHP_SELF'].'?year='.($year - 1).'&zehntage='.((int) $zehntage).'&tab='.$tab.'" title="Vorjahr">'.img_previous().'</a> <a href="'.$_SERVER['PHP_SELF'].'?year='.($year + 1).'&zehntage='.((int) $zehntage).'&tab='.$tab.'" title="Folgejahr">'.img_next().'</a>';
$calcmode = $langs->trans('EurZehnTage').' '.img_help(1, $langs->trans('EurZehnTageHelp')).'&nbsp; ';
$calcmode .= '<label><input type="radio" name="zehntage" value="1"'.($zehntage ? ' checked' : '').'> '.$langs->trans('Yes').'</label> &nbsp; ';
$calcmode .= '<label><input type="radio" name="zehntage" value="0"'.(!$zehntage ? ' checked' : '').'> '.$langs->trans('No').'</label>';
$calcmode .= '<br>'.($eur->ku ? $langs->trans('EurKuYear') : 'Regelbesteuerung').' <span class="opacitymedium">(Einstellung im Modul)</span>';
$description = 'Einnahmenüberschussrechnung nach Zahlungseingang und -ausgang (§ 4 Abs. 3 EStG), gegliedert nach Anlage EÜR '.$eur->form['year'].'.<br><span class="opacitymedium">'.$langs->trans('EurDisclaimer').'</span>';
$exportlink = '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$param.'&action=pdf&token='.newToken().'">PDF</a><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$param.'&action=csv&token='.newToken().'">CSV</a>';

report_header($langs->trans('EurReport').' '.$periodlabel, '', $period, $periodlink, $description, dol_now(), $exportlink, array('tab' => $tab), $calcmode);

// Status banner
if ($eur->isPreliminary()) {
	print info_admin($langs->trans('EurPreliminary').' – siehe Tab „'.$langs->trans('EurTabChecks').'“', 0, 0, 'error');
} else {
	$warnings = array_filter($eur->checks, function ($c) {
		return !$c['ok'];
	});
	print $warnings ? info_admin($langs->trans('EurChecksOk').', aber mit Hinweisen', 0, 0, 'warning') : '<div class="ok">'.$langs->trans('EurChecksOk').'</div>';
}
print '<br>';

// Tabs
$head = array();
foreach (array('form' => 'EurTabForm', 'checks' => 'EurTabChecks', 'manual' => 'EurTabManual') as $k => $label) {
	$badge = '';
	if ($k == 'checks') {
		$nb = count(array_filter($eur->checks, function ($c) {
			return !$c['ok'];
		}));
		$badge = $nb ? '<span class="badge marginleftonlyshort">'.$nb.'</span>' : '';
	}
	if ($k == 'manual' && $eur->manual) {
		$badge = '<span class="badge marginleftonlyshort">'.count($eur->manual).'</span>';
	}
	$head[] = array($_SERVER['PHP_SELF'].'?'.$param.'&tab='.$k, $langs->trans($label).$badge, $k);
}
print dol_get_fiche_head($head, $tab, '', -1);

if ($tab == 'form') {
	print eur_form_table($eur, $sections, $lineCodes, false, $param);

	// Neutral and unassigned codes
	print '<br>'.load_fiche_titre($langs->trans('EurNeutral'), '', '');
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('EurCode').'</td><td>'.$langs->trans('Label').'</td><td class="right">EUR</td></tr>';
	foreach (array('N_AV_KAUF', 'N_DARLEHEN', Eur::CODE_NZ_IN, Eur::CODE_NZ_OUT) as $c) {
		$v = $eur->codes[$c] ?? 0;
		$cls = (strpos($c, 'NZ_') === 0 && $v != 0) ? ' class="error"' : '';
		print '<tr class="oddeven"><td><a href="'.$_SERVER['PHP_SELF'].'?'.$param.'&tab=form&code='.$c.'#details">'.$c.'</a></td><td>'.dol_escape_htmltag($labels[$c] ?? $c).'</td><td class="right"'.$cls.'>'.eur_amount($v).'</td></tr>';
	}
	print '</table>';

	if ($code) {
		print '<br><a name="details"></a>'.load_fiche_titre($langs->trans('EurDetails').' '.dol_escape_htmltag($code).' – '.dol_escape_htmltag($labels[$code] ?? ''), '', '');
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Source').'</td><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Invoice').'</td><td>'.$langs->trans('AccountAccounting').'</td><td>'.$langs->trans('Note').'</td><td class="right">EUR</td></tr>';
		$total = 0;
		foreach ($eur->details[$code] ?? array() as $d) {
			$total += $d['amount'];
			print '<tr class="oddeven"><td>'.dol_print_date($d['date'], 'day').'</td><td>'.dol_escape_htmltag($d['source']).'</td>';
			print '<td><a href="'.DOL_URL_ROOT.$d['url'].'">'.dol_escape_htmltag($d['ref']).'</a></td>';
			print '<td>'.(empty($d['doc']) ? '' : '<a href="'.DOL_URL_ROOT.($d['docurl'] ?? $d['url']).'">'.dol_escape_htmltag($d['doc']).'</a>').'</td>';
			print '<td>'.dol_escape_htmltag($d['account'] ?? '').'</td><td>'.dol_escape_htmltag($d['note'] ?? '').'</td><td class="right nowraponall">'.eur_amount($d['amount']).'</td></tr>';
		}
		foreach ($eur->manual as $m) {
			if ($m['code'] == $code) {
				$total += $m['amount'];
				print '<tr class="oddeven"><td></td><td>Manueller Wert</td><td colspan="4">'.dol_escape_htmltag($m['note']).'</td><td class="right">'.eur_amount($m['amount']).'</td></tr>';
			}
		}
		print '<tr class="liste_total"><td colspan="6">'.$langs->trans('Total').'</td><td class="right">'.eur_amount($total).'</td></tr>';
		print '</table></div>';
		if (strpos($code, 'NZ_') === 0) {
			print '<br><div class="opacitymedium">Nicht kontierte Belegzeilen: <a href="'.DOL_URL_ROOT.'/accountancy/customer/list.php">Kunden</a> · <a href="'.DOL_URL_ROOT.'/accountancy/supplier/list.php">Lieferanten</a> · <a href="'.DOL_URL_ROOT.'/accountancy/expensereport/list.php">Spesen</a>. Konten ohne EÜR-Code: <a href="'.dol_buildpath('/eur/admin/setup.php', 1).'">Zuordnung</a>.</div>';
		}
	}
} elseif ($tab == 'checks') {
	print '<table class="noborder centpercent"><tr class="liste_titre"><td class="width50"></td><td>'.$langs->trans('EurTabChecks').'</td><td class="width100">Status</td></tr>';
	foreach ($eur->checks as $id => $c) {
		$status = $c['ok'] ? img_picto('', 'tick').' OK' : ($c['level'] == 'error' ? img_error().' Fehler' : img_warning().' Hinweis');
		print '<tr class="oddeven"><td class="tdtop">'.$id.'</td><td>'.dol_escape_htmltag($c['title']);
		if (!$c['ok'] && $c['items']) {
			print '<ul class="small">';
			foreach ($c['items'] as $it) {
				print '<li>'.dol_escape_htmltag($it).'</li>';
			}
			print '</ul>';
		}
		print '</td><td class="tdtop nowraponall">'.$status.'</td></tr>';
	}
	print '</table>';

	print '<br><table class="noborder centpercent"><tr class="liste_titre"><td>Kanal</td><td class="right">Zahlungen</td><td class="right">zugeordnet</td></tr>';
	foreach ($eur->channels as $ch => $t) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($ch).'</td><td class="right">'.eur_amount($t['header']).'</td><td class="right">'.eur_amount($t['pieces']).'</td></tr>';
	}
	print '</table>';
} elseif ($tab == 'manual') {
	print '<div class="opacitymedium">'.$langs->trans('EurManualHelp').'</div><br>';
	if (!$eur->fullYear) {
		print info_admin('Die Werte gehören zum Steuerjahr '.$year.' und werden nur eingerechnet, wenn der Berichtszeitraum genau zwölf Monate umfasst.', 0, 0, 'warning').'<br>';
	}
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('EurLine').'</td><td>'.$langs->trans('EurCode').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('Note').'</td><td class="right">EUR</td><td></td></tr>';
	foreach ($eur->manual as $m) {
		$def = $eur->form['codes'][$m['code']] ?? array();
		print '<tr class="oddeven"><td>'.($def['line'] ?? '').'</td><td>'.dol_escape_htmltag($m['code']).'</td><td>'.dol_escape_htmltag($labels[$m['code']] ?? '').'</td><td>'.dol_escape_htmltag($m['note']).'</td><td class="right">'.eur_amount($m['amount']).'</td>';
		print '<td class="right">'.($canwrite ? '<a href="'.$_SERVER['PHP_SELF'].'?'.$param.'&action=deletemanual&rowid='.$m['rowid'].'&token='.newToken().'">'.img_delete().'</a>' : '').'</td></tr>';
	}
	print '</table>';

	if ($canwrite) {
		$options = array();
		foreach ($eur->form['codes'] as $c => $def) {
			if (!empty($def['line'])) {
				$options[$c] = 'Zeile '.$def['line'].' – '.$c.' – '.($labels[$c] ?? '');
			}
		}
		print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'?'.$param.'&tab=manual">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addmanual">';
		print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="4">'.$langs->trans('Add').'</td></tr><tr class="oddeven"><td>';
		print Form::selectarray('mcode', $options, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
		print '</td><td><input type="text" name="amount" class="width100 right" placeholder="0,00"></td>';
		print '<td><input type="text" name="note" class="minwidth300" placeholder="'.$langs->trans('Note').'"></td>';
		print '<td><input type="submit" class="button" value="'.$langs->trans('Save').'"></td></tr></table></form>';
		print '<div class="opacitymedium small">Ein Wert je Code und Jahr; erneutes Speichern ersetzt ihn.</div>';
	}
}

print dol_get_fiche_end();

llxFooter();
$db->close();
