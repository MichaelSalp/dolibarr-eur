<?php
/* Copyright (C) 2026 Michael Plas
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    eur/class/eur.class.php
 * \ingroup eur
 * \brief   Cash-basis computation of the Anlage EÜR (§ 4 Abs. 3 EStG)
 *
 * Data source are payments, never the ledger (the ledger is dated by invoice date).
 * Each payment is split over the lines of the paid document; each line carries its
 * accounting account (fk_code_ventilation), which maps to an EÜR code via the EUR report.
 *
 * Known limit: a credit note or deposit that is applied to an invoice later changes the pool of that
 * invoice, so earlier payments of it are split again (net/VAT shift, profit unchanged). A report for a
 * past year can therefore differ slightly from the one filed; keep the exported PDF/CSV as record.
 */

/**
 * EÜR computation
 */
class Eur
{
	/** @var DoliDB */
	public $db;

	/** @var array<string,float> code => amount in the natural direction of the code */
	public $codes = array();

	/** @var array<string,array<int,array<string,mixed>>> code => list of source rows */
	public $details = array();

	/** @var array<string,array{header:float,pieces:float}> channel => totals for check C1 */
	public $channels = array();

	/** @var array<int,array<string,mixed>> manual values of the year */
	public $manual = array();

	/** @var array<string,array{ok:bool,level:string,title:string,items:array<int,string>}> */
	public $checks = array();

	/** @var array<int,array{abz:float,nabz:float}> line => values */
	public $lines = array();

	/** @var array<string,mixed> form definition of the year */
	public $form = array();

	/** @var int Tax year (year of the end of the period) */
	public $year;
	/** @var string Start of period Y-m-d */
	public $dateFrom;
	/** @var string End of period Y-m-d */
	public $dateTo;
	/** @var bool Period is exactly twelve months (manual yearly values apply) */
	public $fullYear = true;
	/** @var bool Kleinunternehmer year (tax year of the report) */
	public $ku = false;
	/** @var int[] Kleinunternehmer years from setup */
	private $kuYears = array();
	/** @var bool 10-day rule applied */
	public $zehntage = true;

	/** @var string Active chart of accounts (pcg_version) */
	public $pcg = '';
	/** @var int Accounts of the active chart mapped to an EÜR code */
	public $mappedAccounts = 0;

	/** @var array<int,string> accounting account rowid => code */
	private $codeByRowid = array();
	/** @var array<string,string> account number (active chart) => code */
	private $codeByNumber = array();
	/** @var array<string,array<string,mixed>> document pools cache */
	private $pools = array();
	/** @var array<string,array<string,string>> check id => messages collected while computing */
	private $warn = array('C3' => array(), 'C6' => array(), 'C8' => array(), 'C10' => array());

	const CODE_NZ_IN = 'NZ_EIN';
	const CODE_NZ_OUT = 'NZ_AUS';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Available form years (files forms/YYYY.php)
	 *
	 * @return int[]
	 */
	public static function formYears()
	{
		$years = array();
		foreach (glob(__DIR__.'/../forms/*.php') as $f) {
			$years[] = (int) basename($f, '.php');
		}
		sort($years);
		return $years;
	}

	/**
	 * Load form definition. Falls back to the latest form not after $year.
	 *
	 * @param int $year Tax year
	 * @return array<string,mixed>
	 */
	public static function loadForm($year)
	{
		$use = 0;
		foreach (self::formYears() as $y) {
			if ($y <= $year) {
				$use = $y;
			}
		}
		if (!$use) {
			$years = self::formYears();
			$use = reset($years);
		}
		return include __DIR__.'/../forms/'.$use.'.php';
	}

	/**
	 * Split one payment amount over a document's lines. Pure function.
	 *
	 * Share = paid / denom. Net of each line goes to its code; VAT goes to '__VAT__'
	 * (Kleinunternehmer: gross to the line's code). Lines without code go to '__NZ__'.
	 * Result is rounded to cents so that the sum equals $paid exactly (largest remainder).
	 *
	 * @param array<int,array{code:?string,ht:float,vat:float}> $pool   Document lines incl. applied credit notes/deposits
	 * @param float                                           $denom  Amount the document is to be paid with (total_ttc minus discounts used as payment)
	 * @param float                                           $paid   Allocated payment amount
	 * @param bool                                            $ku     Kleinunternehmer year
	 * @return array<string,float>|null                               code => amount, null if denom is zero
	 */
	public static function allocate(array $pool, $denom, $paid, $ku)
	{
		if (abs($denom) < 0.005) {
			return null;
		}
		$share = $paid / $denom;
		$raw = array();
		foreach ($pool as $l) {
			$c = empty($l['code']) ? '__NZ__' : $l['code'];
			if ($ku) {
				$raw[$c] = ($raw[$c] ?? 0) + ($l['ht'] + $l['vat']) * $share;
			} else {
				$raw[$c] = ($raw[$c] ?? 0) + $l['ht'] * $share;
				$raw['__VAT__'] = ($raw['__VAT__'] ?? 0) + $l['vat'] * $share;
			}
		}

		// ponytail: rounding per payment, max 1 cent drift per payment per document; exact per-document rounding if it ever matters
		$target = (int) round($paid * 100);
		$cents = array();
		$rest = array();
		foreach ($raw as $c => $v) {
			$x = round($v * 100, 6);
			$cents[$c] = (int) floor($x);
			$rest[$c] = $x - floor($x);
		}
		$diff = $target - array_sum($cents);
		if (abs($diff) > count($cents)) {
			// Lines do not add up to the document total: keep what is certain, rest is unassigned
			$cents['__NZ__'] = ($cents['__NZ__'] ?? 0) + $diff;
		} elseif ($diff > 0) {
			arsort($rest);
			foreach (array_slice(array_keys($rest), 0, $diff) as $c) {
				$cents[$c]++;
			}
		} elseif ($diff < 0) {
			asort($rest);
			foreach (array_slice(array_keys($rest), 0, -$diff) as $c) {
				$cents[$c]--;
			}
		}

		$res = array();
		foreach ($cents as $c => $v) {
			if ($v != 0) {
				$res[$c] = $v / 100;
			}
		}
		return $res;
	}

	/**
	 * Tax year a VAT payment belongs to (§ 11 Abs. 1 Satz 2 EStG, 10-day rule). Pure function.
	 *
	 * A payment made between 22.12. and 10.01. that is also due in that window belongs to the
	 * year of its period when the period ends in the earlier year.
	 *
	 * @param int  $datep      Payment date (timestamp)
	 * @param int  $datev      End of VAT period (timestamp)
	 * @param bool $apply      Apply the rule
	 * @param bool $dauerfrist Dauerfristverlängerung (due one month later)
	 * @return int
	 */
	public static function vatYear($datep, $datev, $apply, $dauerfrist)
	{
		return (int) gmdate('Y', self::vatDate($datep, $datev, $apply, $dauerfrist));
	}

	/**
	 * Effective date of a VAT payment for period filtering (§ 11 Abs. 1 Satz 2 EStG). Pure function.
	 *
	 * Without the rule this is the payment date. When the rule moves a payment into the
	 * previous year, the effective date is 31.12. of that year.
	 *
	 * @param int  $datep      Payment date (timestamp, UTC)
	 * @param int  $datev      End of VAT period (timestamp, UTC)
	 * @param bool $apply      Apply the rule
	 * @param bool $dauerfrist Dauerfristverlängerung (due one month later)
	 * @return int Timestamp (UTC)
	 */
	public static function vatDate($datep, $datev, $apply, $dauerfrist)
	{
		if (!$apply || empty($datev)) {
			return $datep;
		}
		$py = (int) gmdate('Y', $datep);
		$due = strtotime(($dauerfrist ? '+1 month ' : '').'+10 days', $datev);
		$pm = gmdate('md', $datep);
		$boundary = ($pm >= '1222') ? $py : (($pm <= '0110') ? $py - 1 : null);
		if ($boundary === null || $boundary == $py) {
			return $datep;
		}
		$wStart = gmmktime(0, 0, 0, 12, 22, $boundary);
		$wEnd = gmmktime(23, 59, 59, 1, 10, $boundary + 1);
		if ($due >= $wStart && $due <= $wEnd && (int) gmdate('Y', $datev) == $boundary) {
			return gmmktime(12, 0, 0, 12, 31, $boundary);
		}
		return $datep;
	}

	/**
	 * Compute the EÜR for a year or an exact period
	 *
	 * @param int       $year     Tax year (used when no period is given)
	 * @param bool|null $zehntage Apply 10-day rule (null = setup default)
	 * @param string    $from     Start of period Y-m-d ('' = 01.01. of $year)
	 * @param string    $to       End of period Y-m-d ('' = 31.12. of $year)
	 * @return int 1 if OK, <0 if error
	 */
	public function compute($year, $zehntage = null, $from = '', $to = '')
	{
		global $conf, $mysoc;

		$this->dateFrom = $from ?: ((int) $year).'-01-01';
		$this->dateTo = $to ?: ((int) $year).'-12-31';
		// Tax year, form edition and Kleinunternehmer status follow the end of the period
		$this->year = (int) substr($this->dateTo, 0, 4);
		// Manual values are yearly values: only for a period of exactly twelve months
		$this->fullYear = (date('Y-m-d', strtotime($this->dateFrom.' +1 year -1 day')) == $this->dateTo);
		$this->zehntage = ($zehntage === null) ? (bool) getDolGlobalInt('EUR_ZEHNTAGE_DEFAULT', 1) : (bool) $zehntage;
		$this->kuYears = array_map('intval', array_filter(array_map('trim', explode(',', getDolGlobalString('EUR_KU_YEARS')))));
		$this->ku = in_array($this->year, $this->kuYears);
		$this->form = self::loadForm($this->year);
		$this->codes = $this->details = $this->channels = $this->checks = $this->lines = $this->pools = array();
		$this->warn = array('C3' => array(), 'C6' => array(), 'C8' => array(), 'C10' => array());

		$this->loadMapping();

		// Plain date strings: payment dates are stored in server time, a timestamp would shift the boundaries
		$start = $this->dateFrom.' 00:00:00';
		$end = $this->dateTo.' 23:59:59';

		$this->doInvoices('customer', $start, $end);
		$this->doInvoices('supplier', $start, $end);
		$this->doExpenseReports($start, $end);
		$this->doVat();
		$this->doSocialCharges($start, $end);
		$this->doSalaries($start, $end);
		$this->doVarious($start, $end);
		$this->doLoans($start, $end);
		$this->doDonations($start, $end);

		$this->loadManual();
		$this->buildLines();
		$this->buildChecks($start, $end);

		return 1;
	}

	/**
	 * Load account → code mapping of report EUR (n:m table)
	 *
	 * @return void
	 */
	private function loadMapping()
	{
		global $conf;

		$this->codeByRowid = $this->codeByNumber = array();
		$this->mappedAccounts = 0;
		$pcg = '';
		$sql = "SELECT pcg_version FROM ".$this->db->prefix()."accounting_system WHERE rowid = ".((int) getDolGlobalInt('CHARTOFACCOUNTS'));
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$pcg = $obj->pcg_version;
		}
		$this->pcg = $pcg;

		$sql = "SELECT c.code, a.rowid, a.account_number, a.fk_pcg_version";
		$sql .= " FROM ".$this->db->prefix()."accounting_category_account as ca";
		$sql .= " INNER JOIN ".$this->db->prefix()."c_accounting_category as c ON c.rowid = ca.fk_accounting_category AND c.active = 1";
		$sql .= " INNER JOIN ".$this->db->prefix()."c_accounting_report as r ON r.rowid = c.fk_report AND r.code = 'EUR' AND r.entity = ".((int) $conf->entity);
		$sql .= " INNER JOIN ".$this->db->prefix()."accounting_account as a ON a.rowid = ca.fk_accounting_account";
		$sql .= " WHERE c.entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$this->codeByRowid[(int) $obj->rowid] = $obj->code;
				if ($obj->fk_pcg_version == $pcg) {
					$this->codeByNumber[(string) $obj->account_number] = $obj->code;
					$this->mappedAccounts++;
				}
			}
		}
	}

	/**
	 * Add a cash flow to a code
	 *
	 * @param string|null          $code    EÜR code, null/'' = unassigned
	 * @param float                $flow    Signed cash flow (+ in, − out)
	 * @param string               $channel Channel key for check C1
	 * @param array<string,mixed>  $detail  Source row (date, source, ref, url, account, note)
	 * @return void
	 */
	private function addFlow($code, $flow, $channel, $detail)
	{
		$flow = round($flow, 2);
		if ($flow == 0) {
			return;
		}
		if (!isset($this->channels[$channel])) {
			$this->channels[$channel] = array('header' => 0.0, 'pieces' => 0.0);
		}
		$this->channels[$channel]['pieces'] = round($this->channels[$channel]['pieces'] + $flow, 2);

		if (empty($code)) {
			$code = ($flow > 0) ? self::CODE_NZ_IN : self::CODE_NZ_OUT;
			$value = abs($flow);
		} else {
			if ($code == 'A_UST_FA' && $flow > 0) {
				$code = 'E_UST_ERST';	// VAT refunded by the tax office is income (line 18)
			}
			$value = self::isIncomeCode($code) ? $flow : -$flow;
		}
		$this->codes[$code] = round(($this->codes[$code] ?? 0) + $value, 2);
		$detail['amount'] = $value;
		$this->details[$code][] = $detail;
	}

	/**
	 * Revenue of a Kleinunternehmer document or year goes to line 12 (code E_KU)
	 *
	 * @param string|null $code Code
	 * @param bool        $ku   Kleinunternehmer
	 * @return string|null
	 */
	private function kuCode($code, $ku)
	{
		return ($ku && $code && !empty($this->form['codes'][$code]['ku_line'])) ? 'E_KU' : $code;
	}

	/**
	 * Income-like codes count money in as positive, all others money out
	 *
	 * @param string $code Code
	 * @return bool
	 */
	public static function isIncomeCode($code)
	{
		return (strpos($code, 'E_') === 0 || $code == 'P_EINLAGE' || $code == self::CODE_NZ_IN);
	}

	/**
	 * Code for an account number of the active chart
	 *
	 * @param string $number Account number
	 * @return string|null
	 */
	private function codeForNumber($number)
	{
		$number = trim((string) $number);
		return ($number !== '' && isset($this->codeByNumber[$number])) ? $this->codeByNumber[$number] : null;
	}

	/**
	 * Register the header total of a channel for check C1
	 *
	 * @param string $channel Channel
	 * @param float  $amount  Signed cash flow
	 * @return void
	 */
	private function addHeader($channel, $amount)
	{
		if (!isset($this->channels[$channel])) {
			$this->channels[$channel] = array('header' => 0.0, 'pieces' => 0.0);
		}
		$this->channels[$channel]['header'] = round($this->channels[$channel]['header'] + $amount, 2);
	}

	/**
	 * Raw lines of an invoice (no discounts applied)
	 *
	 * @param string $type 'customer' or 'supplier'
	 * @param int    $id   Invoice id
	 * @return array<string,mixed>|null
	 */
	private function rawInvoice($type, $id)
	{
		global $conf;

		$key = 'raw'.$type.$id;
		if (isset($this->pools[$key])) {
			return $this->pools[$key];
		}
		if ($type == 'customer') {
			$sql = "SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.multicurrency_code, f.multicurrency_tx FROM ".$this->db->prefix()."facture as f WHERE f.rowid = ".((int) $id);
			$sqll = "SELECT d.total_ht, d.total_tva as vat, d.total_ttc, d.tva_tx, d.fk_code_ventilation, d.description, aa.account_number, aa.fk_pcg_version";
			$sqll .= " FROM ".$this->db->prefix()."facturedet as d LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.rowid = d.fk_code_ventilation";
			$sqll .= " WHERE d.fk_facture = ".((int) $id);
		} else {
			$sql = "SELECT f.rowid, f.ref, f.datef, f.total_ttc, f.multicurrency_code, f.multicurrency_tx FROM ".$this->db->prefix()."facture_fourn as f WHERE f.rowid = ".((int) $id);
			$sqll = "SELECT d.total_ht, d.tva as vat, d.total_ttc, d.tva_tx, d.fk_code_ventilation, d.description, aa.account_number, aa.fk_pcg_version";
			$sqll .= " FROM ".$this->db->prefix()."facture_fourn_det as d LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.rowid = d.fk_code_ventilation";
			$sqll .= " WHERE d.fk_facture_fourn = ".((int) $id);
		}
		$resql = $this->db->query($sql);
		if (!$resql || !($f = $this->db->fetch_object($resql))) {
			return $this->pools[$key] = null;
		}
		// Kleinunternehmer status follows the invoice date: revenue performed before switching to § 19 UStG stays in lines 15–17 (Anleitung zu Zeile 12)
		$datef = $this->db->jdate($f->datef);
		$doc = array('ref' => $f->ref, 'total_ttc' => (float) $f->total_ttc, 'lines' => array(), 'unbound' => 0, 'ku' => $datef ? in_array((int) dol_print_date($datef, '%Y'), $this->kuYears) : $this->ku);
		if (!empty($f->multicurrency_code) && $f->multicurrency_code != $conf->currency && (float) $f->multicurrency_tx != 1) {
			$this->warn['C8'][$type.$id] = $f->ref.' ('.$f->multicurrency_code.')';
		}
		$sumttc = 0;
		$resql = $this->db->query($sqll);
		while ($resql && ($d = $this->db->fetch_object($resql))) {
			$code = ((int) $d->fk_code_ventilation > 0) ? ($this->codeByRowid[(int) $d->fk_code_ventilation] ?? null) : null;
			if ((int) $d->fk_code_ventilation <= 0 && ((float) $d->total_ttc != 0)) {
				$doc['unbound']++;
			}
			if ($d->fk_pcg_version && $this->pcg && $d->fk_pcg_version != $this->pcg) {
				$this->warn['C10'][$type.$id] = $f->ref.' ('.$d->fk_pcg_version.' '.$d->account_number.')';
			}
			$doc['lines'][] = array('code' => $code, 'ht' => (float) $d->total_ht, 'vat' => (float) $d->vat, 'account' => (string) $d->account_number, 'desc' => $d->description, 'ttc' => (float) $d->total_ttc, 'tva_tx' => (float) $d->tva_tx);
			$sumttc += (float) $d->total_ttc;
		}
		$doc['consistent'] = (abs($sumttc - $doc['total_ttc']) <= 0.01 * max(1, count($doc['lines'])));
		return $this->pools[$key] = $doc;
	}

	/**
	 * Pool of an invoice incl. credit notes / deposits / overpayments used as payment
	 *
	 * @param string $type 'customer' or 'supplier'
	 * @param int    $id   Invoice id
	 * @return array<string,mixed>|null
	 */
	private function invoicePool($type, $id)
	{
		$key = 'pool'.$type.$id;
		if (isset($this->pools[$key])) {
			return $this->pools[$key];
		}
		$doc = $this->rawInvoice($type, $id);
		if ($doc === null) {
			return $this->pools[$key] = null;
		}
		$pool = array('ref' => $doc['ref'], 'lines' => $doc['lines'], 'denom' => $doc['total_ttc'], 'consistent' => $doc['consistent'], 'unbound' => $doc['unbound'], 'ku' => $doc['ku']);

		if ($type == 'customer') {
			$sql = "SELECT rc.amount_ttc, rc.tva_tx, rc.description, rc.fk_facture_source as src FROM ".$this->db->prefix()."societe_remise_except as rc";
			$sql .= " WHERE rc.fk_facture = ".((int) $id)." AND rc.fk_facture_source IS NOT NULL";
		} else {
			$sql = "SELECT rc.amount_ttc, rc.tva_tx, rc.description, rc.fk_invoice_supplier_source as src FROM ".$this->db->prefix()."societe_remise_except as rc";
			$sql .= " WHERE rc.fk_invoice_supplier = ".((int) $id)." AND rc.fk_invoice_supplier_source IS NOT NULL";
		}
		$resql = $this->db->query($sql);
		while ($resql && ($rc = $this->db->fetch_object($resql))) {
			$src = $this->rawInvoice($type, (int) $rc->src);
			if ($src === null || abs($src['total_ttc']) < 0.005) {
				$pool['consistent'] = false;
				continue;
			}
			// Credit notes/deposits: Dolibarr creates one discount per VAT rate, apply it to the source lines of that rate.
			// Overpayments ("EXCESS RECEIVED") carry rate 0 but were received over all lines: always use all lines.
			$excess = (strpos((string) $rc->description, 'EXCESS') !== false);
			$lines = $excess ? array() : array_filter($src['lines'], function ($l) use ($rc) {
				return abs($l['tva_tx'] - (float) $rc->tva_tx) < 0.001;
			});
			$base = array_sum(array_column($lines, 'ttc'));
			if (!$lines || abs($base) < 0.005) {
				$lines = $src['lines'];
				$base = $src['total_ttc'];
			}
			// Credit note (negative) adds its negative lines; deposit/overpayment (positive) removes lines already counted when that cash came in
			$k = (float) $rc->amount_ttc / abs($base) * ($base < 0 ? 1 : -1);
			foreach ($lines as $l) {
				$l['ht'] *= $k;
				$l['vat'] *= $k;
				$l['ttc'] *= $k;
				$pool['lines'][] = $l;
			}
			$pool['denom'] -= (float) $rc->amount_ttc;
			$pool['consistent'] = $pool['consistent'] && $src['consistent'];
			$pool['unbound'] += $src['unbound'];
		}
		return $this->pools[$key] = $pool;
	}

	/**
	 * Distribute one allocation of a payment onto codes
	 *
	 * @param array<string,mixed> $pool      Pool from invoicePool()/expense report
	 * @param float               $paid      Allocated amount (document direction)
	 * @param int                 $direction +1 money in (customer), −1 money out (supplier, expense report)
	 * @param string              $vatcode   Code for VAT part
	 * @param string              $channel   Channel
	 * @param array<string,mixed> $detail    Source row
	 * @return void
	 */
	private function distribute($pool, $paid, $direction, $vatcode, $channel, $detail)
	{
		$detail['doc'] = $pool['ref'];
		if (!$pool['consistent']) {
			$this->warn['C3'][$detail['doc']] = $detail['doc'];
			$this->addFlow(null, $direction * $paid, $channel, $detail + array('note' => 'Belegzeilen passen nicht zur Belegsumme'));
			return;
		}
		$ku = $pool['ku'] ?? $this->ku;
		$lines = array();
		foreach ($pool['lines'] as $l) {
			// Input VAT excluded by § 15 Abs. 1a UStG: the expense is entered gross, not in line 57
			if (!empty($l['code']) && !empty($this->form['codes'][$l['code']]['gross'])) {
				$l['ht'] += $l['vat'];
				$l['vat'] = 0;
			}
			$lines[] = $l;
		}
		$parts = self::allocate($lines, $pool['denom'], $paid, $ku);
		if ($parts === null) {
			$this->addFlow(null, $direction * $paid, $channel, $detail + array('note' => 'Beleg ohne zu zahlenden Betrag'));
			return;
		}
		$accounts = array();
		foreach ($pool['lines'] as $l) {
			if (!empty($l['code'])) {
				$accounts[$l['code']][$l['account']] = $l['account'];
			}
		}
		foreach ($parts as $code => $amount) {
			if ($code == '__VAT__') {
				$this->addFlow($vatcode, $direction * $amount, $channel, $detail);
			} elseif ($code == '__NZ__') {
				$note = $pool['unbound'] ? 'Belegzeile nicht kontiert' : 'Konto ohne EÜR-Zuordnung';
				$this->addFlow(null, $direction * $amount, $channel, $detail + array('note' => $note));
			} else {
				$this->addFlow($this->kuCode($code, $ku), $direction * $amount, $channel, $detail + array('account' => implode(', ', $accounts[$code] ?? array())));
			}
		}
	}

	/**
	 * Customer or supplier invoice payments
	 *
	 * @param string $type  'customer' or 'supplier'
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doInvoices($type, $start, $end)
	{
		if ($type == 'customer') {
			$ch = 'Kundenzahlungen';
			$direction = 1;
			$vatcode = 'E_UST';
			$sql = "SELECT p.rowid, p.ref, p.datep, p.amount FROM ".$this->db->prefix()."paiement as p";
			$sql .= " WHERE p.entity IN (".getEntity('invoice').")";
			$sqla = "SELECT pf.fk_paiement as pid, pf.fk_facture as fid, pf.amount FROM ".$this->db->prefix()."paiement_facture as pf WHERE pf.fk_paiement IN (%s)";
			$urlp = '/compta/paiement/card.php?id=';
			$urlf = '/compta/facture/card.php?facid=';
		} else {
			$ch = 'Lieferantenzahlungen';
			$direction = -1;
			$vatcode = 'A_VST';
			$sql = "SELECT p.rowid, p.ref, p.datep, p.amount FROM ".$this->db->prefix()."paiementfourn as p";
			$sql .= " WHERE p.entity IN (".getEntity('supplier_invoice').")";
			$sqla = "SELECT pf.fk_paiementfourn as pid, pf.fk_facturefourn as fid, pf.amount FROM ".$this->db->prefix()."paiementfourn_facturefourn as pf WHERE pf.fk_paiementfourn IN (%s)";
			$urlp = '/fourn/paiement/card.php?id=';
			$urlf = '/fourn/facture/card.php?facid=';
		}
		$sql .= " AND p.datep BETWEEN '".$start."' AND '".$end."'";
		$payments = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$payments[(int) $obj->rowid] = $obj;
			$this->addHeader($ch, $direction * (float) $obj->amount);
		}
		if (empty($payments)) {
			return;
		}
		$alloc = array();
		$resql = $this->db->query(sprintf($sqla, implode(',', array_keys($payments))));
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$alloc[(int) $obj->pid][] = $obj;
		}
		foreach ($payments as $pid => $p) {
			$detail = array('date' => $this->db->jdate($p->datep), 'source' => $ch, 'ref' => $p->ref, 'url' => $urlp.$pid);
			$sum = 0;
			foreach ($alloc[$pid] ?? array() as $a) {
				$sum += (float) $a->amount;
				$pool = $this->invoicePool($type, (int) $a->fid);
				if ($pool === null) {
					$this->addFlow(null, $direction * (float) $a->amount, $ch, $detail + array('note' => 'Rechnung nicht gefunden'));
					continue;
				}
				$this->distribute($pool, (float) $a->amount, $direction, $vatcode, $ch, $detail + array('docurl' => $urlf.(int) $a->fid));
			}
			$rest = round((float) $p->amount - $sum, 2);
			if ($rest != 0) {
				$this->addFlow(null, $direction * $rest, $ch, $detail + array('note' => 'Zahlung keiner Rechnung zugeordnet'));
			}
		}
	}

	/**
	 * Expense report payments
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doExpenseReports($start, $end)
	{
		$ch = 'Spesenabrechnungen';
		$sql = "SELECT pe.rowid, pe.datep, pe.amount, e.rowid as eid, e.ref, e.total_ttc, e.date_debut";
		$sql .= " FROM ".$this->db->prefix()."payment_expensereport as pe";
		$sql .= " INNER JOIN ".$this->db->prefix()."expensereport as e ON e.rowid = pe.fk_expensereport";
		$sql .= " WHERE e.entity IN (".getEntity('expensereport').")";
		$sql .= " AND pe.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		$rows = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$this->addHeader($ch, -(float) $obj->amount);
			$key = 'er'.$obj->eid;
			if (!isset($this->pools[$key])) {
				$pool = array('ref' => $obj->ref, 'lines' => array(), 'denom' => (float) $obj->total_ttc, 'unbound' => 0, 'ku' => in_array((int) substr((string) $obj->date_debut, 0, 4), $this->kuYears));
				$sqll = "SELECT d.total_ht, d.total_tva, d.total_ttc, d.fk_code_ventilation, aa.account_number FROM ".$this->db->prefix()."expensereport_det as d";
				$sqll .= " LEFT JOIN ".$this->db->prefix()."accounting_account as aa ON aa.rowid = d.fk_code_ventilation WHERE d.fk_expensereport = ".((int) $obj->eid);
				$sum = 0;
				$resl = $this->db->query($sqll);
				while ($resl && ($d = $this->db->fetch_object($resl))) {
					$code = ((int) $d->fk_code_ventilation > 0) ? ($this->codeByRowid[(int) $d->fk_code_ventilation] ?? null) : null;
					if ((int) $d->fk_code_ventilation <= 0) {
						$pool['unbound']++;
					}
					$pool['lines'][] = array('code' => $code, 'ht' => (float) $d->total_ht, 'vat' => (float) $d->total_tva, 'account' => (string) $d->account_number);
					$sum += (float) $d->total_ttc;
				}
				$pool['consistent'] = (abs($sum - $pool['denom']) <= 0.01 * max(1, count($pool['lines'])));
				$this->pools[$key] = $pool;
			}
			$detail = array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->ref, 'url' => '/expensereport/card.php?id='.((int) $obj->eid), 'docurl' => '/expensereport/card.php?id='.((int) $obj->eid));
			$this->distribute($this->pools[$key], (float) $obj->amount, -1, 'A_VST', $ch, $detail);
		}
	}

	/**
	 * VAT payments to / refunds from the tax office, with optional 10-day rule
	 *
	 * @return void
	 */
	private function doVat()
	{
		$ch = 'Umsatzsteuer-Zahlungen';
		// Payments up to 10 days after the period can be moved into it by the 10-day rule
		$from = $this->dateFrom.' 00:00:00';
		$to = date('Y-m-d', strtotime($this->dateTo.' +11 days')).' 23:59:59';
		$pStart = gmmktime(0, 0, 0, (int) substr($this->dateFrom, 5, 2), (int) substr($this->dateFrom, 8, 2), (int) substr($this->dateFrom, 0, 4));
		$pEnd = gmmktime(23, 59, 59, (int) substr($this->dateTo, 5, 2), (int) substr($this->dateTo, 8, 2), (int) substr($this->dateTo, 0, 4));
		$sql = "SELECT pv.rowid, pv.datep, pv.amount, t.rowid as tid, t.datev, t.label";
		$sql .= " FROM ".$this->db->prefix()."payment_vat as pv";
		$sql .= " INNER JOIN ".$this->db->prefix()."tva as t ON t.rowid = pv.fk_tva";
		$sql .= " WHERE t.entity IN (".getEntity('tax').")";
		$sql .= " AND pv.datep BETWEEN '".$from."' AND '".$to."'";
		$resql = $this->db->query($sql);
		$dauerfrist = (bool) getDolGlobalInt('EUR_USTVA_DAUERFRIST');
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$datep = $this->db->jdate($obj->datep, true);
			$datev = $this->db->jdate($obj->datev, true);
			$effective = self::vatDate($datep, $datev, $this->zehntage, $dauerfrist);
			if ($effective < $pStart || $effective > $pEnd) {
				continue;
			}
			// A refund is stored as a negative payment
			$this->addHeader($ch, -(float) $obj->amount);
			$note = ($effective != $datep) ? '10-Tage-Regel: Zeitraum '.dol_print_date($datev, 'day', 'gmt') : '';
			$this->addFlow('A_UST_FA', -(float) $obj->amount, $ch, array('date' => $datep, 'source' => $ch, 'ref' => $obj->label, 'url' => '/compta/tva/card.php?id='.((int) $obj->tid), 'note' => $note));
		}
	}

	/**
	 * Social and fiscal charges
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doSocialCharges($start, $end)
	{
		$ch = 'Sozialabgaben/Steuern';
		$sql = "SELECT pc.rowid, pc.datep, pc.amount, cs.rowid as csid, cs.libelle, c.accountancy_code";
		$sql .= " FROM ".$this->db->prefix()."paiementcharge as pc";
		$sql .= " INNER JOIN ".$this->db->prefix()."chargesociales as cs ON cs.rowid = pc.fk_charge";
		$sql .= " LEFT JOIN ".$this->db->prefix()."c_chargesociales as c ON c.id = cs.fk_type";
		$sql .= " WHERE cs.entity IN (".getEntity('tax').")";
		$sql .= " AND pc.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$this->addHeader($ch, -(float) $obj->amount);
			$this->addFlow($this->codeForNumber($obj->accountancy_code), -(float) $obj->amount, $ch, array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->libelle, 'url' => '/compta/sociales/card.php?id='.((int) $obj->csid), 'account' => $obj->accountancy_code, 'note' => $this->codeForNumber($obj->accountancy_code) ? '' : 'Konto am Abgabentyp fehlt oder ohne Zuordnung'));
		}
	}

	/**
	 * Salary payments (account SALARIES_ACCOUNTING_ACCOUNT_CHARGE)
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doSalaries($start, $end)
	{
		$ch = 'Gehälter';
		$account = getDolGlobalString('SALARIES_ACCOUNTING_ACCOUNT_CHARGE');
		$sql = "SELECT ps.rowid, ps.datep, ps.amount, ps.label FROM ".$this->db->prefix()."payment_salary as ps";
		$sql .= " WHERE ps.entity IN (".getEntity('salary').")";
		$sql .= " AND ps.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$this->addHeader($ch, -(float) $obj->amount);
			$this->addFlow($this->codeForNumber($account), -(float) $obj->amount, $ch, array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->label, 'url' => '/salaries/payment_salary/card.php?id='.((int) $obj->rowid), 'account' => $account, 'note' => $this->codeForNumber($account) ? '' : 'Gehaltskonto (SALARIES_ACCOUNTING_ACCOUNT_CHARGE) fehlt'));
		}
	}

	/**
	 * Various payments (private withdrawals/contributions, fees, ...)
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doVarious($start, $end)
	{
		$ch = 'Sonstige Zahlungen';
		$sql = "SELECT pv.rowid, pv.ref, pv.label, pv.datep, pv.amount, pv.sens, pv.accountancy_code FROM ".$this->db->prefix()."payment_various as pv";
		$sql .= " WHERE pv.entity IN (".getEntity('variouspayment').")";
		$sql .= " AND pv.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$flow = ((int) $obj->sens == 1 ? 1 : -1) * (float) $obj->amount;
			$this->addHeader($ch, $flow);
			$code = $this->kuCode($this->codeForNumber($obj->accountancy_code), $this->ku);
			$this->addFlow($code, $flow, $ch, array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->label, 'url' => '/compta/bank/various_payment/card.php?id='.((int) $obj->rowid), 'account' => $obj->accountancy_code, 'note' => $code ? '' : 'Konto ohne EÜR-Zuordnung'));
		}
	}

	/**
	 * Loan payments: capital, insurance and interest separately
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doLoans($start, $end)
	{
		$ch = 'Darlehen';
		$sql = "SELECT pl.rowid, pl.datep, pl.amount_capital, pl.amount_insurance, pl.amount_interest, l.rowid as lid, l.label,";
		$sql .= " l.accountancy_account_capital, l.accountancy_account_insurance, l.accountancy_account_interest";
		$sql .= " FROM ".$this->db->prefix()."payment_loan as pl";
		$sql .= " INNER JOIN ".$this->db->prefix()."loan as l ON l.rowid = pl.fk_loan";
		$sql .= " WHERE l.entity IN (".getEntity('loan').")";
		$sql .= " AND pl.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$base = array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->label, 'url' => '/loan/card.php?id='.((int) $obj->lid));
			$this->addHeader($ch, -((float) $obj->amount_capital + (float) $obj->amount_insurance + (float) $obj->amount_interest));
			foreach (array('capital' => 'Tilgung', 'insurance' => 'Versicherung', 'interest' => 'Zinsen') as $part => $label) {
				$account = $obj->{'accountancy_account_'.$part};
				$code = $this->codeForNumber($account);
				$this->addFlow($code, -(float) $obj->{'amount_'.$part}, $ch, $base + array('account' => $account, 'note' => $label.($code ? '' : ': Konto ohne EÜR-Zuordnung')));
			}
		}
	}

	/**
	 * Donations received
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function doDonations($start, $end)
	{
		$ch = 'Spenden';
		$account = getDolGlobalString('DONATION_ACCOUNTINGACCOUNT');
		$sql = "SELECT pd.rowid, pd.datep, pd.amount, d.rowid as did, d.ref FROM ".$this->db->prefix()."payment_donation as pd";
		$sql .= " INNER JOIN ".$this->db->prefix()."don as d ON d.rowid = pd.fk_donation";
		$sql .= " WHERE d.entity IN (".getEntity('donation').")";
		$sql .= " AND pd.datep BETWEEN '".$start."' AND '".$end."'";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$this->addHeader($ch, (float) $obj->amount);
			$this->addFlow($this->kuCode($this->codeForNumber($account), $this->ku), (float) $obj->amount, $ch, array('date' => $this->db->jdate($obj->datep), 'source' => $ch, 'ref' => $obj->ref, 'url' => '/don/card.php?id='.((int) $obj->did), 'account' => $account));
		}
	}

	/**
	 * Manual values of the year
	 *
	 * @return void
	 */
	private function loadManual()
	{
		global $conf;

		$this->manual = array();
		if (!$this->fullYear) {
			return;
		}
		$sql = "SELECT rowid, code, amount, note FROM ".$this->db->prefix()."eur_manual WHERE entity = ".((int) $conf->entity)." AND year = ".((int) $this->year)." ORDER BY code";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$this->manual[] = array('rowid' => (int) $obj->rowid, 'code' => $obj->code, 'amount' => (float) $obj->amount, 'note' => $obj->note);
		}
	}

	/**
	 * Aggregate codes (cash + manual) into form lines and compute sums
	 *
	 * @return void
	 */
	private function buildLines()
	{
		$values = $this->codes;
		foreach ($this->manual as $m) {
			$values[$m['code']] = round(($values[$m['code']] ?? 0) + $m['amount'], 2);
		}
		foreach ($this->form['lines'] as $line => $def) {
			$this->lines[$line] = array('abz' => 0.0, 'nabz' => 0.0);
		}
		foreach ($values as $code => $v) {
			$def = $this->form['codes'][$code] ?? null;
			if (empty($def['line'])) {
				continue;
			}
			$line = $def['line'];
			if (!empty($def['split'])) {
				$this->lines[$line]['abz'] += round($v * $def['split']['abz'], 2);
				$this->lines[$line]['nabz'] += round($v - round($v * $def['split']['abz'], 2), 2);
			} elseif (($def['col'] ?? '') == 'nabz') {
				$this->lines[$line]['nabz'] += $v;
			} else {
				$this->lines[$line]['abz'] += $v;
			}
		}
		// Sums are defined in order of dependency in the form file
		foreach ($this->form['lines'] as $line => $def) {
			if (!empty($def['sum'])) {
				$s = 0;
				foreach ($def['sum'] as $l => $sign) {
					$s += $sign * ($this->lines[$l]['abz'] ?? 0);
				}
				$this->lines[$line]['abz'] = round($s, 2);
			}
		}
		foreach ($this->lines as $line => $v) {
			$this->lines[$line] = array('abz' => round($v['abz'], 2), 'nabz' => round($v['nabz'], 2));
		}
	}

	/**
	 * Build checks C1–C9
	 *
	 * @param string $start Start of year (Y-m-d H:i:s)
	 * @param string $end   End of year (Y-m-d H:i:s)
	 * @return void
	 */
	private function buildChecks($start, $end)
	{
		global $conf, $mysoc;

		// C0 mapping exists for the active chart of accounts (independent of whether there are payments)
		$items = array();
		if (!$this->pcg) {
			$items[] = 'In Dolibarr ist kein Kontenrahmen ausgewählt (Buchhaltung → Einstellungen → Kontenplan). Ohne Konten kann nichts zugeordnet werden.';
		} elseif (!$this->mappedAccounts) {
			$items[] = 'Für den aktiven Kontenrahmen '.$this->pcg.' ist kein einziges Konto einem EÜR-Code zugeordnet. In den Moduleinstellungen „Standardzuordnung laden“ (SKR03/SKR04) oder Konten selbst zuordnen.';
		}
		$this->addCheck('C0', empty($items), 'error', 'Kontenzuordnung vorhanden'.($this->mappedAccounts ? ' ('.$this->mappedAccounts.' Konten, '.$this->pcg.')' : ''), $items);

		// C1 cash reconciliation per channel
		$items = array();
		foreach ($this->channels as $ch => $t) {
			if (abs($t['header'] - $t['pieces']) >= 0.005) {
				$items[] = $ch.': Zahlungen '.price($t['header']).' ≠ zugeordnet '.price($t['pieces']);
			}
		}
		$this->addCheck('C1', empty($items), 'error', 'Kassenabstimmung: Summe der Zahlungen = Summe aller Zuordnungen', $items);

		// C2 unassigned amounts
		$items = array();
		foreach (array(self::CODE_NZ_IN, self::CODE_NZ_OUT) as $c) {
			foreach ($this->details[$c] ?? array() as $d) {
				$items[] = dol_print_date($d['date'], 'day', 'gmt').' '.$d['source'].' '.$d['ref'].(empty($d['doc']) ? '' : ' / '.$d['doc']).': '.price($d['amount']).' – '.($d['note'] ?? '');
			}
		}
		$this->addCheck('C2', empty($items), 'error', 'Nicht zugeordnete Beträge (Einnahmen '.price($this->codes[self::CODE_NZ_IN] ?? 0).', Ausgaben '.price($this->codes[self::CODE_NZ_OUT] ?? 0).')', $items);

		$this->addCheck('C3', empty($this->warn['C3']), 'error', 'Belege, deren Zeilen nicht zur Belegsumme passen (komplett nicht zugeordnet)', array_values($this->warn['C3']));

		// C4 bank lines without payment object
		$items = array();
		$p = $this->db->prefix();
		$sql = "SELECT b.rowid, b.dateo, b.amount, b.label FROM ".$p."bank as b";
		$sql .= " INNER JOIN ".$p."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$start."' AND '".$end."'";
		$sql .= " AND b.fk_type <> 'SOLD'";
		$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$p."bank_url as u WHERE u.fk_bank = b.rowid AND u.type = 'banktransfert')";
		foreach (array('paiement', 'paiementfourn', 'payment_vat', 'paiementcharge', 'payment_salary', 'payment_expensereport', 'payment_various', 'payment_loan', 'payment_donation') as $t) {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM ".$p.$t." as x WHERE x.fk_bank = b.rowid)";
		}
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$items[] = dol_print_date($this->db->jdate($obj->dateo), 'day').' '.$obj->label.': '.price($obj->amount).' (Bankzeile '.$obj->rowid.')';
		}
		$this->addCheck('C4', empty($items), 'error', 'Bankbuchungen ohne Zahlungsbeleg (fehlen in der EÜR)', $items);

		// C5 account mapped to more than one EÜR code
		$items = array();
		$sql = "SELECT a.account_number, a.fk_pcg_version, COUNT(DISTINCT c.code) as nb FROM ".$p."accounting_category_account as ca";
		$sql .= " INNER JOIN ".$p."c_accounting_category as c ON c.rowid = ca.fk_accounting_category";
		$sql .= " INNER JOIN ".$p."c_accounting_report as r ON r.rowid = c.fk_report AND r.code = 'EUR' AND r.entity = ".((int) $conf->entity);
		$sql .= " INNER JOIN ".$p."accounting_account as a ON a.rowid = ca.fk_accounting_account AND a.entity = ".((int) $conf->entity);
		$sql .= " WHERE c.entity = ".((int) $conf->entity)." AND c.active = 1";
		$sql .= " GROUP BY a.account_number, a.fk_pcg_version HAVING COUNT(DISTINCT c.code) > 1";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$items[] = $obj->fk_pcg_version.' '.$obj->account_number.' ist '.$obj->nb.' EÜR-Codes zugeordnet';
		}
		$this->addCheck('C5', empty($items), 'error', 'Konten mit mehreren EÜR-Zuordnungen', $items);

		// C6 GWG lines above the limit
		$items = array();
		$limit = 800;
		foreach ($this->details['A_GWG'] ?? array() as $d) {
			if ($d['amount'] > $limit) {
				$items[] = ($d['doc'] ?? $d['ref']).': '.price($d['amount']).' '.($this->ku ? 'brutto' : 'netto').' > '.price($limit).' (GWG-Grenze, ggf. AfA statt Sofortabzug)';
			}
		}
		$this->addCheck('C6', empty($items), 'warning', 'Geringwertige Wirtschaftsgüter über 800 €', $items);

		// C7 asset purchase without manual AfA
		$hasAfa = false;
		foreach ($this->manual as $m) {
			if (in_array($m['code'], array('A_AFA_GRUND', 'A_AFA_IMMAT', 'A_AFA_BEW', 'A_SONDER_AFA'))) {
				$hasAfa = true;
			}
		}
		$avkauf = $this->codes['N_AV_KAUF'] ?? 0;
		if ($this->fullYear) {
			$this->addCheck('C7', !($avkauf > 0 && !$hasAfa), 'warning', 'Anlagenkauf ohne eingetragene AfA', ($avkauf > 0 && !$hasAfa) ? array('Anschaffungen '.price($avkauf).' im Jahr, aber keine AfA als manueller Wert erfasst (Zeilen 31–34)') : array());
		} else {
			$this->addCheck('C7', false, 'warning', 'Manuelle Jahreswerte (AfA, Pauschalen) nicht enthalten', array('Der Zeitraum umfasst keine zwölf Monate. Manuelle Werte sind Jahreswerte und werden nur bei einem vollen Wirtschaftsjahr eingerechnet.'));
		}

		$this->addCheck('C8', empty($this->warn['C8']), 'warning', 'Belege in Fremdwährung (Kursdifferenzen nur anteilig verteilt)', array_values($this->warn['C8']));

		// C10 documents bound to accounts of another chart: number-based channels (various payments, charges,
		// salaries, loans) are read with the active chart and may then land on a different line
		$items = array_values($this->warn['C10']);
		if ($items) {
			array_unshift($items, 'Aktiver Kontenrahmen ist '.$this->pcg.'. Sonstige Zahlungen, Abgaben, Gehälter und Darlehen werden über Kontonummern dieses Kontenrahmens zugeordnet – bitte prüfen.');
		}
		$this->addCheck('C10', empty($items), 'error', 'Belege mit Konten eines anderen Kontenrahmens', $items);

		// C9 Kleinunternehmer setting vs company VAT setting (current year only)
		$items = array();
		if ($this->year == (int) dol_print_date(dol_now(), '%Y')) {
			$assuj = !empty($mysoc->tva_assuj);
			if ($this->ku && $assuj) {
				$items[] = 'Jahr ist als Kleinunternehmer-Jahr eingestellt, die Firma ist aber als umsatzsteuerpflichtig hinterlegt';
			} elseif (!$this->ku && !$assuj) {
				$items[] = 'Firma ist ohne Umsatzsteuer hinterlegt, das Jahr ist aber nicht als Kleinunternehmer-Jahr eingestellt';
			}
		}
		$this->addCheck('C9', empty($items), 'warning', 'Kleinunternehmer-Einstellung', $items);
	}

	/**
	 * @param string            $id    Check id
	 * @param bool              $ok    Passed
	 * @param string            $level 'error' (makes report preliminary) or 'warning'
	 * @param string            $title Title
	 * @param array<int,string> $items Findings
	 * @return void
	 */
	private function addCheck($id, $ok, $level, $title, $items)
	{
		$this->checks[$id] = array('ok' => (bool) $ok, 'level' => $level, 'title' => $title, 'items' => $items);
	}

	/**
	 * True if any error-level check failed (report is preliminary)
	 *
	 * @return bool
	 */
	public function isPreliminary()
	{
		foreach ($this->checks as $c) {
			if (!$c['ok'] && $c['level'] == 'error') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Labels of all codes (catalogue from report EUR plus manual-only codes of the form)
	 *
	 * @return array<string,string>
	 */
	public function codeLabels()
	{
		global $conf, $langs;

		$labels = array();
		$sql = "SELECT c.code, c.label FROM ".$this->db->prefix()."c_accounting_category as c";
		$sql .= " INNER JOIN ".$this->db->prefix()."c_accounting_report as r ON r.rowid = c.fk_report AND r.code = 'EUR'";
		$sql .= " WHERE c.entity = ".((int) $conf->entity)." ORDER BY c.position";
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$labels[$obj->code] = $obj->label;
		}
		foreach ($this->form['codes'] as $code => $def) {
			if (!isset($labels[$code])) {
				$labels[$code] = empty($def['line']) ? $code : $this->form['lines'][$def['line']][0];
			}
		}
		$labels[self::CODE_NZ_IN] = $langs->trans('EurUnassignedIn');
		$labels[self::CODE_NZ_OUT] = $langs->trans('EurUnassignedOut');
		return $labels;
	}
}
