<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include('./include/auth.php');
include_once('./lib/utility.php');
include_once('./lib/api_graph.php');
include_once('./lib/api_tree.php');
include_once('./lib/api_data_source.php');
include_once('./lib/api_aggregate.php');
include_once('./lib/template.php');
include_once('./lib/html_tree.php');
include_once('./lib/html_form_template.php');
include_once('./lib/rrd.php');
include_once('./lib/data_query.php');

validate_graph_request_vars();

$graph_actions = array(
	1  => __('Delete'),
);

if (get_nfilter_request_var('template_id') > 0) {
	$graph_actions += array(
		2  => __('Change Graph Template'),
	);
}

$graph_actions += array(
	5  => __('Change Device'),
	6  => __('Reapply Suggested Names'),
    9  => __('Create Aggregate Graph'),
    10 => __('Create Aggregate from Template'),
	8  => __('Apply Automation Rules')
);

$graph_actions = api_plugin_hook_function('graphs_action_array', $graph_actions);

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'item':
		top_header();
		item();
		bottom_footer();

		break;
	case 'graph_remove':
		graph_remove();

		header('Location: graphs.php?header=false');
		break;
	case 'ajax_hosts':
		get_allowed_ajax_hosts();

		break;
	case 'ajax_hosts_noany':
		get_allowed_ajax_hosts(false);

		break;
	case 'lock':
	case 'unlock':
		$_SESSION['sess_graph_lock_id'] = get_filter_request_var('id');
		$_SESSION['sess_graph_locked']  = (get_request_var('action') == 'lock' ? true:false);
	case 'graph_edit':
		top_header();
		graph_edit();
		bottom_footer();

		break;
	default:
		top_header();
		graph_management();
		bottom_footer();

		break;
}

/* --------------------------
    Global Form Functions
   -------------------------- */

function add_tree_names_to_actions_array() {
	global $graph_actions;

	/* add a list of tree names to the actions dropdown */
	$trees = db_fetch_assoc('SELECT id, name FROM graph_tree ORDER BY name');

	if (sizeof($trees)) {
		foreach ($trees as $tree) {
			$graph_actions{'tr_' . $tree['id']} = __('Place on a Tree (%s)', $tree['name']);
		}
	}
}

/* --------------------------
    The Save Function
   -------------------------- */

function parse_validate_graph_template_id($variable) {
	$output_type_id = 0;
	if (strpos(get_nfilter_request_var($variable), '_') !== false) {
		$template_parts = explode('_', get_nfilter_request_var($variable));
		if (is_numeric($template_parts[0]) && is_numeric($template_parts[1])) {
			set_request_var('graph_template_id', $template_parts[0]);
			$output_type_id = $template_parts[1];
		}else{
			cacti_log('ERROR: Unable to parse graph_template_id with value ' . get_nfilter_request_var($variable), false, 'WEBUI');
			exit;
		}
	}else{
		get_filter_request_var($variable);
	}

	return $output_type_id;
}

function form_save() {
	/* ================= input validation ================= */
	get_filter_request_var('local_graph_id');
	get_filter_request_var('host_id');
	get_filter_request_var('graph_template_graph_id');
	get_filter_request_var('local_graph_template_graph_id');
	/* ==================================================== */

	$gt_id_unparsed      = get_nfilter_request_var('graph_template_id');
	$gt_id_prev_unparsed = get_nfilter_request_var('graph_template_id_prev');
	parse_validate_graph_template_id('graph_template_id');

	if ((isset_request_var('save_component_graph_new')) && (!isempty_request_var('graph_template_id'))) {
		$save['id'] = get_nfilter_request_var('local_graph_id');
		$save['graph_template_id'] = get_nfilter_request_var('graph_template_id');
		$save['host_id'] = get_nfilter_request_var('host_id');

		$local_graph_id = sql_save($save, 'graph_local');

		change_graph_template($local_graph_id, $gt_id_unparsed, true);

		/* update the title cache */
		update_graph_title_cache($local_graph_id);
	}

	if (isset_request_var('save_component_graph')) {
		if (get_filter_request_var('host_id') == '-1') {
			set_request_var('host_id', '0');
		}

		$save1['id']                     = get_nfilter_request_var('local_graph_id');
		$save1['host_id']                = get_request_var('host_id');
		$save1['graph_template_id']      = get_nfilter_request_var('graph_template_id');

		$save2['id']                     = get_nfilter_request_var('graph_template_graph_id');
		$save2['local_graph_template_graph_id'] = get_nfilter_request_var('local_graph_template_graph_id');
		$save2['graph_template_id']      = get_nfilter_request_var('graph_template_id');
		$save2['image_format_id']        = form_input_validate(get_nfilter_request_var('image_format_id'), 'image_format_id', '^[0-9]+$', true, 3);
		$save2['title']                  = form_input_validate(get_nfilter_request_var('title'), 'title', '', false, 3);
		$save2['height']                 = form_input_validate(get_nfilter_request_var('height'), 'height', '^[0-9]+$', false, 3);
		$save2['width']                  = form_input_validate(get_nfilter_request_var('width'), 'width', '^[0-9]+$', false, 3);
		$save2['upper_limit']            = form_input_validate(get_nfilter_request_var('upper_limit'), 'upper_limit', "^(-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U$", ((strlen(get_nfilter_request_var('upper_limit')) === 0) ? true : false), 3);
		$save2['lower_limit']            = form_input_validate(get_nfilter_request_var('lower_limit'), 'lower_limit', "^(-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U$", ((strlen(get_nfilter_request_var('lower_limit')) === 0) ? true : false), 3);
		$save2['vertical_label']         = form_input_validate(get_nfilter_request_var('vertical_label'), 'vertical_label', '', true, 3);
		$save2['slope_mode']             = form_input_validate((isset_request_var('slope_mode') ? get_nfilter_request_var('slope_mode') : ''), 'slope_mode', '', true, 3);
		$save2['auto_scale']             = form_input_validate((isset_request_var('auto_scale') ? get_nfilter_request_var('auto_scale') : ''), 'auto_scale', '', true, 3);
		$save2['auto_scale_opts']        = form_input_validate(get_nfilter_request_var('auto_scale_opts'), 'auto_scale_opts', '', true, 3);
		$save2['auto_scale_log']         = form_input_validate((isset_request_var('auto_scale_log') ? get_nfilter_request_var('auto_scale_log') : ''), 'auto_scale_log', '', true, 3);
		$save2['scale_log_units']        = form_input_validate((isset_request_var('scale_log_units') ? get_nfilter_request_var('scale_log_units') : ''), 'scale_log_units', '', true, 3);
		$save2['auto_scale_rigid']       = form_input_validate((isset_request_var('auto_scale_rigid') ? get_nfilter_request_var('auto_scale_rigid') : ''), 'auto_scale_rigid', '', true, 3);
		$save2['auto_padding']           = form_input_validate((isset_request_var('auto_padding') ? get_nfilter_request_var('auto_padding') : ''), 'auto_padding', '', true, 3);
		$save2['base_value']             = form_input_validate(get_nfilter_request_var('base_value'), 'base_value', '^[0-9]+$', false, 3);
		$save2['export']                 = form_input_validate((isset_request_var('export') ? get_nfilter_request_var('export') : ''), 'export', '', true, 3);
		$save2['unit_value']             = form_input_validate(get_nfilter_request_var('unit_value'), 'unit_value', '', true, 3);
		$save2['unit_exponent_value']    = form_input_validate(get_nfilter_request_var('unit_exponent_value'), 'unit_exponent_value', '^-?[0-9]+$', true, 3);
		$save2['t_alt_y_grid']           = form_input_validate((isset_request_var('t_alt_y_grid') ? get_nfilter_request_var('t_alt_y_grid') : ''), 't_alt_y_grid', '', true, 3);
		$save2['alt_y_grid']             = form_input_validate((isset_request_var('alt_y_grid') ? get_nfilter_request_var('alt_y_grid') : ''), 'alt_y_grid', '', true, 3);
		$save2['t_right_axis']           = form_input_validate((isset_request_var('t_right_axis') ? get_nfilter_request_var('t_right_axis') : ''), 't_right_axis', '', true, 3);
		$save2['right_axis']             = form_input_validate((isset_request_var('right_axis') ? get_nfilter_request_var('right_axis') : ''), 'right_axis', '^[.0-9]+:-?[.0-9]+$', true, 3);
		$save2['t_right_axis_label']     = form_input_validate((isset_request_var('t_right_axis_label') ? get_nfilter_request_var('t_right_axis_label') : ''), 't_right_axis_label', '', true, 3);
		$save2['right_axis_label']       = form_input_validate((isset_request_var('right_axis_label') ? get_nfilter_request_var('right_axis_label') : ''), 'right_axis_label', '', true, 3);
		$save2['t_right_axis_format']    = form_input_validate((isset_request_var('t_right_axis_format') ? get_nfilter_request_var('t_right_axis_format') : ''), 't_right_axis_format', '', true, 3);
		$save2['right_axis_format']      = form_input_validate((isset_request_var('right_axis_format') ? get_nfilter_request_var('right_axis_format') : ''), 'right_axis_format', '^[0-9]+$', true, 3);
		$save2['t_no_gridfit']           = form_input_validate((isset_request_var('t_no_gridfit') ? get_nfilter_request_var('t_no_gridfit') : ''), 't_no_gridfit', '', true, 3);
		$save2['no_gridfit']             = form_input_validate((isset_request_var('no_gridfit') ? get_nfilter_request_var('no_gridfit') : ''), 'no_gridfit', '', true, 3);
		$save2['t_unit_length']          = form_input_validate((isset_request_var('t_unit_length') ? get_nfilter_request_var('t_unit_length') : ''), 't_unit_length', '', true, 3);
		$save2['unit_length']            = form_input_validate((isset_request_var('unit_length') ? get_nfilter_request_var('unit_length') : ''), 'unit_length', '^[0-9]+$', true, 3);
		$save2['t_tab_width']            = form_input_validate((isset_request_var('t_tab_width') ? get_nfilter_request_var('t_tab_width') : ''), 't_tab_width', '', true, 3);
		$save2['tab_width']              = form_input_validate((isset_request_var('tab_width') ? get_nfilter_request_var('tab_width') : ''), 'tab_width', '^[0-9]*$', true, 3);
		$save2['t_dynamic_labels']       = form_input_validate((isset_request_var('t_dynamic_labels') ? get_nfilter_request_var('t_dynamic_labels') : ''), 't_dynamic_labels', '', true, 3);
		$save2['dynamic_labels']         = form_input_validate((isset_request_var('dynamic_labels') ? get_nfilter_request_var('dynamic_labels') : ''), 'dynamic_labels', '', true, 3);
		$save2['t_force_rules_legend']   = form_input_validate((isset_request_var('t_force_rules_legend') ? get_nfilter_request_var('t_force_rules_legend') : ''), 't_force_rules_legend', '', true, 3);
		$save2['force_rules_legend']     = form_input_validate((isset_request_var('force_rules_legend') ? get_nfilter_request_var('force_rules_legend') : ''), 'force_rules_legend', '', true, 3);
		$save2['t_legend_position']      = form_input_validate((isset_request_var('t_legend_position') ? get_nfilter_request_var('t_legend_position') : ''), 't_legend_position', '', true, 3);
		$save2['legend_position']        = form_input_validate((isset_request_var('legend_position') ? get_nfilter_request_var('legend_position') : ''), 'legend_position', '', true, 3);
		$save2['t_legend_direction']     = form_input_validate((isset_request_var('t_legend_direction') ? get_nfilter_request_var('t_legend_direction') : ''), 't_legend_direction', '', true, 3);
		$save2['legend_direction']       = form_input_validate((isset_request_var('legend_direction') ? get_nfilter_request_var('legend_direction') : ''), 'legend_direction', '', true, 3);
		$save2['t_right_axis_formatter'] = form_input_validate((isset_request_var('t_right_axis_formatter') ? get_nfilter_request_var('t_right_axis_formatter') : ''), 't_right_axis_formatter', '', true, 3);
		$save2['right_axis_formatter']   = form_input_validate((isset_request_var('right_axis_formatter') ? get_nfilter_request_var('right_axis_formatter') : ''), 'right_axis_formatter', '', true, 3);
		$save2['t_left_axis_formatter']  = form_input_validate((isset_request_var('t_left_axis_formatter') ? get_nfilter_request_var('t_left_axis_formatter') : ''), 't_left_axis_formatter', '', true, 3);
		$save2['left_axis_formatter']    = form_input_validate((isset_request_var('left_axis_formatter') ? get_nfilter_request_var('left_axis_formatter') : ''), 'left_axis_formatter', '', true, 3);

		if (!is_error_message()) {
			$local_graph_id = sql_save($save1, 'graph_local');
		}

		if (!is_error_message()) {
			$save2['local_graph_id'] = $local_graph_id;
			$graph_templates_graph_id = sql_save($save2, 'graph_templates_graph');

			if ($graph_templates_graph_id) {
				raise_message(1);
			}else{
				raise_message(2);
			}

			/* update the title cache */
			update_graph_title_cache($local_graph_id);
		}

		if ((!is_error_message()) && ($gt_id_unparsed != $gt_id_prev_unparsed)) {
			change_graph_template($local_graph_id, $gt_id_unparsed);
		}elseif (!isempty_request_var('graph_template_id')) {
			update_graph_data_query_cache($local_graph_id);
		}
	}

	if (isset_request_var('save_component_input')) {
		/* ================= input validation ================= */
		get_filter_request_var('local_graph_id');
		/* ==================================================== */

		/* first; get the current graph template id */
		$graph_template_id = db_fetch_cell_prepared('SELECT graph_template_id 
			FROM graph_local 
			WHERE id = ?', 
			array(get_nfilter_request_var('local_graph_id')));

		/* get all inputs that go along with this graph template, if templated */
		if ($graph_template_id > 0) {
			$input_list = db_fetch_assoc_prepared('SELECT id, column_name 
				FROM graph_template_input 
				WHERE graph_template_id = ?', 
				array($graph_template_id));
			
			if (sizeof($input_list)) {
				foreach ($input_list as $input) {
					/* we need to find out which graph items will be affected by saving this particular item */
					$item_list = db_fetch_assoc_prepared('SELECT gti.id
						FROM graph_template_input_defs AS gtid
						INNER JOIN graph_templates_item AS gti
						ON gtid.graph_template_item_id=gti.local_graph_template_item_id
						WHERE gti.local_graph_id = ?
						AND gtid.graph_template_input_id = ?', 
						array(get_nfilter_request_var('local_graph_id'), $input['id']));
					
					/* loop through each item affected and update column data */
					if (sizeof($item_list)) {
						foreach ($item_list as $item) {
							/* if we are changing templates, the POST vars we are searching for here will not exist.
							 this is because the db and form are out of sync here, but it is ok to just skip over saving
							 the inputs in this case. */
							if (isset_request_var($input['column_name'] . '_' . $input['id'])) {
								db_execute_prepared('UPDATE graph_templates_item 
									SET ' . $input['column_name'] . ' = ? 
									WHERE id = ?', 
									array(get_nfilter_request_var($input['column_name'] . '_' . $input['id']), $item['id']));
							}
						}
					}
				}
			}
		}
	}

	if ((isset_request_var('save_component_graph_new')) && (isempty_request_var('graph_template_id'))) {
		header('Location: graphs.php?action=graph_edit&header=false&host_id=' . get_nfilter_request_var('host_id') . '&new=1');
	}elseif ((is_error_message()) || (isempty_request_var('local_graph_id')) || (get_nfilter_request_var('graph_template_id') != get_nfilter_request_var('graph_template_id_prev')) || (get_nfilter_request_var('host_id') != get_nfilter_request_var('host_id_prev'))) {
		header('Location: graphs.php?action=graph_edit&header=false&id=' . (empty($local_graph_id) ? get_nfilter_request_var('local_graph_id') : $local_graph_id) . (isset_request_var('host_id') ? '&host_id=' . get_nfilter_request_var('host_id') : ''));
	}else{
		header('Location: graphs.php?header=false');
	}
}

/* ------------------------
    The "actions" function
   ------------------------ */

function get_current_graph_template_name($local_graph_id) {
	$graph_local = db_fetch_row_prepared('SELECT * 
		FROM graph_local 
		WHERE id = ?', 
		array($local_graph_id));

	$task_items = db_fetch_cell_prepared('SELECT GROUP_CONCAT(DISTINCT task_item_id) AS items 
		FROM graph_templates_item 
		WHERE local_graph_id = ?', 
		array($local_graph_id));

	if ($task_items != '') {
		$local_data_id = db_fetch_cell("SELECT DISTINCT local_data_id 
			FROM data_template_rrd 
			WHERE id IN($task_items)");
	}else{
		$local_data_id = 0;
	}

	if ($local_data_id > 0) {
		$data = db_fetch_row_prepared('SELECT id, data_input_id, data_template_id, name, local_data_id
			FROM data_template_data
			WHERE local_data_id = ?', 
			array($local_data_id));

		if (sizeof($data)) {
			/* get each INPUT field for this data input source */
			$output_type_field_id = db_fetch_cell_prepared('SELECT id
				FROM data_input_fields
				WHERE data_input_id = ?
				AND input_output="in"
				AND type_code="output_type"
				ORDER BY sequence',
				array($data['data_input_id']));

			$snmp_query_graph_id = db_fetch_cell_prepared('SELECT value
				FROM data_input_data
				WHERE data_template_data_id = ?
				AND data_input_field_id = ?',
				array($data['id'], $output_type_field_id));
		}else{
			$snmp_query_graph_id = 0;
		}

		if (!empty($snmp_query_graph_id)) {
			return db_fetch_cell_prepared('SELECT name FROM snmp_query_graph WHERE id = ?', array($snmp_query_graph_id));
		}else{
			return db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE id = ?' , array($graph_local['graph_template_id']));
		}
	}else{
		return db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE id = ?' , array($graph_local['graph_template_id']));
	}
}

function get_current_graph_template($local_graph_id) {
	$graph_local = db_fetch_row_prepared('SELECT * 
		FROM graph_local 
		WHERE id = ?', 
		array($local_graph_id));

	$task_items = db_fetch_cell_prepared('SELECT GROUP_CONCAT(DISTINCT task_item_id) AS items 
		FROM graph_templates_item 
		WHERE local_graph_id = ?', 
		array($local_graph_id));

	if ($task_items != '') {
		$local_data_id = db_fetch_cell("SELECT DISTINCT local_data_id 
			FROM data_template_rrd 
			WHERE id IN($task_items)");
	}else{
		$local_data_id = 0;
	}

	if ($local_data_id > 0) {
		$data = db_fetch_row_prepared('SELECT id, data_input_id, data_template_id, name, local_data_id
			FROM data_template_data
			WHERE local_data_id = ?', 
			array($local_data_id));

		/* get each INPUT field for this data input source */
		$output_type_field_id = db_fetch_cell_prepared('SELECT id
			FROM data_input_fields
			WHERE data_input_id = ?
			AND input_output="in"
			AND type_code="output_type"
			ORDER BY sequence',
			array($data['data_input_id']));

		$snmp_query_graph_id = db_fetch_cell_prepared('SELECT value
			FROM data_input_data
			WHERE data_template_data_id = ?
			AND data_input_field_id = ?',
			array($data['id'], $output_type_field_id));

		if (!empty($snmp_query_graph_id)) {
			return $graph_local['graph_template_id'] . '_' . $snmp_query_graph_id;
		}else{
			return $graph_local['graph_template_id'];
		}
	}else{
		return $graph_local['graph_template_id'];
	}
}

function get_common_graph_templates(&$graph) {
	if (sizeof($graph)) {
		$dqid = db_fetch_cell_prepared('SELECT snmp_query_id FROM graph_local WHERE id = ?', array($graph['local_graph_id']));
	}else{
		$dqid = '';
	}

	if (!empty($dqid)) {
		$sqgi = db_fetch_cell_prepared('SELECT id 
			FROM snmp_query_graph 
			WHERE snmp_query_id = ? 
			AND graph_template_id = ?',
			array($dqid, $graph['graph_template_id']));

		$query_fields = db_fetch_cell_prepared('SELECT GROUP_CONCAT(snmp_field_name ORDER BY snmp_field_name) AS columns 
			FROM snmp_query_graph_rrd 
			WHERE snmp_query_graph_id = ?
			GROUP BY snmp_query_graph_id', array($sqgi));

		$common_graph_ids = array_rekey(db_fetch_assoc_prepared('SELECT 
			snmp_query_graph_id, GROUP_CONCAT(snmp_field_name ORDER BY snmp_field_name) AS columns
			FROM snmp_query_graph_rrd
			GROUP BY snmp_query_graph_id
			HAVING columns = ?', array($query_fields)), 'snmp_query_graph_id', 'columns');

		$ids = implode(',', array_keys($common_graph_ids));

		$gtids = db_fetch_cell_prepared('SELECT GROUP_CONCAT(DISTINCT graph_template_id) AS gtids 
			FROM snmp_query_graph 
			WHERE snmp_query_id = ? AND id IN (' . $ids . ')', array($dqid));

		$gtsql = "SELECT CONCAT_WS('', graph_template_id, '_', id, '') AS id, name 
			FROM snmp_query_graph 
			WHERE (snmp_query_id = " . $dqid . "
			AND id IN (" . $ids . ")) OR graph_template_id IN (" . $gtids . ") ORDER BY name";
	}elseif (sizeof($graph)) {
		$gtsql = 'SELECT gt.id, gt.name FROM graph_templates AS gt WHERE gt.id=' . $graph['graph_template_id'] . ' ORDER BY name';
	}else{
		$gtsql = 'SELECT gt.id, gt.name FROM graph_templates AS gt ORDER BY name';
	}

	return $gtsql;
}

function form_actions() {
	global $graph_actions, $struct_aggregate;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') { /* delete */
				if (!isset_request_var('delete_type')) {
					set_request_var('delete_type', 1);
				}

				switch (get_nfilter_request_var('delete_type')) {
					case '2': /* delete all data sources referenced by this graph */
						$all_data_sources = array_rekey(db_fetch_assoc('SELECT DISTINCT dtd.local_data_id
							FROM data_template_data AS dtd
							INNER JOIN data_template_rrd AS dtr
							ON dtd.local_data_id=dtr.local_data_id
							INNER JOIN graph_templates_item AS gti
							ON dtr.id=gti.task_item_id
							WHERE ' . array_to_sql_or($selected_items, 'gti.local_graph_id') . '
							AND dtd.local_data_id > 0'), 'local_data_id', 'local_data_id');

						$data_sources = array_rekey(db_fetch_assoc('SELECT dtd.local_data_id,
							COUNT(DISTINCT gti.local_graph_id) AS graphs
							FROM data_template_data AS dtd
							INNER JOIN data_template_rrd AS dtr
							ON dtd.local_data_id=dtr.local_data_id
							INNER JOIN graph_templates_item AS gti
							ON dtr.id=gti.task_item_id
							WHERE dtd.local_data_id > 0
							GROUP BY dtd.local_data_id
							HAVING graphs = 1 
							AND ' . array_to_sql_or($all_data_sources, 'local_data_id')), 'local_data_id', 'local_data_id');

						if (sizeof($data_sources)) {
							api_data_source_remove_multi($data_sources);
							api_plugin_hook_function('data_source_remove', $data_sources);
						}

						api_graph_remove_multi($selected_items);
						api_plugin_hook_function('graphs_remove', $selected_items);

						/* Remove orphaned data sources */
						$data_sources = array_rekey(db_fetch_assoc('SELECT DISTINCT dtd.local_data_id
							FROM data_template_data AS dtd
							INNER JOIN data_template_rrd AS dtr
							ON dtd.local_data_id=dtr.local_data_id
							LEFT JOIN graph_templates_item AS gti
							ON dtr.id=gti.task_item_id
							WHERE ' . array_to_sql_or($all_data_sources, 'dtd.local_data_id') . '
							AND gti.local_graph_id IS NULL
							AND dtd.local_data_id > 0'), 'local_data_id', 'local_data_id');

						if (sizeof($data_sources)) {
							api_data_source_remove_multi($data_sources);
							api_plugin_hook_function('data_source_remove', $data_sources);
						}

						break;
					case '1':
						api_graph_remove_multi($selected_items);
						api_plugin_hook_function('graphs_remove', $selected_items);

						break;
				}
			}elseif (get_request_var('drp_action') == '2') { /* change graph template */
				$gt_id_unparsed      = get_nfilter_request_var('graph_template_id');
				$gt_id_prev_unparsed = get_nfilter_request_var('graph_template_id_prev');
				parse_validate_graph_template_id('graph_template_id');

				for ($i=0;($i<count($selected_items));$i++) {
					change_graph_template($selected_items[$i], $gt_id_unparsed, true);
				}
			}elseif (preg_match('/^tr_([0-9]+)$/', get_request_var('drp_action'), $matches)) { /* place on tree */
				get_filter_request_var('tree_id');
				get_filter_request_var('tree_item_id');
				for ($i=0;($i<count($selected_items));$i++) {
					api_tree_item_save(0, get_nfilter_request_var('tree_id'), TREE_ITEM_TYPE_GRAPH, get_nfilter_request_var('tree_item_id'), '', $selected_items[$i], 0, 0, 0, false);
				}
			}elseif (get_request_var('drp_action') == '5') { /* change host */
				get_filter_request_var('host_id');
				for ($i=0;($i<count($selected_items));$i++) {
					db_execute_prepared('UPDATE graph_local 
						SET host_id = ? WHERE id = ?', 
						array(get_nfilter_request_var('host_id'), $selected_items[$i]));
					update_graph_title_cache($selected_items[$i]);
				}
			}elseif (get_request_var('drp_action') == '6') { /* reapply suggested naming */
				for ($i=0;($i<count($selected_items));$i++) {
					api_reapply_suggested_graph_title($selected_items[$i]);
					update_graph_title_cache($selected_items[$i]);
				}
			}elseif (get_request_var('drp_action') == '9' || get_request_var('drp_action') == '10') {
				/* get common info - not dependant on template/no template*/
				$local_graph_id = 0; // this will be a new graph
				$member_graphs  = $selected_items;
				$graph_title    = form_input_validate(get_nfilter_request_var('title_format'), 'title_format', '', true, 3);

				/* future aggregate_graphs entry */
				$ag_data = array();
				$ag_data['id'] = 0;
				$ag_data['title_format'] = $graph_title;
				$ag_data['user_id']      = $_SESSION['sess_user_id'];

				if (get_request_var('drp_action') == '9') {
					if (!isset_request_var('aggregate_total_type'))   set_request_var('aggregate_total_type', 0);
					if (!isset_request_var('aggregate_total'))        set_request_var('aggregate_total', 0);
					if (!isset_request_var('aggregate_total_prefix')) set_request_var('aggregate_total_prefix', '');
					if (!isset_request_var('aggregate_order_type'))   set_request_var('aggregate_order_type', 0);
	
					$item_no = form_input_validate(get_nfilter_request_var('item_no'), 'item_no', '^[0-9]+$', true, 3);

					$ag_data['aggregate_template_id'] = 0;
					$ag_data['template_propogation']  = '';
					$ag_data['graph_template_id']     = form_input_validate(get_nfilter_request_var('graph_template_id'), 'graph_template_id', '^[0-9]+$', true, 3);
					$ag_data['gprint_prefix']         = form_input_validate(get_nfilter_request_var('gprint_prefix'), 'gprint_prefix', '', true, 3);
					$ag_data['graph_type']            = form_input_validate(get_nfilter_request_var('aggregate_graph_type'), 'aggregate_graph_type', '^[0-9]+$', true, 3);
					$ag_data['total']                 = form_input_validate(get_nfilter_request_var('aggregate_total'), 'aggregate_total', '^[0-9]+$', true, 3);
					$ag_data['total_type']            = form_input_validate(get_nfilter_request_var('aggregate_total_type'), 'aggregate_total_type', '^[0-9]+$', true, 3);
					$ag_data['total_prefix']          = form_input_validate(get_nfilter_request_var('aggregate_total_prefix'), 'aggregate_total_prefix', '', true, 3);
					$ag_data['order_type']            = form_input_validate(get_nfilter_request_var('aggregate_order_type'), 'aggregate_order_type', '^[0-9]+$', true, 3);
				} else {
					$template_data = db_fetch_row_prepared('SELECT * 
						FROM aggregate_graph_templates 
						WHERE id = ?', 
						array(get_nfilter_request_var('aggregate_template_id')));

					$item_no = db_fetch_cell_prepared('SELECT COUNT(*) 
						FROM aggregate_graph_templates_item 
						WHERE aggregate_template_id = ?', 
						array(get_nfilter_request_var('aggregate_template_id')));

					$ag_data['aggregate_template_id'] = get_nfilter_request_var('aggregate_template_id');
					$ag_data['template_propogation']  = 'on';
					$ag_data['graph_template_id']     = $template_data['graph_template_id'];
					$ag_data['gprint_prefix']         = $template_data['gprint_prefix'];
					$ag_data['graph_type']            = $template_data['graph_type'];
					$ag_data['total']                 = $template_data['total'];
					$ag_data['total_type']            = $template_data['total_type'];
					$ag_data['total_prefix']          = $template_data['total_prefix'];
					$ag_data['order_type']            = $template_data['order_type'];
				}

				/* create graph in cacti tables */
				$local_graph_id = aggregate_graph_save(
					$local_graph_id,
					$ag_data['graph_template_id'],
					$graph_title,
					$ag_data['aggregate_template_id']
				);

				$ag_data['local_graph_id'] = $local_graph_id;
				$aggregate_graph_id = sql_save($ag_data, 'aggregate_graphs');
				$ag_data['aggregate_graph_id'] = $aggregate_graph_id;

				// 	/* save member graph info */
				// 	$i = 1;
				// 	foreach($member_graphs as $graph_id) {
				// 		db_execute("INSERT INTO aggregate_graphs_items 
				// 			(aggregate_graph_id, local_graph_id, sequence) 
				// 			VALUES
				// 			($aggregate_graph_id, $graph_id, $i)"
				// 		);
				// 		$i++;
				// 	}

				/* save aggregate graph graph items */
				if (get_request_var('drp_action') == '9') {
					/* get existing item ids and sequences from graph template */
					$graph_templates_items = array_rekey(
						db_fetch_assoc_prepared('SELECT id, sequence 
							FROM graph_templates_item 
							WHERE local_graph_id=0 
							AND graph_template_id = ?', 
							array($ag_data['graph_template_id'])),
						'id', array('sequence')
					);

					/* update graph template item values with posted values */
					aggregate_validate_graph_items($_POST, $graph_templates_items);

					$aggregate_graph_items = array();
					foreach ($graph_templates_items as $item_id => $data) {
						$item_new                            = array();
						$item_new['aggregate_graph_id']      = $aggregate_graph_id;
						$item_new['graph_templates_item_id'] = $item_id;

						$item_new['color_template']          = isset($data['color_template']) ? $data['color_template'] : 0;
						$item_new['item_skip']               = isset($data['item_skip']) ? 'on' : '';
						$item_new['item_total']              = isset($data['item_total']) ? 'on' : '';
						$item_new['sequence']                = isset($data['sequence']) ? $data['sequence'] : 0;

						$aggregate_graph_items[]             = $item_new;
					}

					aggregate_graph_items_save($aggregate_graph_items, 'aggregate_graphs_graph_item');
				} else {
					$aggregate_graph_items = db_fetch_assoc_prepared('SELECT * 
						FROM aggregate_graph_templates_item 
						WHERE aggregate_template_id = ?', 
						array($ag_data['aggregate_template_id']));
				}

				$attribs = $ag_data;
				$attribs['graph_title'] = $ag_data['title_format'];
				$attribs['reorder'] = $ag_data['order_type'];
				$attribs['item_no'] = $item_no;
				$attribs['color_templates'] = array();
				$attribs['skipped_items']   = array();
				$attribs['total_items']     = array();
				$attribs['graph_item_types']= array();
				$attribs['cdefs']           = array();
				foreach ($aggregate_graph_items as $item) {
					if (isset($item['color_template']) && $item['color_template'] > 0)
						$attribs['color_templates'][ $item['sequence'] ] = $item['color_template'];

					if (isset($item['item_skip']) && $item['item_skip'] == 'on')
						$attribs['skipped_items'][ $item['sequence'] ] = $item['sequence'];

					if (isset($item['item_total']) && $item['item_total'] == 'on')
						$attribs['total_items'][ $item['sequence'] ] = $item['sequence'];

					if (isset($item['cdef_id']) && isset($item['t_cdef_id']) && $item['t_cdef_id'] == 'on')
						$attribs['cdefs'][ $item['sequence'] ] = $item['cdef_id'];

					if (isset($item['graph_type_id']) && isset($item['t_graph_type_id']) && $item['t_graph_type_id'] == 'on')
						$attribs['graph_item_types'][ $item['sequence'] ] = $item['graph_type_id'];
				}

				/* create actual graph items */
				aggregate_create_update($local_graph_id, $member_graphs, $attribs);

				header("Location: aggregate_graphs.php?header=false&action=edit&tab=details&id=$local_graph_id");
				exit;
			}elseif (get_request_var('drp_action') == '8') { /* automation */
				cacti_log('automation_graph_action_execute called: ' . $action, true, 'AUTM8 TRACE', POLLER_VERBOSITY_MEDIUM);

				/* work on all selected graphs */
				for ($i=0;($i<count($selected_items));$i++) {
					execute_graph_create_tree($selected_items[$i]);
				}
			} else {
				api_plugin_hook_function('graphs_action_execute', get_request_var('drp_action'));
			}

			/* update snmpcache */
			snmpagent_graphs_action_bottom(array(get_request_var('drp_action'), $selected_items));
			api_plugin_hook_function('graphs_action_bottom', array(get_request_var('drp_action'), $selected_items));
		}

		header('Location: graphs.php?header=false');
		exit;
	}

	/* setup some variables */
	$graph_list = ''; $i = 0;

	/* loop through each of the graphs selected on the previous page and get more info about them */
	while (list($var,$val) = each($_POST)) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$graph_list .= '<li>' . htmlspecialchars(get_graph_title($matches[1])) . '</li>';
			$graph_array[$i] = $matches[1];

			$i++;
		}
	}

	top_header();

	/* add a list of tree names to the actions dropdown */
	add_tree_names_to_actions_array();

	form_start('graphs.php');

	html_start_box($graph_actions{get_request_var('drp_action')}, '60%', '', '3', 'center', '');

	if (isset($graph_array) && sizeof($graph_array)) {
		if (get_request_var('drp_action') == '1') { /* delete */
			/* find out which (if any) data sources are being used by this graph, so we can tell the user */
			if (isset($graph_array) && sizeof($graph_array)) {
				$data_sources = db_fetch_assoc('SELECT DISTINCT dtd.local_data_id, dtd.name_cache
					FROM data_template_data AS dtd
					INNER JOIN data_template_rrd AS dtr
					ON dtr.local_data_id=dtd.local_data_id
					INNER JOIN graph_templates_item AS gti
					ON dtr.id=gti.task_item_id
					WHERE ' . array_to_sql_or($graph_array, 'gti.local_graph_id') . '
					AND dtd.local_data_id > 0');
			}

			print "	<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to delete the following Graph(s).  Note that if you choose to Delete Data Sources, only those Data Sources not in use elsewhere will also be Deleted.') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>";

			if (isset($data_sources) && sizeof($data_sources)) {
				print "<tr><td class='textArea'><p>" . __('The following Data Source(s) are in use by these Graph(s).') . "</p>\n";

				print '<div class="itemlist"><ul>';
				foreach ($data_sources as $data_source) {
					print '<li>' . htmlspecialchars($data_source['name_cache']) . "</li>\n";
				}
				print '</ul></div><br>';

				print '<span class="nowrap">';
				form_radio_button('delete_type', '2', '2', __('Delete all Data Source(s) referenced by these Graph(s) that are not in use elsewhere.'), '2'); 
				print '<br>';
				form_radio_button('delete_type', '1', '2', __('Leave the Data Source(s) untouched.'), '2'); 
				print '<br>';
				print '</span>';
				print '</td></tr>';
			}

			print "</td></tr>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') ."' title='" . __('Delete Graph(s)') . "'>";
		} elseif (get_request_var('drp_action') == '2') { /* change graph template */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Choose a Graph Template and click \'Continue\' to change the Graph Template for the following Graph(s). Please note, that only compatible Graph Templates will be displayed.  Compatible is identified by those having identical Data Sources.') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>
					<p>" . __('New Graph Template') . "<br>"; 

					$graph = array();
					$graph['local_graph_id'] = db_fetch_cell_prepared('SELECT id 
						FROM graph_local 
						WHERE graph_template_id = ?
						LIMIT 1', 
						array(get_request_var('template_id')));
					$graph['graph_template_id'] = get_filter_request_var('template_id');

					$gtsql = get_common_graph_templates($graph);

					form_dropdown('graph_template_id', db_fetch_assoc($gtsql), 'name','id','','','0'); 

					print "</p>
				</td>
			</tr>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Change Graph Template') . "'>";
		} elseif (preg_match('/^tr_([0-9]+)$/', get_request_var('drp_action'), $matches)) { /* place on tree */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to place the following Graph(s) under the Tree Branch selected below.') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>
					<p>" . __('Destination Branch') . "<br>"; 

			grow_dropdown_tree($matches[1], '0', 'tree_item_id', '0'); 

			print "</p>
				</td>
				</tr>
				<input type='hidden' name='tree_id' value='" . htmlspecialchars($matches[1]) . "'>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Place Graph(s) on Tree') . "'>";
		} elseif (get_request_var('drp_action') == '5') { /* change host */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Choose a new Device for these Graph(s) and click \'Continue\'.') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>
					<p>" . __('New Device'). "<br>"; 

			form_dropdown('host_id',db_fetch_assoc("SELECT id,CONCAT_WS('',description,' (',hostname,')') as name FROM host ORDER BY description,hostname"),'name','id','','','0'); 

			print "</p>
				</td>
			</tr>\n";

			$save_html = "<input type='button' value='Cancel' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='Continue' title='Change Graph(s) Associated Device'>";
		} elseif (get_request_var('drp_action') == '6') { /* reapply suggested naming to host */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to re-apply suggested naming to the following Graph(s).') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Reapply Suggested Naming to Graph(s)') . "'>";
		} elseif (get_request_var('drp_action') == '9') {
			include_once('./lib/api_aggregate.php');

			/* initialize return code and graphs array */
			$return_code    = false;
			$data_sources   = array();
			$graph_template = '';

			if (aggregate_get_data_sources($graph_array, $data_sources, $graph_template)) {
				# provide a new prefix for GPRINT lines
				$gprint_prefix = '|host_hostname|';

				/* list affected graphs */
				print '<tr>';
				print "<td class='textArea'>
					<p>" . __('Click \'Continue\' to create an Aggregate Graph from the selected Graph(s).'). "</p>
					<div class='itemlist'><ul>" . get_nfilter_request_var('graph_list') . "</ul></div>
				</td>\n";

				/* list affected data sources */
				if (sizeof($data_sources) > 0) {
					print "<td class='textArea'>" .
					'<p>' . __('The following data sources are in use by these graphs:') . '</p>
					<div class="itemlist"><ul>';
					foreach ($data_sources as $data_source) {
						print '<li>' . htmlspecialchars($data_source['name_cache']) . "</li>\n";
					}
					print "</ul></div></td>\n";
				}
				print "</tr>\n";

				$ttitle = $graph_array[0];

				/* aggregate form */
				$_aggregate_defaults = array(
					'title_format' 	=> auto_title($ttitle),
					'graph_template_id' => $graph_template, 
					'gprint_prefix'	=> $gprint_prefix
				);

				draw_edit_form(
					array(
						'config' => array('no_form_tag' => true),
						'fields' => inject_form_variables($struct_aggregate, $_aggregate_defaults)
					)
				);

				# draw all graph items of first graph, including a html_start_box
				draw_aggregate_graph_items_list(0, $graph_template);

				# again, a new html_start_box. Using the one from above would yield ugly formatted NO and YES buttons
				html_start_box(__('Please confirm'), '100%', '', '3', 'center', '');

				?>
				<script type='text/javascript'>
				function changeTotals() {
					switch ($('#aggregate_total').val()) {
						case '<?php print AGGREGATE_TOTAL_NONE;?>':
							$('#aggregate_total_type').prop('disabled', true);
							$('#aggregate_total_prefix').prop('disabled', true);
							$('#aggregate_order_type').prop('disabled', false);
							break;
						case '<?php print AGGREGATE_TOTAL_ALL;?>':
							$('#aggregate_total_type').prop('disabled', false);
							$('#aggregate_total_prefix').prop('disabled', false);
							$('#aggregate_order_type').prop('disabled', false);
							changeTotalsType();
							break;
						case '<?php print AGGREGATE_TOTAL_ONLY;?>':
							$('#aggregate_total_type').prop('disabled', false);
							$('#aggregate_total_prefix').prop('disabled', false);
							$('#aggregate_order_type').prop('disabled', true);
							changeTotalsType();
							break;
					}
				}

				function changeTotalsType() {
					if (($('#aggregate_total_type').val() == <?php print AGGREGATE_TOTAL_TYPE_SIMILAR;?>)) {
						$('#aggregate_total_prefix').attr('value', 'Total');
					} else if (($('#aggregate_total_type').val() == <?php print AGGREGATE_TOTAL_TYPE_ALL;?>)) {
						$('#aggregate_total_prefix').attr('value', 'All Items');
					}
				}

				$().ready(function() {
					$('#aggregate_total').change(function() {
						changeTotals();
					});

					$('#aggregate_total_type').change(function() {
						changeTotalsType();
					});

					changeTotals();
				});
				</script>
				<?php

				$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Resize Selected Graph(s)') . "'>";
			}else{
				$save_html = "<input type='button' value='" . __('Return') . "' onClick='cactiReturnTo()'>";
			}
		}elseif (get_request_var('drp_action') == '10') { /* aggregate template */
			include_once('./lib/api_aggregate.php');

			/* initialize return code and graphs array */
			$data_sources   = array();
			$graph_template = '';

			/* find out which (if any) data sources are being used by this graph, so we can tell the user */
			if (aggregate_get_data_sources($graph_array, $data_sources, $graph_template)) {
				$aggregate_templates = db_fetch_assoc_prepared('SELECT id, name 
					FROM aggregate_graph_templates 
					WHERE graph_template_id = ?
					ORDER BY name', array($graph_template));

				if (sizeof($aggregate_templates)) {
					/* list affected graphs */
					print "<tr>
						<td class='textArea'>
							<p>" . __('Select the Aggregate Template to use and press \'Continue\' to create your Aggregate Graph.  Otherwise press \'Cancel\' to return.') . "</p>
							<div class='itemlist'><ul>" . $graph_list . "</ul></div>
						</td>
					</tr>\n";

					print "<tr><td><table>
						<tr>
							<td>" . __('Graph Title') . "</td>
							<td><input name='title_format' size='40'></td>
						</tr>
						<tr>
							<td>" . __('Aggregate Template') . "</td>
							<td>
								<select name='aggregate_template_id'>\n";

					html_create_list($aggregate_templates, 'name', 'id', $aggregate_templates[0]['id']);

					print "</select>
						</td>
					</tr></table></td></tr>\n";

					$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Create Aggregate') . "'>";
				}else{
					print "<tr>
						<td class='textArea'>
							<p>" . __('There are presently no Aggregate Templates defined for this Graph Template.  Please either first create an Aggregate Template for the selected Graphs Graph Template and try again, or simply crease an un-templated Aggregate Graph.') . "</p>
							<p>" . __('Press \'Return\' to return and select different Graphs.') . "</p>
						</td>
					</tr>\n";

					$save_html = "<input type='button' value='" . __('Return') . "' onClick='cactiReturnTo()'>";
				}
			}else{
				$save_html = "<input type='button' value='" . __('Return') . "' onClick='cactiReturnTo()'>";
			}
		}elseif (get_request_var('drp_action') == 8) { /* automation */
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to apply Automation Rules to the following Graphs.') . "</p>
					<div class='itemlist'><ul>$graph_list</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "' title='" . __('Apply Automation Rules') . "'>";
		} else {
			$save['drp_action'] = get_nfilter_request_var('drp_action');
			$save['graph_list'] = $graph_list;
			$save['graph_array'] = (isset($graph_array) ? $graph_array : array());

			api_plugin_hook_function('graphs_action_prepare', $save);

			$save_html = "<input type='button' value='" . __('Cancel') . "' onClick='cactiReturnTo()'>&nbsp;<input type='submit' value='" . __('Continue') . "'>";
		}
	}else{
		print "<tr><td class='even'><span class='textError'>" . __('You must select at least one Graph.') . "</span></td></tr>\n";

		$save_html = "<input type='button' value='" . __('Return') . "' onClick='cactiReturnTo()'>";
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($graph_array) ? serialize($graph_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . get_request_var('drp_action') . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

/* -----------------------
    item - Graph Items
   ----------------------- */

function item() {
	global $consolidation_functions, $graph_item_types, $struct_graph_item;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	if (isempty_request_var('id')) {
		$template_item_list = array();

		$header_label = __('Graph Items [new]');
	}else{
		$template_item_list = db_fetch_assoc_prepared('SELECT
			graph_templates_item.id,
			graph_templates_item.text_format,
			graph_templates_item.value,
			graph_templates_item.hard_return,
			graph_templates_item.graph_type_id,
			graph_templates_item.alpha,
			graph_templates_item.consolidation_function_id,
			data_template_rrd.data_source_name,
			cdef.name AS cdef_name,
			colors.hex
			FROM graph_templates_item
			LEFT JOIN data_template_rrd ON (graph_templates_item.task_item_id = data_template_rrd.id)
			LEFT JOIN data_local ON (data_template_rrd.local_data_id = data_local.id)
			LEFT JOIN data_template_data ON (data_local.id = data_template_data.local_data_id)
			LEFT JOIN cdef ON (cdef_id = cdef.id)
			LEFT JOIN colors ON (color_id = colors.id)
			WHERE graph_templates_item.local_graph_id = ?
			ORDER BY graph_templates_item.sequence', array(get_request_var('id')));

		$host_id = db_fetch_cell_prepared('SELECT host_id FROM graph_local WHERE id = ?', array(get_request_var('id')));
		$header_label = __('Graph Items [edit: %s]', htmlspecialchars(get_graph_title(get_request_var('id'))));
	}

	$graph_template_id = db_fetch_cell_prepared('SELECT graph_template_id FROM graph_local WHERE id = ?', array(get_request_var('id')));

	if (empty($graph_template_id)) {
		$add_text = 'graphs_items.php?action=item_edit&local_graph_id=' . get_request_var('id') . "&host_id=$host_id";
	}else{
		$add_text = '';
	}

	html_start_box($header_label, '100%', '', '3', 'center', $add_text);
	draw_graph_items_list($template_item_list, 'graphs_items.php', 'local_graph_id=' . get_request_var('id'), (empty($graph_template_id) ? false : true));
	html_end_box();
}

/* ------------------------------------
    graph - Graphs
   ------------------------------------ */

function graph_edit() {
	global $struct_graph, $image_types, $consolidation_functions, $graph_item_types, $struct_graph_item;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	$locked = 'false';
	$use_graph_template = true;

	if (!isempty_request_var('id')) {
		$_SESSION['sess_graph_lock_id'] = get_request_var('id');

		$local_graph_template_graph_id = db_fetch_cell_prepared('SELECT local_graph_template_graph_id 
			FROM graph_templates_graph 
			WHERE local_graph_id = ?', 
			array(get_request_var('id')));

		if (get_request_var('id') != $_SESSION['sess_graph_lock_id'] && !empty($local_graph_template_graph_id)) {
			$locked = 'true';
			$_SESSION['sess_graph_locked'] = $locked;
		}elseif (empty($local_graph_template_graph_id)) {
			$locked = 'false';
			$_SESSION['sess_graph_locked'] = $locked;
		}elseif (isset($_SESSION['sess_graph_locked'])) {
			$locked = $_SESSION['sess_graph_locked'];
		}else{
			$locked = 'true';
			$_SESSION['sess_graph_locked'] = $locked;
		}

		$graph = db_fetch_row_prepared('SELECT * 
			FROM graph_templates_graph 
			WHERE local_graph_id = ?', 
			array(get_request_var('id')));

		$graph_template = db_fetch_row_prepared('SELECT * 
			FROM graph_templates_graph 
			WHERE id = ?', 
			array($local_graph_template_graph_id));

		$host_id = db_fetch_cell_prepared('SELECT host_id 
			FROM graph_local 
			WHERE id = ?', 
			array(get_request_var('id')));

		$header_label = __('Graph Template Selection [edit: %s]', htmlspecialchars(get_graph_title(get_request_var('id'))));

		if ($graph['graph_template_id'] == '0') {
			$use_graph_template = 'false';
		}
	}else{
		$header_label = __('Graph Template Selection [new]');
		$use_graph_template = false;

		if (isset_request_var('host_id') && get_filter_request_var('host_id') > 0) {
			$host_id = get_request_var('host_id');
		}else{
			$host_id = 0;
		}
	}

	/* handle debug mode */
	if (isset_request_var('debug')) {
		if (get_nfilter_request_var('debug') == '0') {
			kill_session_var('graph_debug_mode');
		}elseif (get_nfilter_request_var('debug') == '1') {
			$_SESSION['graph_debug_mode'] = true;
		}
	}

	if (!isempty_request_var('id')) {
		if (isset($_SESSION['graph_debug_mode'])) {
			$message = __('Turn Off Graph Debug Mode.');
		}else{
			$message = __('Turn On Graph Debug Mode.');
		}

		?>
		<table style='width:100%;'>
			<tr>
				<td class='textInfo left' colspan='2' valign='top'>
					<?php print htmlspecialchars(get_graph_title(get_request_var('id')));?>
				</td>
				<td class='textInfo right' align='right' valign='top'>
					<span class='linkMarker'>*<a class='hyperLink' href='<?php print htmlspecialchars('graphs.php?action=graph_edit&id=' . (isset_request_var('id') ? get_request_var('id') : '0') . '&debug=' . (isset($_SESSION['graph_debug_mode']) ? '0' : '1'));?>'><?php print $message;?></a></span><br>
					<?php
						if (!empty($graph['graph_template_id'])) {
							?><span class='linkMarker'>*<a class='hyperLink' href='<?php print htmlspecialchars('graph_templates.php?action=template_edit&id=' . (isset($graph['graph_template_id']) ? $graph['graph_template_id'] : '0'));?>'><?php print __('Edit Graph Template.');?></a></span><br><?php
						}
						if (!isempty_request_var('host_id') || !empty($host_id)) {
							?><span class='linkMarker'>*<a class='hyperLink' href='<?php print htmlspecialchars('host.php?action=edit&id=' . ($host_id > 0 ? $host_id : get_request_var('host_id')));?>'><?php print __('Edit Device.');?></a></span><br><?php
						}
						if ($locked == 'true') {
							?><span class='linkMarker'>*<span class='hyperLink' id='unlockid'><?php print __('Unlock Graph.');?></span></span><?php
						}else{
							?><span class='linkMarker'>*<span class='hyperLink' id='lockid'><?php print __('Lock Graph.');?></span></span><?php
						}
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	form_start('graphs.php');

	html_start_box($header_label, '100%', '', '3', 'center', '');

	$gtsql = get_common_graph_templates($graph);

	if (!empty($graph['local_graph_id'])) {
		$graph_template_id = get_current_graph_template($graph['local_graph_id']);
	}else{
		$graph_template_id = 0;
	}

	$form_array = array(
		'graph_template_id' => array(
			'method' => 'drop_sql',
			'friendly_name' => __('Selected Graph Template'),
			'description' => __('Choose a Graph Template to apply to this Graph. Please note that you may only change Graph Templates to a 100% compatible Graph Template, which means that it includes identical Data Sources.'),
			'value' => $graph_template_id,
			'none_value' => ($graph['graph_template_id'] == 0 ? __('None'):''),
			'sql' => $gtsql
  			),
		'host_id' => array(
			'method' => 'drop_callback',
			'friendly_name' => __('Device'),
			'description' => __('Choose the Device that this Graph belongs to.'),
			'sql' => 'SELECT id, description as name FROM host ORDER BY name',
			'action' => 'ajax_hosts_noany',
			'id' => $host_id,
			'value' => db_fetch_cell_prepared('SELECT description FROM host WHERE id = ?', array($host_id)),
			),
		'graph_template_graph_id' => array(
			'method' => 'hidden',
			'value' => (isset($graph) ? $graph['id'] : '0')
			),
		'local_graph_id' => array(
			'method' => 'hidden',
			'value' => (isset($graph) ? $graph['local_graph_id'] : '0')
			),
		'local_graph_template_graph_id' => array(
			'method' => 'hidden',
			'value' => (isset($graph) ? $graph['local_graph_template_graph_id'] : '0')
			),
		'graph_template_id_prev' => array(
			'method' => 'hidden',
			'value' => $graph_template_id
			),
		'host_id_prev' => array(
			'method' => 'hidden',
			'value' => (isset($host_id) ? $host_id : '0')
			)
		);

	draw_edit_form(
		array(
			'config' => array(),
			'fields' => $form_array
			)
		);

	html_end_box();

	/* only display the "inputs" area if we are using a graph template for this graph */
	if (!empty($graph['graph_template_id'])) {
		html_start_box(__('Supplemental Graph Template Data'), '100%', '', '3', 'center', '');

		draw_nontemplated_fields_graph($graph['graph_template_id'], $graph, '|field|', '<strong>' . __('Graph Fields') . '</strong>', true, true, 0);
		draw_nontemplated_fields_graph_item($graph['graph_template_id'], get_request_var('id'), '|field|_|id|', '<strong>' . __('Graph Item Fields') . '</strong>', true, $locked);

		html_end_box();
	}

	if (isempty_request_var('id') || isempty_request_var('graph_template_id')) {
		$locked = false;
	}

	/* graph item list goes here */
	if ((!isempty_request_var('id')) && (empty($graph['graph_template_id']))) {
		item();
	}

	if (!isempty_request_var('id')) {
		?>
		<table style='width:100%;'>
			<tr>
				<td class="textInfo center" colspan="2">
					<img <?php print ($graph['image_format_id'] == 3 ? "style='width:" . $graph['width'] . "px;height:" . $graph['height'] . "px;'":"");?> src="<?php print htmlspecialchars('graph_image.php?action=edit&local_graph_id=' . get_request_var('id') . '&rra_id=' . read_user_setting('default_rra_id'));?>" alt="">
				</td>
				<?php
				if ((isset($_SESSION['graph_debug_mode'])) && (isset_request_var('id'))) {
					$graph_data_array['output_flag'] = RRDTOOL_OUTPUT_STDERR;
					$graph_data_array['print_source'] = 1;
					?>
					<td>
						<span class="textInfo"><?php print __('RRDTool Command:');?></span><br>
						<pre><?php print @rrdtool_function_graph(get_request_var('id'), 1, $graph_data_array);?></pre>
						<span class="textInfo"><?php print __('RRDTool Says:');?></span><br>
						<?php unset($graph_data_array['print_source']);?>
						<pre><?php print @rrdtool_function_graph(get_request_var('id'), 1, $graph_data_array);?></pre>
					</td>
					<?php
				}
				?>
			</tr>
		</table>
		<br>
		<?php
	}

	if (((isset_request_var('id')) || (isset_request_var('new'))) && (empty($graph['graph_template_id']))) {
		html_start_box(__('Graph Configuration'), '100%', '', '3', 'center', '');

		$form_array = array();

		while (list($field_name, $field_array) = each($struct_graph)) {
			$form_array += array($field_name => $struct_graph[$field_name]);

			$form_array[$field_name]['value'] = (isset($graph) ? $graph[$field_name] : '');
			$form_array[$field_name]['form_id'] = (isset($graph) ? $graph['id'] : '0');

			if (!(($use_graph_template == false) || ($graph_template{'t_' . $field_name} == 'on'))) {
				$form_array[$field_name]['method'] = 'template_' . $form_array[$field_name]['method'];
				$form_array[$field_name]['description'] = '';
			}
		}

		draw_edit_form(
			array(
				'config' => array('no_form_tag' => true),
				'fields' => $form_array
			)
		);

		html_end_box();
	}

	if ((isset_request_var('id')) || (isset_request_var('new'))) {
		form_hidden_box('save_component_graph','1','');
		form_hidden_box('save_component_input','1','');
	}else{
		form_hidden_box('save_component_graph_new','1','');
	}

	form_hidden_box('rrdtool_version', read_config_option('rrdtool_version'), '');
	form_save_button('graphs.php');

	//Now we need some javascript to make it dynamic
	?>
	<script type='text/javascript'>

	dynamic();

	function dynamic() {
		if ($('#scale_log_units').is(':checked')) {
			$('#scale_log_units').prop('disabled', true);
			if ($('#auto_scale_log').is(':checked')) {
				$('#scale_log_units').prop('disabled', false);
			}
		}
	}

	function changeScaleLog() {
		if ($('#scale_log_units').is(':checked')) {
			$('#scale_log_units').prop('disabled', true);
			if ($('#auto_scale_log').is(':checked')) {
				$('#scale_log_units').prop('disabled', false);
			}
		}
	}

	$(function() {
		$('#unlockid').click(function(event) {
			event.preventDefault;

			$('body').append("<div id='modal' class='ui-widget-overlay ui-front' style='z-index: 100;'><i style='position:absolute;top:50%;left:50%;' class='fa fa-spin fa-circle-o-notch'/></div>");

			$.get('graphs.php?action=unlock&header=false&id='+$('#local_graph_id').val(), function(data) {
				$('#modal').remove();
				$('#main').html(data);
				applySkin();
			});
		});

		$('#lockid').click(function(event) {
			event.preventDefault;

			loadPageNoHeader('graphs.php?action=lock&header=false&id='+$('#local_graph_id').val());
		});
	});

	if (<?php print ($locked == true ? 'true':'false');?> == true) {
		$('input, select').not('input[value="<?php print __('Cancel');?>"]').prop('disabled', true);
		$('#host_id_wrap').addClass('ui-selectmenu-disabled ui-state-disabled');
		$('#host_id_input').addClass('ui-state-disabled');
	}
	</script>
	<?php
}

function validate_graph_request_vars() {
	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'pageset' => true,
			'default' => '-1'
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'default' => '1'
			),
		'rfilter' => array(
			'filter' => FILTER_VALIDATE_IS_REGEX, 
			'pageset' => true,
			'default' => '', 
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'title_cache', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK, 
			'default' => 'ASC', 
			'options' => array('options' => 'sanitize_search_string')
			),
		'host_id' => array(
			'filter' => FILTER_VALIDATE_INT, 
			'pageset' => true,
			'default' => '-1'
			),
		'template_id' => array(
			'filter' => FILTER_VALIDATE_REGEXP,
			'options' => array('options' => array('regexp' => '(cg_[0-9]|dq_[0-9]|[\-0-9])')),
			'pageset' => true,
			'default' => '-1'
			)
	);

	validate_store_request_vars($filters, 'sess_graph');
	/* ================= input validation ================= */
}

function graph_management() {
	global $graph_actions, $item_rows;

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	}else{
		$rows = get_request_var('rows');
	}

	?>
	<script type="text/javascript">

	function applyFilter() {
		strURL  = 'graphs.php?host_id=' + $('#host_id').val();
		strURL += '&rows=' + $('#rows').val();
		strURL += '&page=' + $('#page').val();
		strURL += '&rfilter=' + $('#rfilter').val();
		strURL += '&template_id=' + $('#template_id').val();
		strURL += '&header=false';
		loadPageNoHeader(strURL);
	}

	function clearFilter() {
		strURL = 'graphs.php?clear=1&header=false';
		loadPageNoHeader(strURL);
	}

	$(function() {
		$('#clear').click(function() {
			clearFilter();
		});

		$('#filter').change(function() {
			applyFilter();
		});

		$('#form_graphs').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});
	});

	</script>
	<?php

	if (read_config_option('grds_creation_method') == 1) {
		$add_url = htmlspecialchars('graphs.php?action=graph_edit&host_id=' . get_request_var('host_id'));
	}else{
		$add_url = '';
	}

	html_start_box(__('Graph Management'), '100%', '', '3', 'center', $add_url);

	?>
	<tr class='even noprint'>
		<td>
			<form id='form_graphs' name='form_graphs' action='graphs.php'>
			<table class='filterTable'>
				<tr>
					<?php print html_host_filter(get_request_var('host_id'));?>
					<td>
						<?php print __('Template');?>
					</td>
					<td>
						<select id='template_id' name='template_id' onChange='applyFilter()'>
							<option value='-1'<?php if (get_request_var('template_id') == '-1') {?> selected<?php }?>><?php print __('Any');?></option>
							<option value='0'<?php if (get_request_var('template_id') == '0') {?> selected<?php }?>><?php print __('None');?></option>
							<?php
							$templates = get_allowed_graph_templates_normalized();
							if (sizeof($templates) > 0) {
								foreach ($templates as $template) {
									print "<option value='" . $template['id'] . "'"; if (get_request_var('template_id') == $template['id']) { print ' selected'; } print '>' . title_trim(htmlspecialchars($template['name']), 40) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<input type='submit' id='refresh' value='<?php print __('Go');?>' title='<?php print __('Set/Refresh Filters');?>'>
					</td>
					<td>
						<input type='button' id='clear' value='<?php print __('Clear');?>' title='<?php print __('Clear Filters');?>'>
					</td>
				</tr>
			</table>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input id='rfilter' type='text' name='rfilter' size='30' value='<?php print htmlspecialchars(get_request_var('rfilter'));?>'>
					</td>
					<td>
						<?php print __('Graphs');?>
					</td>
					<td>
						<select id='rows' name='rows' onChange='applyFilter()'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
							<?php
							if (sizeof($item_rows) > 0) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . htmlspecialchars($value) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<input type='hidden' id='page' name='page' value='<?php print get_request_var('page');?>'>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	/* form the 'where' clause for our main sql query */
	$sql_where = '';
	if (strlen(get_request_var('rfilter'))) {
		$sql_where = " WHERE (gtg.title_cache RLIKE '" . get_request_var('rfilter') . "'" .
			" OR gt.name RLIKE '" . get_request_var('rfilter') . "')";
	}

	if (get_request_var('host_id') == '-1') {
		/* Show all items */
	}elseif (isempty_request_var('host_id')) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' gl.host_id=0';
	}elseif (!isempty_request_var('host_id')) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' gl.host_id=' . get_request_var('host_id');
	}

	if (get_request_var('template_id') == '-1') {
		/* Show all items */
	}elseif (get_request_var('template_id') == '0') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' gtg.graph_template_id=0';
	}elseif (!isempty_request_var('template_id')) {
		$parts = explode('_', get_request_var('template_id'));
		if ($parts[0] == 'cg') {
			$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' gl.graph_template_id=' . $parts[1];
		}else{
			$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' gl.snmp_query_graph_id=' . $parts[1];
		}
	}

	/* don't allow aggregates to be view here */
	$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . ' ag.local_graph_id IS NULL';

	/* allow plugins to modify sql_where */
	$sql_where = api_plugin_hook_function('graphs_sql_where', $sql_where);

	$total_rows = db_fetch_cell("SELECT
		COUNT(gtg.id)
		FROM graph_local AS gl
		INNER JOIN graph_templates_graph AS gtg
		ON gl.id=gtg.local_graph_id
		LEFT JOIN graph_templates AS gt
		ON gl.graph_template_id=gt.id
		LEFT JOIN aggregate_graphs AS ag
		ON ag.local_graph_id=gl.id
		$sql_where");

	$graph_list = db_fetch_assoc("SELECT gtg.id, gtg.local_graph_id, gtg.height, gtg.width,
		gtg.title_cache, gt.name, gl.host_id
		FROM graph_local AS gl
		INNER JOIN graph_templates_graph AS gtg
		ON gl.id=gtg.local_graph_id
		LEFT JOIN graph_templates AS gt
		ON gl.graph_template_id=gt.id
		LEFT JOIN aggregate_graphs AS ag
		ON ag.local_graph_id=gl.id
		$sql_where
		ORDER BY " . get_request_var('sort_column') . ' ' . get_request_var('sort_direction') .
		' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows);

	$nav = html_nav_bar('graphs.php?rfilter=' . get_request_var('rfilter') . '&host_id=' . get_request_var('host_id'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, 'Graphs', 'page', 'main');

	form_start('graphs.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'title_cache'    => array('display' => __('Graph Name'), 'align' => 'left', 'sort' => 'ASC', 'tip' => __('The Title of this Graph.  Generally programatically generated from the Graph Template definition or Suggested Naming rules.  The max length of the Title is controlled under Settings->Visual.')),
		'local_graph_id' => array('display' => __('ID'), 'align' => 'right', 'sort' => 'ASC', 'tip' => __('The internal database ID fro this Graph.  Useful when performing automation or debugging.')),
		'name'           => array('display' => __('Template Name'), 'align' => 'left', 'sort' => 'ASC', 'tip' => __('The Graph Template that this Graph was based upon.')),
		'height'         => array('display' => __('Size'), 'align' => 'left', 'sort' => 'ASC', 'tip' => __('The size of this Graph when not in Preview mode.'))
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (sizeof($graph_list)) {
		foreach ($graph_list as $graph) {
			/* we're escaping strings here, so no need to escape them on form_selectable_cell */
			$template_name = ((empty($graph['name'])) ? '<em>None</em>' : htmlspecialchars($graph['name']));
			$template_name = get_current_graph_template_name($graph['local_graph_id']);
			form_alternate_row('line' . $graph['local_graph_id'], true);
			form_selectable_cell(filter_value(title_trim($graph['title_cache'], read_config_option('max_title_length')), get_request_var('rfilter'), 'graphs.php?action=graph_edit&id=' . $graph['local_graph_id']), $graph['local_graph_id']);
			form_selectable_cell($graph['local_graph_id'], $graph['local_graph_id'], '', 'text-align:right');
			form_selectable_cell(filter_value($template_name, get_request_var('rfilter')), $graph['local_graph_id']);
			form_selectable_cell($graph['height'] . 'x' . $graph['width'], $graph['local_graph_id']);
			form_checkbox_cell($graph['title_cache'], $graph['local_graph_id']);
			form_end_row();
		}
	}else{
		print "<tr class='tableRow'><td colspan='5'><em>" . __('No Graphs Found') . "</em></td></tr>";
	}

	html_end_box(false);

	if (sizeof($graph_list)) {
		print $nav;
	}

	/* add a list of tree names to the actions dropdown */
	add_tree_names_to_actions_array();

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($graph_actions);

	form_end();
}

