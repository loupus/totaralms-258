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
 * @author Aaron Wells <aaronw@catalyst.net.nz>
 * @package totara
 * @subpackage cohort/rules/sqlhandlers
 */
/**
 * This file contains sqlhandler for rules based on date fields
 */
if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

define('COHORT_RULE_DATE_OP_BEFORE_FIXED_DATE', 10);
define('COHORT_RULE_DATE_OP_AFTER_FIXED_DATE', 20);
define('COHORT_RULE_DATE_OP_BEFORE_PAST_DURATION', 30);
define('COHORT_RULE_DATE_OP_WITHIN_PAST_DURATION', 40);
define('COHORT_RULE_DATE_OP_WITHIN_FUTURE_DURATION', 50);
define('COHORT_RULE_DATE_OP_AFTER_FUTURE_DURATION', 60);
global $COHORT_RULE_DATE_OP;
$COHORT_RULE_DATE_OP = array(
    COHORT_RULE_DATE_OP_BEFORE_FIXED_DATE => 'before',
    COHORT_RULE_DATE_OP_AFTER_FIXED_DATE => 'after',
    COHORT_RULE_DATE_OP_BEFORE_PAST_DURATION => 'beforepastduration',
    COHORT_RULE_DATE_OP_WITHIN_PAST_DURATION => 'inpastduration',
    COHORT_RULE_DATE_OP_WITHIN_FUTURE_DURATION => 'inpastduration',
    COHORT_RULE_DATE_OP_AFTER_FUTURE_DURATION => 'beforepastduration',
);


/**
 * Handles rules that are comparing a DB column versus a specific date timestamp
 */
abstract class cohort_rule_sqlhandler_date extends cohort_rule_sqlhandler {
    public $params = array(
        'operator' => 0,
        'date' => 0,
    );

    /**
     * The database field we're comparing against. May be a column name or a custom field id
     * @var mixed
     */
    public $field;

    public function __construct($field){
        $this->field = $field;
    }

    public function get_sql_snippet(){
        global $COHORT_RULE_DATE_OP;

        switch ($this->operator) {
            case COHORT_RULE_DATE_OP_BEFORE_FIXED_DATE:
                $comparison = "<= {$this->date}";
                break;
            case COHORT_RULE_DATE_OP_AFTER_FIXED_DATE:
                $comparison = ">= {$this->date}";
                break;
            case COHORT_RULE_DATE_OP_BEFORE_PAST_DURATION:
                $comparison = '<= ' . (time() - ($this->date * 24 * 60 * 60));
                break;
            case COHORT_RULE_DATE_OP_WITHIN_PAST_DURATION:
                $comparison = 'BETWEEN ' . (time() - ($this->date * 24 * 60 * 60)) . ' AND ' . time();
                break;
            case COHORT_RULE_DATE_OP_WITHIN_FUTURE_DURATION:
                $comparison = 'BETWEEN ' . time() . ' AND ' . (time() + ($this->date * 24 * 60 * 60));
                break;
            case COHORT_RULE_DATE_OP_AFTER_FUTURE_DURATION:
                $comparison = '>= ' . (time() + ($this->date * 24 * 60 * 60));
                break;
        }
        return $this->construct_sql_snippet($this->field, $comparison);
    }

    /**
     * Concatenates together the actual string bits to return the SQL snippet
     * @param $field str
     * @param $comparison str
     */
    abstract protected function construct_sql_snippet($field, $comparison);
}

/**
 * SQL snippet for a field of the mdl_user table, holding a timestamp
 */
class cohort_rule_sqlhandler_date_userfield extends cohort_rule_sqlhandler_date {
    protected function construct_sql_snippet($field, $comparison) {
        global $DB;
        $sqlhandler = new stdClass();
        $sqlhandler->sql = "u.{$field} {$comparison}";
        $sqlhandler->params = array();
        return $sqlhandler;
    }
}

/**
 * SQL snippet for a user custom field holding a timestamp
 */
class cohort_rule_sqlhandler_date_usercustomfield extends cohort_rule_sqlhandler_date {
    protected function construct_sql_snippet($field, $comparison) {
        global $DB;
        $sqlhandler = new stdClass();
        $sqlhandler->sql = "exists("
                ."select 1 from {user_info_data} usinda "
                ."where usinda.userid=u.id "
                ."and usinda.fieldid={$field} "
                ."and " . $DB->sql_cast_char2int('usinda.data', true) . " {$comparison}"
            .")";
        $sqlhandler->params = array();
        return $sqlhandler;
    }
}

/**
 * SQL snippet for finding out when a user started their primary position
 */
class cohort_rule_sqlhandler_date_posstarted extends cohort_rule_sqlhandler_date {
    public function __construct(){
        // No arguments necessary, this is a single-purpose SQL handler to
        // query only one field
    }

    protected function construct_sql_snippet($field, $comparison) {
        global $DB;
        $sqlhandler = new stdClass();
        // Somewhere down the Totara road map they will get rid of the
        // "prog_pos_assignment" field and either fold the timeassigned
        // field into the pos_assignment table, or rely on the pos_assignment_history
        // table. At that time, we'll need to change this class
        $sqlhandler->sql = "exists("
                ."select 1 "
                ."from {prog_pos_assignment} ppa "
                ."inner join {pos} p "
                ."on ppa.positionid=p.id "
                ."where ppa.userid=u.id "
                ."and ppa.type=" . POSITION_TYPE_PRIMARY . " "
                ."and ppa.timeassigned {$comparison}"
            .")";
        $sqlhandler->params = array();
        return $sqlhandler;
    }
}

/**
 * SQL snippet for a position custom field holding a timestamp
 */
class cohort_rule_sqlhandler_date_poscustomfield extends cohort_rule_sqlhandler_date {
    protected function construct_sql_snippet($field, $comparison) {
        global $DB;
        $sqlhandler = new stdClass();
        $sqlhandler->sql = "exists("
                ."select 1 "
                ."from {pos_assignment} pa "
                ."inner join {pos_type_info_data} ptid "
                ."on pa.positionid=ptid.positionid and ptid.data != '' and ptid.data is not null "
                ."where pa.userid=u.id "
                ."and pa.type=".POSITION_TYPE_PRIMARY." "
                ."and ptid.fieldid={$field} "
                ."and ".$DB->sql_cast_char2int('ptid.data', true)." {$comparison}"
            .")";
        $sqlhandler->params = array();
        return $sqlhandler;
    }
}

/**
 * SQL snippet for a organisation custom field holding a timestamp
 */
class cohort_rule_sqlhandler_date_orgcustomfield extends cohort_rule_sqlhandler_date {
    protected function construct_sql_snippet($field, $comparison) {
        global $DB;
        $sqlhandler = new stdClass();
        $sqlhandler->sql = "exists("
                ."select 1 "
                ."from {pos_assignment} pa "
                ."inner join {org_type_info_data} otid "
                ."on pa.organisationid=otid.organisationid and otid.data != '' and otid.data is not null "
                ."where pa.userid=u.id "
                ."and pa.type=".POSITION_TYPE_PRIMARY." "
                ."and otid.fieldid={$field} "
                ."and ".$DB->sql_cast_char2int('otid.data', true)." {$comparison}"
            .")";
        $sqlhandler->params = array();
        return $sqlhandler;
    }
}
