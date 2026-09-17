<?php
/* Copyright (C) 2026 Michael Plas (Michi91)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * CLI: configure a FRESH Dolibarr database as German company (SKR03) and create the EÜR test dataset.
 * Invoices, credit notes, deposits and their payments are created through core classes so that
 * totals and discounts are exactly what Dolibarr itself produces.
 *
 * Usage: php seed.php
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var Societe $mysoc
 */

/**
 * Stop on error
 *
 * @param string $msg Message
 * @return never
 */
function fail($msg)
{
	global $db;
	fwrite(STDERR, "FEHLER: ".$msg."\n".$db->lasterror()."\n");
	exit(1);
}

/**
 * Run SQL or fail
 *
 * @param string $sql SQL
 * @return int Last insert id
 */
function q($sql)
{
	global $db;
	if (!$db->query($sql)) {
		fail($sql);
	}
	return (int) $db->last_insert_id('llx_x');
}

/**
 * Timestamp of a date (noon, server time)
 *
 * @param string $d Y-m-d
 * @return int
 */
function d($d)
{
	list($y, $m, $dd) = explode('-', $d);
	return dol_mktime(12, 0, 0, (int) $m, (int) $dd, (int) $y);
}

$langs->setDefaultLang('de_DE');
$user = new User($db);
if ($user->fetch(0, 'admin') <= 0) {
	fail('User admin not found');
}
$user->loadRights();

$res = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix()."facture");
if ($res && $db->fetch_object($res)->nb > 0) {
	fail('Die Datenbank enthält bereits Rechnungen. seed.php ist nur für eine frische Installation gedacht.');
}

echo "1. Firma Deutschland\n";
$consts = array(
	'MAIN_INFO_SOCIETE_COUNTRY' => '5:DE:Deutschland',
	'MAIN_INFO_SOCIETE_NOM' => 'Muster Beratung (Test EÜR)',
	'MAIN_INFO_SOCIETE_TOWN' => 'Köln',
	'MAIN_INFO_SOCIETE_ZIP' => '50667',
	'MAIN_MONNAIE' => 'EUR',
	'FACTURE_TVAOPTION' => '1',
	'SOCIETE_FISCAL_MONTH_START' => '1',
);
foreach ($consts as $k => $v) {
	dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $conf->entity);
	$conf->global->$k = $v;
}
$mysoc->setMysoc($conf);

echo "2. Module\n";
foreach (array('modSociete', 'modFacture', 'modFournisseur', 'modBanque', 'modTax', 'modExpenseReport', 'modSalaries', 'modLoan', 'modAccounting') as $mod) {
	$r = activateModule($mod);
	if (!empty($r['errors'])) {
		fail($mod.': '.implode(', ', $r['errors']));
	}
}

echo "3. Kontenrahmen SKR03 + SKR04\n";
$sqlfile = DOL_DOCUMENT_ROOT.'/install/mysql/data/llx_accounting_account_de.sql';
$offset = 0;
if (preg_match('/-- ADD (\d+) to rowid/ims', file_get_contents($sqlfile), $reg)) {
	$offset += $reg[1];
}
$offset += $conf->entity * 100000000;
if (run_sql($sqlfile, 1, $conf->entity, 1, '', 'default', 32768, 0, $offset) <= 0) {
	fail('Kontenrahmen konnte nicht geladen werden');
}
$res = $db->query("SELECT rowid FROM ".$db->prefix()."accounting_system WHERE pcg_version = 'SKR03'");
$chart = $db->fetch_object($res)->rowid;
dolibarr_set_const($db, 'CHARTOFACCOUNTS', $chart, 'chaine', 0, '', $conf->entity);
$conf->global->CHARTOFACCOUNTS = $chart;
foreach (array('ACCOUNTING_ACCOUNT_CUSTOMER_DEPOSIT' => '1718', 'SALARIES_ACCOUNTING_ACCOUNT_CHARGE' => '4120') as $k => $v) {
	dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $conf->entity);
	$conf->global->$k = $v;
}

echo "4. Modul EÜR (legt Codes und Kontenzuordnung an)\n";
$r = activateModule('modEur');
if (!empty($r['errors'])) {
	fail('modEur: '.implode(', ', $r['errors']));
}
// Modules were enabled during this run: reload their settings (output dirs etc.)
$conf->setValues($db);
// No PDF generation while seeding (in memory only, the instance setting is not changed)
$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
$res = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix()."accounting_category_account");
echo "   Zugeordnete Konten: ".$db->fetch_object($res)->nb."\n";

/**
 * Accounting account rowid (SKR03)
 *
 * @param string $number Account number
 * @return int
 */
function acc($number)
{
	global $db, $conf;
	$res = $db->query("SELECT rowid FROM ".$db->prefix()."accounting_account WHERE fk_pcg_version = 'SKR03' AND account_number = '".$db->escape($number)."' AND entity = ".((int) $conf->entity));
	$obj = $res ? $db->fetch_object($res) : null;
	if (!$obj) {
		fail('Konto '.$number.' fehlt');
	}
	return (int) $obj->rowid;
}

echo "5. Stammdaten\n";
$bank = new Account($db);
$bank->ref = 'GIRO';
$bank->label = 'Geschäftskonto';
$bank->courant = Account::TYPE_CURRENT;
$bank->currency_code = 'EUR';
$bank->country_id = 5;
$bank->date_solde = d('2024-01-01');
$bank->solde = 0;
if ($bank->create($user) <= 0) {
	fail('Bankkonto: '.$bank->error);
}
$vir = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);

$customer = new Societe($db);
$customer->name = 'Kunde GmbH';
$customer->client = 1;
$customer->country_id = 5;
$customer->code_client = 'auto';
if ($customer->create($user) <= 0) {
	fail('Kunde: '.$customer->error);
}
$supplier = new Societe($db);
$supplier->name = 'Lieferant AG';
$supplier->fournisseur = 1;
$supplier->country_id = 5;
$supplier->code_fournisseur = 'auto';
if ($supplier->create($user) <= 0) {
	fail('Lieferant: '.$supplier->error);
}

/**
 * Create a validated customer invoice
 *
 * @param string                                        $date   Y-m-d
 * @param array<int,array{0:float,1:float,2:string}>    $lines  [net, vat rate, account]
 * @param int                                           $type   Facture type
 * @param int                                           $source Source invoice (credit note)
 * @param callable|null                                 $beforeValidate Hook on draft
 * @return Facture
 */
function invoice($date, $lines, $type = Facture::TYPE_STANDARD, $source = 0, $beforeValidate = null)
{
	global $db, $user, $customer;
	$f = new Facture($db);
	$f->socid = $customer->id;
	$f->date = d($date);
	$f->type = $type;
	$f->fk_facture_source = $source;
	if ($f->create($user) <= 0) {
		fail('Rechnung: '.$f->error.' '.implode(',', $f->errors));
	}
	foreach ($lines as $l) {
		$lineid = $f->addline('Leistung', $l[0], 1, $l[1]);
		if ($lineid <= 0) {
			fail('Rechnungszeile: '.$f->error);
		}
		q("UPDATE ".$db->prefix()."facturedet SET fk_code_ventilation = ".acc($l[2])." WHERE rowid = ".((int) $lineid));
	}
	if ($beforeValidate) {
		$beforeValidate($f);
	}
	if ($f->validate($user) <= 0) {
		fail('Validierung: '.$f->error.' '.implode(',', $f->errors));
	}
	$f->fetch($f->id);
	return $f;
}

/**
 * Customer payment
 *
 * @param string $date   Y-m-d
 * @param int    $fid    Invoice id
 * @param float  $amount Amount (negative = refund of credit note)
 * @return void
 */
function pay($date, $fid, $amount)
{
	global $db, $user, $vir, $bank;
	$p = new Paiement($db);
	$p->datepaye = d($date);
	$p->amounts = array($fid => $amount);
	$p->paiementid = $vir;
	if ($p->create($user, 0) <= 0) {
		fail('Zahlung: '.$p->error);
	}
	if ($p->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bank->id, '', '') <= 0) {
		fail('Bank: '.$p->error);
	}
}

/**
 * Discount from a credit note or deposit, one per VAT rate like Dolibarr does
 *
 * @param Facture $src Source invoice
 * @return int[] discount ids
 */
function toDiscount($src)
{
	global $db, $user;
	$byrate = array();
	foreach ($src->lines as $l) {
		$k = (string) $l->tva_tx;
		$byrate[$k] = array(
			'ht' => ($byrate[$k]['ht'] ?? 0) + abs($l->total_ht),
			'tva' => ($byrate[$k]['tva'] ?? 0) + abs($l->total_tva),
			'ttc' => ($byrate[$k]['ttc'] ?? 0) + abs($l->total_ttc),
		);
	}
	$ids = array();
	foreach ($byrate as $rate => $a) {
		$disc = new DiscountAbsolute($db);
		$disc->fk_soc = $src->socid;
		$disc->socid = $src->socid;
		$disc->fk_facture_source = $src->id;
		$disc->description = ($src->type == Facture::TYPE_CREDIT_NOTE ? '(CREDIT_NOTE)' : '(DEPOSIT)');
		$disc->amount_ht = $disc->multicurrency_amount_ht = $a['ht'];
		$disc->amount_tva = $disc->multicurrency_amount_tva = $a['tva'];
		$disc->amount_ttc = $disc->multicurrency_amount_ttc = $a['ttc'];
		$disc->tva_tx = (float) $rate;
		if ($disc->create($user) <= 0) {
			fail('Rabatt: '.$disc->error);
		}
		$ids[] = $disc->id;
	}
	return $ids;
}

/**
 * Validated and paid supplier invoice
 *
 * @param string                                     $date    Y-m-d
 * @param string                                     $paydate Y-m-d
 * @param array<int,array{0:float,1:float,2:string}> $lines   [net, vat rate, account]
 * @return void
 */
function supplierInvoice($date, $paydate, $lines)
{
	global $db, $user, $supplier, $vir, $bank;
	static $n = 0;
	$f = new FactureFournisseur($db);
	$f->socid = $supplier->id;
	$f->ref_supplier = 'LI-'.(++$n);
	$f->date = d($date);
	if ($f->create($user) <= 0) {
		fail('Lieferantenrechnung: '.$f->error);
	}
	foreach ($lines as $l) {
		$lineid = $f->addline('Einkauf', $l[0], $l[1], 0, 0, 1);
		if ($lineid <= 0) {
			fail('Lieferantenzeile: '.$f->error);
		}
		q("UPDATE ".$db->prefix()."facture_fourn_det SET fk_code_ventilation = ".acc($l[2])." WHERE rowid = ".((int) $lineid));
	}
	if ($f->validate($user) <= 0) {
		fail('Validierung Lieferant: '.$f->error);
	}
	$f->fetch($f->id);
	$p = new PaiementFourn($db);
	$p->datepaye = d($paydate);
	$p->amounts = array($f->id => $f->total_ttc);
	$p->paiementid = $vir;
	if ($p->create($user, 1) <= 0) {
		fail('Lieferantenzahlung: '.$p->error);
	}
	if ($p->addPaymentToBank($user, 'payment_supplier', '(SupplierInvoicePayment)', $bank->id, '', '') <= 0) {
		fail('Bank Lieferant: '.$p->error);
	}
}

echo "6. Testfälle\n";
$e = $conf->entity;
$p = $db->prefix();

// T1 standard
$f = invoice('2025-02-10', array(array(1000, 19, '8400')));
pay('2025-02-20', $f->id, 1190);

// T2 mixed rates across years
$f = invoice('2024-12-15', array(array(1000, 19, '8400'), array(500, 7, '8300')));
pay('2024-12-28', $f->id, 725);
pay('2025-01-15', $f->id, 1000);

// T3 Skonto
$f = invoice('2025-03-01', array(array(2000, 19, '8400')));
pay('2025-03-05', $f->id, 2332.40);
$f->setPaid($user, 'discount_vat', '2 % Skonto');

// T4 credit note refunded in cash
$f = invoice('2025-04-01', array(array(300, 19, '8400')));
pay('2025-04-02', $f->id, 357);
$cn = invoice('2025-04-10', array(array(100, 19, '8400')), Facture::TYPE_CREDIT_NOTE, $f->id);
pay('2025-04-12', $cn->id, -119);

// T5 credit note used as payment, mixed rates
$f = invoice('2025-05-01', array(array(1000, 19, '8400'), array(1000, 7, '8300')));
$cn = invoice('2025-05-10', array(array(200, 7, '8300')), Facture::TYPE_CREDIT_NOTE, $f->id);
foreach (toDiscount($cn) as $did) {
	$disc = new DiscountAbsolute($db);
	$disc->fetch($did);
	if ($disc->link_to_invoice(0, $f->id) <= 0) {
		fail('Gutschrift verrechnen: '.$disc->error);
	}
}
pay('2025-05-20', $f->id, 2046);

// T6 deposit invoice, deducted as line on the final invoice
$dep = invoice('2025-06-01', array(array(500, 19, '1718')), Facture::TYPE_DEPOSIT);
pay('2025-06-03', $dep->id, 595);
$discids = toDiscount($dep);
$f = invoice('2025-07-10', array(array(2000, 19, '8400')), Facture::TYPE_STANDARD, 0, function ($draft) use ($discids, $db) {
	foreach ($discids as $did) {
		if ($draft->insert_discount($did) <= 0) {
			fail('Anzahlung abziehen: '.$draft->error);
		}
	}
	q("UPDATE ".$db->prefix()."facturedet SET fk_code_ventilation = ".acc('1718')." WHERE fk_facture = ".((int) $draft->id)." AND fk_remise_except IS NOT NULL");
});
pay('2025-07-15', $f->id, 1785);

// T7 GWG + fixed asset purchase
supplierInvoice('2025-03-10', '2025-03-20', array(array(700, 19, '4855'), array(3000, 19, '0420')));

// T8 rent + entertainment
supplierInvoice('2025-12-01', '2025-12-30', array(array(800, 19, '4210'), array(100, 19, '4650')));

// T9 expense report (hotel 7 %, train ticket 50 gross at 19 %)
$fee = $db->fetch_object($db->query("SELECT id FROM ".$p."c_type_fees WHERE active = 1 ORDER BY id LIMIT 1"))->id;
$er = q("INSERT INTO ".$p."expensereport (ref, entity, total_ht, total_tva, total_ttc, date_debut, date_fin, date_create, fk_user_author, fk_statut, paid) VALUES ('ER2509-0001', ".$e.", 142.02, 14.98, 157, '2025-09-01', '2025-09-02', '2025-09-03 12:00:00', ".$user->id.", 6, 1)");
q("INSERT INTO ".$p."expensereport_det (fk_expensereport, fk_c_type_fees, comments, qty, value_unit, tva_tx, total_ht, total_tva, total_ttc, date, fk_code_ventilation) VALUES (".$er.", ".$fee.", 'Hotel', 1, 107, 7, 100, 7, 107, '2025-09-01', ".acc('4670').")");
q("INSERT INTO ".$p."expensereport_det (fk_expensereport, fk_c_type_fees, comments, qty, value_unit, tva_tx, total_ht, total_tva, total_ttc, date, fk_code_ventilation) VALUES (".$er.", ".$fee.", 'Bahn', 1, 50, 19, 42.02, 7.98, 50, '2025-09-01', ".acc('4670').")");
q("INSERT INTO ".$p."payment_expensereport (fk_expensereport, datec, datep, amount, fk_typepayment, fk_bank, fk_user_creat) VALUES (".$er.", '2025-09-05 12:00:00', '2025-09-05 12:00:00', 157, ".$vir.", 0, ".$user->id.")");

// T10 VAT payments (10-day rule)
foreach (array(
	array('2024-12-31', 1200, '2025-01-09'),
	array('2025-03-31', 500, '2025-04-10'),
	array('2025-06-30', -80, '2025-07-08'),
	array('2025-12-31', 300, '2026-01-08'),
) as $v) {
	$tid = q("INSERT INTO ".$p."tva (datec, datep, datev, amount, label, entity, paye) VALUES ('".$v[2]." 12:00:00', '".$v[2]."', '".$v[0]."', ".$v[1].", 'USt-VA bis ".$v[0]."', ".$e.", 1)");
	q("INSERT INTO ".$p."payment_vat (fk_tva, datec, datep, amount, fk_typepaiement, fk_bank, fk_user_creat) VALUES (".$tid.", '".$v[2]." 12:00:00', '".$v[2]." 12:00:00', ".$v[1].", ".$vir.", 0, ".$user->id.")");
}

// T11 loan, various payments, bank line without payment object
$loan = q("INSERT INTO ".$p."loan (entity, datec, label, capital, datestart, dateend, nbterm, rate, accountancy_account_capital, accountancy_account_insurance, accountancy_account_interest, fk_user_author, active) VALUES (".$e.", '2025-01-01', 'Darlehen Hausbank', 10000, '2025-01-01', '2029-12-31', 60, 3, '0650', '4360', '2110', ".$user->id.", 1)");
q("INSERT INTO ".$p."payment_loan (fk_loan, datec, datep, amount_capital, amount_insurance, amount_interest, fk_typepayment, fk_bank, fk_user_creat) VALUES (".$loan.", '2025-09-30 12:00:00', '2025-09-30 12:00:00', 1000, 20, 150, ".$vir.", 0, ".$user->id.")");
foreach (array(
	array('Privatentnahme', 0, 2000, '1800'),
	array('Privateinlage', 1, 500, '1890'),
	array('Kontoführung', 0, 12.50, '4970'),
	array('Zweifelhafte Forderung (ohne Zuordnung)', 0, 99, '1460'),
) as $i => $v) {
	q("INSERT INTO ".$p."payment_various (ref, label, datec, datep, datev, sens, amount, fk_typepayment, accountancy_code, entity, fk_user_author) VALUES ('".($i + 1)."', '".$db->escape($v[0])."', '2025-10-01 12:00:00', '2025-10-01', '2025-10-01', ".$v[1].", ".$v[2].", ".$vir.", '".$v[3]."', ".$e.", ".$user->id.")");
}
if ($bank->addline(d('2025-11-11'), 'VIR', 'Unbekannte Abbuchung', -45, '', 0, $user) <= 0) {
	fail('Bankzeile: '.$bank->error);
}

// T13 gift above 50 € (4635): input VAT not deductible, expense gross (§ 15 Abs. 1a UStG)
supplierInvoice('2025-08-10', '2025-08-15', array(array(100, 19, '4635')));

// T14 overpayment of an invoice with a 0 % line, used as discount on another invoice
$fa = invoice('2025-08-01', array(array(100, 19, '8400'), array(50, 0, '8337')));
pay('2025-08-05', $fa->id, 269);
$disc = new DiscountAbsolute($db);
$disc->fk_soc = $disc->socid = $fa->socid;
$disc->fk_facture_source = $fa->id;
$disc->description = '(EXCESS RECEIVED)';
$disc->amount_ht = $disc->amount_ttc = $disc->multicurrency_amount_ht = $disc->multicurrency_amount_ttc = 100;
$disc->amount_tva = $disc->multicurrency_amount_tva = 0;
$disc->tva_tx = 0;
if ($disc->create($user) <= 0) {
	fail('Überzahlung: '.$disc->error);
}
$fb = invoice('2025-08-10', array(array(500, 19, '8400')));
if ($disc->link_to_invoice(0, $fb->id) <= 0) {
	fail('Überzahlung verrechnen: '.$disc->error);
}
pay('2025-08-20', $fb->id, 495);

// T12 manual value
q("INSERT INTO ".$p."eur_manual (entity, year, code, amount, note) VALUES (".$e.", 2025, 'A_HOMEOFFICE', 720, '120 Tage × 6 €')");

echo "Fertig.\n";
