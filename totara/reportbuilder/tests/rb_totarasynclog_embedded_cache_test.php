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
 * @author Valerii Kuznetsov <valerii.kuznetsov@totaralms.com>
 * @package totara
 * @subpackage reportbuilder
 *
 * Unit/functional tests to check Sync log reports caching
 */
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}
global $CFG;
require_once($CFG->dirroot . '/totara/reportbuilder/tests/reportcache_advanced_testcase.php');
require_once($CFG->dirroot . '/admin/tool/totara_sync/lib.php');

class rb_totarasynclog_embedded_cache_test extends reportcache_advanced_testcase {
    // testcase data
    protected $report_builder_data = array('id' => 15, 'fullname' => 'Sync log', 'shortname' => 'totarasynclog',
                                           'source' => 'totara_sync_log', 'hidden' => 1, 'embedded' => 1);

    protected $report_builder_columns_data = array(
                        array('id' => 69, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'id',
                              'heading' => 'A', 'sortorder' => 1),
                        array('id' => 70, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'time',
                              'heading' => 'B', 'sortorder' => 2),
                        array('id' => 71, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'element',
                              'heading' => 'C', 'sortorder' => 3),
                        array('id' => 72, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'logtype',
                              'heading' => 'D', 'sortorder' => 4),
                        array('id' => 73, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'action',
                              'heading' => 'E', 'sortorder' => 5),
                        array('id' => 74, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'info',
                              'heading' => 'F', 'sortorder' => 6));

    protected $report_builder_filters_data = array(
                        array('id' => 31, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'time',
                              'sortorder' => 1, 'advanced' => 0),
                        array('id' => 32, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'element',
                              'sortorder' => 2, 'advanced' => 0),
                        array('id' => 33, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'logtype',
                              'sortorder' => 3, 'advanced' => 0),
                        array('id' => 34, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'action',
                              'sortorder' => 4, 'advanced' => 0),
                        array('id' => 35, 'reportid' => 15, 'type' => 'totara_sync_log', 'value' => 'info',
                              'sortorder' => 5, 'advanced' => 0));

    // Work data
    protected $logs = null;
    protected static $ind = 0;

    /**
     * Prepare mock data for testing
     *
     * Common part of all test cases:
     * - Prepare 10 sync log records
     * - Record 3 and 5 has word 'critical'
     */
    protected function setUp() {
        parent::setup();
        $this->loadDataSet($this->createArrayDataSet(array('report_builder' => array($this->report_builder_data),
                                                           'report_builder_columns' => $this->report_builder_columns_data,
                                                           'report_builder_filters' => $this->report_builder_filters_data)));

        for ($a = 0; $a <= 9; $a++) {
            $data = array();
            if ($a == 3 || $a == 5) {
                $data = array('info' => 'Record with critical word '. $a);
            }

            $this->logs[] = $this->create_synclog($data);
        }
    }

    /**
     * Test programs report
     * Test case:
     * - Common part (@see: self::setUp() )
     * - Find all synclogs
     * - Find synclog entry with word 'level'
     *
     * @param int Use cache or not (1/0)
     * @dataProvider provider_use_cache
     */
    public function test_synclog($usecache) {
        $this->resetAfterTest();
        if ($usecache) {
            $this->enable_caching($this->report_builder_data['id']);
        }

        $result = $this->get_report_result($this->report_builder_data['shortname'], array(), $usecache);

        $this->assertCount(10, $result);

        $form = array('totara_sync_log-info' => array('operator' => 0, 'value' => 'critical'));
        $result = $this->get_report_result($this->report_builder_data['shortname'], array(), $usecache, $form);
        $this->assertCount(2, $result);
        $was = array();
        foreach ($result as $r) {
             $this->assertStringMatchesFormat('Record with critical word %d', $r->totara_sync_log_info);
             $this->assertContains($r->id, array($this->logs[3]->id, $this->logs[5]->id));
             $this->assertNotContains($r->id, $was);
             $was[] = $r->id;
        }

    }

    /**
     * Create mock synclog record
     *
     * @param array $data Ovveride default properties
     * @return stdClass Program record
     */
    protected function create_synclog($data = array()) {
        global $DB;
        self::$ind++;
        $element = isset($data['element']) ? $data['element'] : 'test';
        $info =  isset($data['info']) ? $data['info'] : 'Log info #'. self::$ind;
        $type = isset($data['type']) ? $data['type'] : 'info';
        $action = isset($data['action']) ? $data['action'] : '';

        $lid = totara_sync_log($element, $info, $type, $action);
        $record = $DB->get_record('totara_sync_log', array('id' => $lid));
        return $record;
    }
}