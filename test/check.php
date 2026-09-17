<?php
/* Copyright (C) 2026 Michael Plas (Michi91)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * CLI: assertions for the EÜR module.
 *  1. Pure functions allocate() and vatYear()
 *  2. compute(2025) against the hand-computed values of the dataset created by seed.php
 *
 * Usage: php check.php          (exit code 0 = all green)
 */

if (php_sapi_name() !== 'cli') {
	die("CLI only\n");
}
// Dolibarr htdocs: env DOLIBARR_HTDOCS, else walk up from the script path as called (works for symlinked modules)
$htdocs = getenv('DOLIBARR_HTDOCS');
foreach (array($_SERVER['SCRIPT_FILENAME'] ?? '', __FILE__) as $start) {
	for ($dir = dirname((string) $start); !$htdocs && $start && $dir !== dirname($dir); $dir = dirname($dir)) {
		if (file_exists($dir.'/master.inc.php')) {
			$htdocs = $dir;
		}
	}
}
if (!$htdocs || !file_exists($htdocs.'/master.inc.php')) {
	fwrite(STDERR, "Dolibarr nicht gefunden. Skript über htdocs/custom/eur/test/ aufrufen oder DOLIBARR_HTDOCS setzen.\n");
	exit(2);
}
require $htdocs.'/master.inc.php';
require_once __DIR__.'/../class/eur.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 */

$failures = 0;
$count = 0;

/**
 * Compare two amounts to the cent
 *
 * @param string $name     Label
 * @param float  $expected Expected
 * @param float  $actual   Actual
 * @return void
 */
function eq($name, $expected, $actual)
{
	global $failures, $count;
	$count++;
	if (abs((float) $expected - (float) $actual) >= 0.005) {
		$failures++;
		echo "  FAIL ".$name.": erwartet ".number_format($expected, 2, ',', '.').", ist ".number_format((float) $actual, 2, ',', '.')."\n";
	}
}

/**
 * Boolean assertion
 *
 * @param string $name Label
 * @param bool   $cond Condition
 * @return void
 */
function ok($name, $cond)
{
	global $failures, $count;
	$count++;
	if (!$cond) {
		$failures++;
		echo "  FAIL ".$name."\n";
	}
}

echo "1. allocate()\n";
// T2: 1000 @19 + 500 @7 = 1725, payment of 1000
$r = Eur::allocate(array(array('code' => 'E', 'ht' => 1000, 'vat' => 190), array('code' => 'E', 'ht' => 500, 'vat' => 35)), 1725, 1000, false);
eq('T2 netto', 869.57, $r['E']);
eq('T2 USt', 130.43, $r['__VAT__']);
// T5: credit note 7 % used as payment
$pool = array(array('code' => 'E', 'ht' => 1000, 'vat' => 190), array('code' => 'E', 'ht' => 1000, 'vat' => 70), array('code' => 'E', 'ht' => -200, 'vat' => -14));
$r = Eur::allocate($pool, 2260 - 214, 2046, false);
eq('T5 netto', 1800, $r['E']);
eq('T5 USt', 246, $r['__VAT__']);
// Sum always equals the payment, also with ugly shares
$r = Eur::allocate(array(array('code' => 'A', 'ht' => 33.33, 'vat' => 6.33), array('code' => 'B', 'ht' => 33.33, 'vat' => 6.33), array('code' => null, 'ht' => 33.34, 'vat' => 6.34)), 119, 100, false);
eq('Summe = Zahlung', 100, array_sum($r));
ok('Unkontiert → __NZ__', isset($r['__NZ__']));
// Kleinunternehmer: gross into the code
$r = Eur::allocate(array(array('code' => 'A', 'ht' => 700, 'vat' => 133)), 833, 833, true);
eq('KU brutto', 833, $r['A']);
ok('KU ohne USt-Anteil', !isset($r['__VAT__']));
ok('Nenner 0 → null', Eur::allocate(array(), 0, 10, false) === null);

echo "2. vatYear() (10-Tage-Regel)\n";
$t = function ($s) {
	return gmmktime(12, 0, 0, (int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
};
eq('Dez-VA bezahlt 09.01. → Vorjahr', 2024, Eur::vatYear($t('2025-01-09'), $t('2024-12-31'), true, false));
eq('Dez-VA bezahlt 08.01.26 → 2025', 2025, Eur::vatYear($t('2026-01-08'), $t('2025-12-31'), true, false));
eq('ohne Regel: Zahlungsjahr', 2025, Eur::vatYear($t('2025-01-09'), $t('2024-12-31'), false, false));
eq('Nov-VA ohne Dauerfrist bezahlt 05.01. → Zahlungsjahr (fällig 10.12.)', 2025, Eur::vatYear($t('2025-01-05'), $t('2024-11-30'), true, false));
eq('Nov-VA mit Dauerfrist bezahlt 05.01. → Vorjahr', 2024, Eur::vatYear($t('2025-01-05'), $t('2024-11-30'), true, true));
eq('Zahlung nach 10.01. → Zahlungsjahr', 2025, Eur::vatYear($t('2025-01-11'), $t('2024-12-31'), true, false));
eq('Zahlung im Sommer', 2025, Eur::vatYear($t('2025-07-08'), $t('2025-06-30'), true, false));

echo "3. compute(2025) Regelbesteuerung, 10-Tage-Regel\n";
$eur = new Eur($db);
$eur->compute(2025, true);
$expect = array(
	'E_UMS_STPFL' => 8429.57, 'E_UMS_FREI' => 50, 'E_UST' => 1470.83, 'E_UST_ERST' => 80, 'A_GESCH_NABZ' => 119,
	'A_GWG' => 700, 'A_VST' => 888.98, 'A_RAUM' => 800, 'A_BEWIRT' => 100, 'A_REISE' => 142.02,
	'A_UST_FA' => 800, 'A_ZINS' => 150, 'A_BEITRAEGE' => 20, 'A_UEBRIGE' => 12.50,
	'N_AV_KAUF' => 3000, 'N_DARLEHEN' => 1000, 'P_ENTNAHME' => 2000, 'P_EINLAGE' => 500,
	Eur::CODE_NZ_OUT => 99, Eur::CODE_NZ_IN => 0,
);
foreach ($expect as $c => $v) {
	eq($c, $v, $eur->codes[$c] ?? 0);
}
foreach ($eur->codes as $c => $v) {
	ok('kein unerwarteter Code '.$c.' ('.$v.')', array_key_exists($c, $expect));
}
$in = $out = 0;
foreach ($eur->channels as $ch => $tt) {
	eq('C1 '.$ch, $tt['header'], $tt['pieces']);
	if ($ch == 'Sonstige Zahlungen' || $ch == 'Umsatzsteuer-Zahlungen') {
		continue;
	}
	$tt['header'] > 0 ? $in += $tt['header'] : $out -= $tt['header'];
}
// These channels mix directions in one net total: add in- and outflows separately from the codes
$in += $eur->codes['P_EINLAGE'] + $eur->codes['E_UST_ERST'];
$out += $eur->codes['P_ENTNAHME'] + $eur->codes['A_UEBRIGE'] + $eur->codes[Eur::CODE_NZ_OUT] + $eur->codes['A_UST_FA'];
eq('Kasse Eingänge', 10530.40, $in);
eq('Kasse Ausgänge', 9831.50, $out);
eq('Zeile 15', 8429.57, $eur->lines[15]['abz']);
eq('Zeile 16 (Überzahlung mit 0-%-Zeile nicht negativ)', 50, $eur->lines[16]['abz']);
eq('Zeile 17', 1470.83, $eur->lines[17]['abz']);
eq('Zeile 23', 10030.40, $eur->lines[23]['abz']);
eq('Zeile 57 ohne nicht abziehbare Vorsteuer', 888.98, $eur->lines[57]['abz']);
eq('Zeile 62 nicht abziehbar brutto', 119, $eur->lines[62]['nabz']);
eq('Zeile 63 abziehbar', 70, $eur->lines[63]['abz']);
eq('Zeile 63 nicht abziehbar', 30, $eur->lines[63]['nabz']);
eq('Zeile 66 Homeoffice (manuell)', 720, $eur->lines[66]['abz']);
eq('Zeile 75', 4303.50, $eur->lines[75]['abz']);
eq('Zeile 90', 5726.90, $eur->lines[90]['abz']);
eq('Zeile 97', 5726.90, $eur->lines[97]['abz']);
eq('Zeile 106', 2000, $eur->lines[106]['abz']);
eq('Zeile 107', 500, $eur->lines[107]['abz']);
ok('C1 ok', $eur->checks['C1']['ok']);
ok('C2 meldet 99 nicht zugeordnet', !$eur->checks['C2']['ok'] && count($eur->checks['C2']['items']) == 1);
ok('C3 ok', $eur->checks['C3']['ok']);
ok('C4 meldet genau 1 Bankzeile', !$eur->checks['C4']['ok'] && count($eur->checks['C4']['items']) == 1);
ok('C5 ok (keine Doppelzuordnung SKR03/SKR04)', $eur->checks['C5']['ok']);
ok('C6 ok (GWG 700 netto)', $eur->checks['C6']['ok']);
ok('C7 Hinweis Anlagenkauf ohne AfA', !$eur->checks['C7']['ok']);
ok('vorläufig', $eur->isPreliminary());

echo "4. compute(2025) ohne 10-Tage-Regel\n";
$eur2 = new Eur($db);
$eur2->compute(2025, false);
eq('A_UST_FA', 1700, $eur2->codes['A_UST_FA'] ?? 0);
eq('USt-Kanal', -1620, $eur2->channels['Umsatzsteuer-Zahlungen']['header']);
eq('Zeile 58', 1700, $eur2->lines[58]['abz']);

echo "5. compute(2025) als Kleinunternehmer-Jahr\n";
$conf->global->EUR_KU_YEARS = '2025';
$eur3 = new Eur($db);
$eur3->compute(2025, true);
eq('A_VST', 0, $eur3->codes['A_VST'] ?? 0);
eq('A_GWG brutto', 833, $eur3->codes['A_GWG'] ?? 0);
eq('N_AV_KAUF brutto', 3570, $eur3->codes['N_AV_KAUF'] ?? 0);
eq('A_RAUM brutto', 952, $eur3->codes['A_RAUM'] ?? 0);
eq('A_BEWIRT brutto', 119, $eur3->codes['A_BEWIRT'] ?? 0);
eq('A_REISE brutto', 157, $eur3->codes['A_REISE'] ?? 0);
eq('Zeile 12 (Umsätze 2025 brutto)', 8950.40, $eur3->lines[12]['abz']);
eq('Zeile 15: Rechnung aus Regelbesteuerungsjahr 2024', 869.57, $eur3->lines[15]['abz']);
eq('Zeile 17: USt dieser Rechnung bleibt', 130.43, $eur3->lines[17]['abz']);
eq('Zeile 16 leer (Überzahlung 2025 in Zeile 12)', 0, $eur3->lines[16]['abz']);
ok('C6 meldet GWG > 800 brutto', !$eur3->checks['C6']['ok']);
ok('C1 ok', $eur3->checks['C1']['ok']);

echo "6. compute() Zeitraum 01.01.–30.06.2025\n";
unset($conf->global->EUR_KU_YEARS);
ok('vatDate: Dez-VA bezahlt 08.01.26 wirkt am 31.12.25', gmdate('Y-m-d', Eur::vatDate($t('2026-01-08'), $t('2025-12-31'), true, false)) == '2025-12-31');
ok('vatDate: ohne Verschiebung = Zahlungsdatum', gmdate('Y-m-d', Eur::vatDate($t('2025-04-10'), $t('2025-03-31'), true, false)) == '2025-04-10');
$h1 = new Eur($db);
$h1->compute(0, true, '2025-01-01', '2025-06-30');
ok('Steuerjahr aus Enddatum', $h1->year == 2025);
ok('kein volles Jahr', !$h1->fullYear);
// T1 1000/190, T2 869.57/130.43, T3 1960/372.40, T4 200/38, T5 1800/246, T6 Anzahlung 500/95 (Schlussrechnung erst Juli)
eq('E_UMS_STPFL', 6329.57, $h1->codes['E_UMS_STPFL'] ?? 0);
eq('E_UST', 1071.83, $h1->codes['E_UST'] ?? 0);
// USt-VA Dez 2024 (bezahlt 09.01.) gehört ins Vorjahr, Erstattung (Juli) liegt außerhalb
eq('A_UST_FA', 500, $h1->codes['A_UST_FA'] ?? 0);
eq('E_UST_ERST', 0, $h1->codes['E_UST_ERST'] ?? 0);
eq('A_VST (nur T7)', 703, $h1->codes['A_VST'] ?? 0);
ok('keine manuellen Jahreswerte', empty($h1->manual));
eq('Zeile 66 ohne Homeoffice-Jahreswert', 0, $h1->lines[66]['abz']);
ok('C1 ok', $h1->checks['C1']['ok']);
ok('Hinweis: manuelle Werte fehlen', !$h1->checks['C7']['ok']);
$wj = new Eur($db);
$wj->compute(0, true, '2024-07-01', '2025-06-30');
ok('abweichendes Wirtschaftsjahr zählt als volles Jahr', $wj->fullYear);

echo "7. Warnung ohne Kontenzuordnung (C0)\n";
ok('C0 ok bei geladener Zuordnung', $eur->checks['C0']['ok'] && $eur->mappedAccounts > 0);
ok('C0 steht an erster Stelle', array_key_first($eur->checks) == 'C0');
// Chart without any EÜR mapping active (in memory only, DB unchanged)
$chartbefore = getDolGlobalInt('CHARTOFACCOUNTS');
$res = $db->query("SELECT rowid FROM ".$db->prefix()."accounting_system WHERE pcg_version NOT IN ('SKR03', 'SKR04') ORDER BY rowid LIMIT 1");
$conf->global->CHARTOFACCOUNTS = $db->fetch_object($res)->rowid;
$nomap = new Eur($db);
$nomap->compute(2026, true);	// year without payments: before C0 this reported "all checks passed"
ok('C0 schlägt fehl ohne Zuordnung', !$nomap->checks['C0']['ok']);
ok('Report dann vorläufig, auch ohne Zahlungen', $nomap->isPreliminary());
$conf->global->CHARTOFACCOUNTS = 0;
$nochart = new Eur($db);
$nochart->compute(2026, true);
ok('C0 meldet fehlenden Kontenrahmen', !$nochart->checks['C0']['ok'] && strpos($nochart->checks['C0']['items'][0], 'kein Kontenrahmen') !== false);
$res = $db->query("SELECT rowid FROM ".$db->prefix()."accounting_system WHERE pcg_version = 'SKR04'");
$conf->global->CHARTOFACCOUNTS = $db->fetch_object($res)->rowid;
$skr04 = new Eur($db);
$skr04->compute(2025, true);
ok('C0 ok mit SKR04 (auch zugeordnet)', $skr04->checks['C0']['ok']);
ok('C10 meldet SKR03-kontierte Belege bei aktivem SKR04', !$skr04->checks['C10']['ok']);
ok('C10 ok beim richtigen Kontenrahmen', $eur->checks['C10']['ok']);
$conf->global->CHARTOFACCOUNTS = $chartbefore;

echo "\n".($count - $failures)." von ".$count." Prüfungen bestanden\n";
exit($failures ? 1 : 0);
