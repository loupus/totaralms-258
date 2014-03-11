<?php
/*
 * This file is part of Totara LMS
 *
 * Copyright (C) 2010 onwards Totara Learning Solutions LTD
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Simon Coggins <simon.coggins@totaralms.com>
 * @package totara
 * @subpackage reportbuilder
 */

require_once($CFG->dirroot.'/totara/core/db/utils.php');

/**
 * Local database upgrade script
 *
 * @param   integer $oldversion Current (pre-upgrade) local db version timestamp
 * @return  boolean $result
 */
function xmldb_totara_reportbuilder_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager(); // loads ddl manager and xmldb classes

    if ($oldversion < 2012071300) {
        // handle renaming of assignment field: description to intro
        foreach (array('columns', 'filters') as $table) {
            $sql = "SELECT rbt.id FROM {report_builder_{$table}} rbt
                JOIN {report_builder} rb
                ON rbt.reportid = rb.id
                WHERE
                (rbt.type = ? AND rbt.value = ? AND rb.source = ?) OR
                (rbt.type = ? AND rbt.value = ? AND rb.source = ?)";
            $items = $DB->get_fieldset_sql($sql, array(
                'assignment', 'description', 'assignment',
                'base', 'description', 'assignmentsummary'));

            if (!empty($items)) {
                list($insql, $inparams) = $DB->get_in_or_equal($items);
                $sql = "UPDATE {report_builder_{$table}} SET value = ? WHERE id {$insql}";
                $params = array_merge(array('intro'), $inparams);
                $DB->execute($sql, $params);
            }
        }
        totara_upgrade_mod_savepoint(true, 2012071300, 'totara_reportbuilder');
    }

    if ($oldversion < 2012071900) {
        require_once($CFG->dirroot . '/totara/reportbuilder/lib.php');
        // rename the aggregated user columns/filters to avoid clashing with standard user fields
        reportbuilder_rename_data('columns', 'course_completion_by_org', 'user', 'fullname', 'user', 'allparticipants');
        reportbuilder_rename_data('filters', 'course_completion_by_org', 'user', 'fullname', 'user', 'allparticipants');
        totara_upgrade_mod_savepoint(true, 2012071900, 'totara_reportbuilder');
    }

    if ($oldversion < 2012073100) {
        // set global setting for financial year
        // default: July, 1
        set_config('financialyear', '0107', 'reportbuilder');
        totara_upgrade_mod_savepoint(true, 2012073100, 'totara_reportbuilder');
    }

    if ($oldversion < 2012081000) {
        // need to migrate saved search data from the database
        // to remove extraneous array that is no longer used
        $searches = $DB->get_recordset('report_builder_saved', null, '', 'id, search');
        foreach ($searches as $search) {
            $todb = new stdClass();
            $todb->id = $search->id;
            $currentfilters = unserialize($search->search);
            $newfilters = array();
            foreach ($currentfilters as $key => $filter) {
                // if the filter contains an array with only the [0] key set
                // assume it is no longer needed and remove it
                $newfilters[$key] = (isset($filter[0]) && count($filter) == 1) ? $filter[0] : $filter;
            }
            $todb->search = serialize($newfilters);
            $DB->update_record('report_builder_saved', $todb);
        }
        $searches->close();
        totara_upgrade_mod_savepoint(true, 2012081000, 'totara_reportbuilder');
    }

    if ($oldversion < 2012112300) {
        // Convert saved searches with status to the new status field
        $filter = 'course_completion-status';

        $like_sql = $DB->sql_like('rs.search', '?');

        $sql = "SELECT rs.id, rs.search
                FROM {report_builder_saved} AS rs
                JOIN {report_builder} AS rb ON rb.id = rs.reportid
                WHERE rb.source = ?
                AND {$like_sql}";

        $params = array('course_completion', '%' . $DB->sql_like_escape($filter) . '%');

        $searches = $DB->get_records_sql($sql, $params);

        require_once($CFG->dirroot . '/completion/completion_completion.php');

        foreach ($searches as $search) {
            $todb = new stdClass();
            $todb->id = $search->id;
            $data = unserialize($search->search);

            if (isset($data[$filter])) {
                $options = $data[$filter];
                if (isset($options['operator']) && isset($options['value']) && is_int($options['operator']) && is_string($options['value'])) {
                    $operator = $options['operator'];
                    $value = $options['value'];
                    if (($operator == 1 && $value == '0') || ($operator == 2 && $value == '1')) {
                        // Completion Status is equal to "Not completed" or
                        // Completion Status isn't equal to "Completed"
                        $options['value'] = array(
                            COMPLETION_STATUS_NOTYETSTARTED => "1",
                            COMPLETION_STATUS_INPROGRESS => "1",
                            COMPLETION_STATUS_COMPLETE => "0",
                            COMPLETION_STATUS_COMPLETEVIARPL => "0" );
                    } else if (($operator == 1 && $value == '1') || ($operator == 2 && $value == '0')) {
                        // Completion Status is equal to "Completed" or
                        // Completion Status isn't equal to "Not completed"
                        $options['value'] = array(
                            COMPLETION_STATUS_NOTYETSTARTED => "0",
                            COMPLETION_STATUS_INPROGRESS => "0",
                            COMPLETION_STATUS_COMPLETE => "1",
                            COMPLETION_STATUS_COMPLETEVIARPL => "1" );
                    } else {
                        // not the expected data so leave the data alone
                        continue;
                    }
                    // Set the operator to any of the following
                    $options['operator'] = 1;
                    $data[$filter] = $options;
                    $todb->search = serialize($data);
                    $DB->update_record('report_builder_saved', $todb);
                }
            }
        }
        totara_upgrade_mod_savepoint(true, 2012112300, 'totara_reportbuilder');
    }

    if ($oldversion < 2013021100) {
        $table = new xmldb_table('report_builder');
        $field1 = new xmldb_field('cache', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0, 'hidden');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $settingstable = new xmldb_table('report_builder_settings');
        $fieldcache = new xmldb_field('cachedvalue', XMLDB_TYPE_CHAR, '255', null, null, null, 0, 'value');
        if (!$dbman->field_exists($settingstable, $fieldcache)) {
            $dbman->add_field($settingstable, $fieldcache);
        }

        $tablecache = new xmldb_table('report_builder_cache');

        $tablecache->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $tablecache->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tablecache->add_field('cachetable', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $tablecache->add_field('frequency', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tablecache->add_field('schedule', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $tablecache->add_field('lastreport', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $tablecache->add_field('nextreport', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $tablecache->add_field('config', XMLDB_TYPE_TEXT, '', null, null, null, null);
        $tablecache->add_field('changed', XMLDB_TYPE_INTEGER, '1', null, null, null, null);
        $tablecache->add_field('genstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        $tablecache->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $tablecache->add_key('reportid', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', array('id'));

        $tablecache->add_index('nextreport', XMLDB_INDEX_NOTUNIQUE, array('nextreport'));

        if (!$dbman->table_exists('report_builder_cache')) {
            $dbman->create_table($tablecache);
        }

        totara_upgrade_mod_savepoint(true, 2013021100, 'totara_reportbuilder');
    }

    if ($oldversion < 2013032700) {
        //add new column to check for pre-filtering
        $table = new xmldb_table('report_builder');
        $field = new xmldb_field('initialdisplay');
        $field->set_attributes(XMLDB_TYPE_INTEGER, 1, null, XMLDB_NOTNULL, null, 0, 'embedded');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        totara_upgrade_mod_savepoint(true, 2013032700, 'totara_reportbuilder');
    }

    if ($oldversion < 2013061000) {
        $table = new xmldb_table('report_builder_schedule');
        $field = new xmldb_field('exporttofilesystem', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'frequency');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        set_config('exporttofilesystem', 0, 'reportbuilder');
        totara_upgrade_mod_savepoint(true, 2013061000, 'totara_reportbuilder');
    }

    if ($oldversion < 2013092400) {
        $table = new xmldb_table('report_builder_filters');
        $namefield = new xmldb_field('filtername', XMLDB_TYPE_CHAR, '255');
        $customnamefield = new xmldb_field('customname', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $namefield)) {
            $dbman->add_field($table, $namefield);
        }
        if (!$dbman->field_exists($table, $customnamefield)) {
            $dbman->add_field($table, $customnamefield);
        }
        // Add pdf to the default export options.
        $currentexportoptions = get_config('reportbuilder', 'exportoptions');
        $currentexportoptions = is_null($currentexportoptions) ? 0 : $currentexportoptions;
        $newexportoptions = ($currentexportoptions | REPORT_BUILDER_EXPORT_PDF_PORTRAIT | REPORT_BUILDER_EXPORT_PDF_LANDSCAPE);
        set_config('exportoptions', $newexportoptions, 'reportbuilder');
        totara_upgrade_mod_savepoint(true, 2013092400, 'totara_reportbuilder');
    }

    if ($oldversion < 2013103000) {
        // Adding foreign keys.
        $tables = array(
            'report_builder_columns' => array(
                new xmldb_key('repobuilcolu_rep_fk', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', 'id')),

            'report_builder_filters' => array(
                new xmldb_key('repobuilfilt_rep_fk', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', 'id')),

            'report_builder_settings' => array(
                new xmldb_key('repobuilsett_rep_fk', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', 'id')),

            'report_builder_saved' => array(
                new xmldb_key('repobuilsave_rep_fk', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', 'id'),
                new xmldb_key('repobuilsave_use_fk', XMLDB_KEY_FOREIGN, array('userid'), 'user', 'id')),

            'report_builder_group_assign' => array(
                new xmldb_key('repobuilgrouassi_gro_fk', XMLDB_KEY_FOREIGN, array('groupid'), 'report_builder_group', 'id')),

            'report_builder_preproc_track' => array(
                new xmldb_key('repobuilpreptrac_gro_fk', XMLDB_KEY_FOREIGN, array('groupid'), 'report_builder_group', 'id')),

            'report_builder_schedule' => array(
                new xmldb_key('repobuilsche_rep_fk', XMLDB_KEY_FOREIGN, array('reportid'), 'report_builder', 'id'),
                new xmldb_key('repobuilsche_use_fk', XMLDB_KEY_FOREIGN, array('userid'), 'user', 'id'),
                new xmldb_key('repobuilsche_sav_fk', XMLDB_KEY_FOREIGN, array('savedsearchid'), 'report_builder_saved', 'id')));

        foreach ($tables as $tablename => $keys) {
            $table = new xmldb_table($tablename);
            foreach ($keys as $key) {
                $dbman->add_key($table, $key);
            }
        }

        // Report builder savepoint reached.
        totara_upgrade_mod_savepoint(true, 2013103000, 'totara_reportbuilder');
    }

    if ($oldversion < 2013121000) {
        require_once($CFG->dirroot . '/totara/reportbuilder/lib.php');

        // Rename any existing records for the timecompleted column/filter in dp_certifications.
        reportbuilder_rename_data('columns', 'dp_certification', 'prog_completion', 'timecompleted', 'certif_completion', 'timecompleted');
        reportbuilder_rename_data('filters', 'dp_certification', 'prog_completion', 'timecompleted', 'certif_completion', 'timecompleted');

        // Report builder savepoint reached.
        totara_upgrade_mod_savepoint(true, 2013121000, 'totara_reportbuilder');
    }

    if ($oldversion < 2014012400) {
        // Changing length of field from 255 to 1024 to match length of hierarchy custom field names.
        $table = new xmldb_table('report_builder_columns');
        $field = new xmldb_field('heading', XMLDB_TYPE_CHAR, '1024', null, null, null, null, 'value');
        // Launch change of type for field heading
        $dbman->change_field_precision($table, $field);
        // Changing length of field from 255 to 1024 to match length of hierarchy custom field names.
        $table = new xmldb_table('report_builder_filters');
        $field = new xmldb_field('filtername', XMLDB_TYPE_CHAR, '1024', null, null, null, null, 'advanced');
        // Launch change of type for field filtername
        $dbman->change_field_precision($table, $field);
        totara_upgrade_mod_savepoint(true, 2014012400, 'totara_reportbuilder');
    }
    return true;
}
