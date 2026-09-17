<?php
/* Copyright (C) 2026 Michael Plas
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    eur/admin/setup.php
 * \ingroup eur
 * \brief   EÜR setup page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/class/html.formsetup.class.php";

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "eur@eur"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$modulepart = GETPOST('modulepart', 'aZ09');

$formSetup = new FormSetup($db);
$item = $formSetup->newItem('EUR_KU_YEARS');
$item->cssClass = 'minwidth200';
$formSetup->newItem('EUR_ZEHNTAGE_DEFAULT')->setAsYesNo();
$formSetup->newItem('EUR_USTVA_DAUERFRIST')->setAsYesNo();


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'loadmapping') {
	// Own token check on top of the core CSRF protection (which depends on MAIN_SECURITY_CSRF_WITH_TOKEN)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden();
	}
	// The same idempotent file that runs on module activation
	$result = run_sql(dol_buildpath('/eur/sql/data_eur.sql', 0), 1, $conf->entity, 1, '', 'default');
	if ($result > 0) {
		setEventMessages($langs->trans("EurMappingLoaded"), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$title = "EurSetup";
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-eur page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

print $formSetup->generateOutput(true);
print '<br>';

// Mapping state
$nbaccounts = 0;
$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."accounting_category_account as ca";
$sql .= " INNER JOIN ".$db->prefix()."c_accounting_category as c ON c.rowid = ca.fk_accounting_category";
$sql .= " INNER JOIN ".$db->prefix()."c_accounting_report as r ON r.rowid = c.fk_report AND r.code = 'EUR' AND r.entity = ".((int) $conf->entity);
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbaccounts = (int) $obj->nb;
}
$nbchart = 0;
$resql = $db->query("SELECT COUNT(*) as nb FROM ".$db->prefix()."accounting_account WHERE entity = ".((int) $conf->entity));
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbchart = (int) $obj->nb;
}

print load_fiche_titre($langs->trans("EurEditMapping"), '', '');
print '<div class="opacitymedium">'.$langs->trans("EurLoadMappingHelp").'</div><br>';
if (!$nbchart) {
	print info_admin($langs->trans("EurNoChart"), 0, 0, 'warning');
}
print '<div>'.$nbaccounts.' Konten sind EÜR-Codes zugeordnet.</div><br>';
print '<div class="tabsAction" style="text-align: left">';
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=loadmapping&token='.newToken().'">'.$langs->trans("EurLoadMapping").'</a>';
print '<a class="butAction" href="'.DOL_URL_ROOT.'/accountancy/admin/categories_list.php?id=32&search_country_id=5">'.$langs->trans("EurEditMapping").'</a>';
print '</div>';

print '<br><div class="opacitymedium">'.$langs->trans("EurDisclaimer").'</div>';

llxFooter();
$db->close();
