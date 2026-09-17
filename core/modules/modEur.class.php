<?php
/* Copyright (C) 2026 Michael Plas
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    eur/core/modules/modEur.class.php
 * \ingroup eur
 * \brief   Descriptor of module EÜR (Einnahmenüberschussrechnung, Anlage EÜR)
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for EÜR
 */
class modEur extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 500100;
		$this->rights_class = 'eur';
		$this->family = "financial";
		$this->module_position = '65';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "ModuleEurDesc";
		$this->descriptionlong = "ModuleEurDesc";
		$this->editor_name = 'Michael Plas';
		$this->version = '1.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'accounting';

		$this->module_parts = array();
		$this->dirs = array("/eur/temp");
		$this->config_page_url = array("setup.php@eur");

		// The mapping tables (llx_c_accounting_*) only exist once the accountancy module is active
		$this->depends = array('modAccounting', 'modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("eur@eur");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(24, 0);

		// The EÜR mapping lives in the n:m table so it does not steal fk_accounting_category from other reports
		$this->const = array(
			1 => array('ACCOUNTING_ENABLE_MULTI_REPORT', 'chaine', '1', 'Required by module EÜR', 0, 'current', 0),
			2 => array('EUR_ZEHNTAGE_DEFAULT', 'chaine', '1', 'EÜR: 10-Tage-Regel standardmäßig anwenden', 0, 'current', 0),
		);

		if (!isModEnabled("eur")) {
			$conf->eur = new stdClass();
			$conf->eur->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'EÜR anzeigen und exportieren';
		$this->rights[$r][4] = 'report';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Manuelle EÜR-Werte pflegen';
		$this->rights[$r][4] = 'report';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=accountancy',
			'type' => 'left',
			'titre' => 'MenuEur',
			'prefix' => img_picto('', 'accounting', 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'accountancy',
			'leftmenu' => 'eur',
			'url' => '/eur/eur_report.php',
			'langs' => 'eur@eur',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('eur')",
			'perms' => '$user->hasRight("eur", "report", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Called when module is enabled: creates tables, loads code catalogue and default account mapping
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1> 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/eur/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Called when module is disabled. Data (manual values, mapping) is kept.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int<-1,1> 1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
