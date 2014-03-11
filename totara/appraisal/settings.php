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
 * @subpackage totara_appraisal
 */

global $SITE, $CFG;

$capabilities = array(
    'totara/appraisal:manageappraisals',
    'totara/appraisal:cloneappraisal',
    'totara/appraisal:assignappraisaltogroup',
    'totara/appraisal:managenotifications',
    'totara/appraisal:manageactivation',
    'totara/appraisal:managepageelements');

if ($hassiteconfig || has_any_capability($capabilities, $systemcontext)) {

    $ADMIN->add('appraisals',
        new admin_externalpage('manageappraisals',
            new lang_string('manageappraisals', 'totara_appraisal'),
            new moodle_url('/totara/appraisal/manage.php'),
            $capabilities,
            empty($CFG->enableappraisals)
        )
    );

    $ADMIN->add('appraisals',
        new admin_externalpage('managefeedback360',
            new lang_string('managefeedback360', 'totara_feedback360'),
            new moodle_url('/totara/feedback360/manage.php'),
            $capabilities,
            empty($CFG->enablefeedback360)
        )
    );

    $ADMIN->add('appraisals',
        new admin_externalpage('reportappraisals',
            new lang_string('reportappraisals', 'totara_appraisal'),
            new moodle_url('/totara/appraisal/reports.php'),
            $capabilities,
            empty($CFG->enableappraisals)
        )
    );
}
