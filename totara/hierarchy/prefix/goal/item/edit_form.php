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
 * @subpackage totara_hierarchy
 */

require_once($CFG->dirroot.'/lib/formslib.php');
require_once($CFG->dirroot.'/totara/hierarchy/lib.php');
require_once($CFG->dirroot.'/totara/hierarchy/prefix/goal/lib.php');
require_once($CFG->dirroot.'/totara/hierarchy/item/edit_form.php');

class goal_edit_form extends item_edit_form {

    // Load data for the form.
    public function definition_hierarchy_specific() {
        global $DB;

        $mform =& $this->_form;
        $item = $this->_customdata['item'];

        // Get the name of the framework's scale. (Note this code expects there.
        // To be only one scale per framework, even though the DB structure.
        // Allows there to be multiple since we're using a go-between table).
        $scaledesc = $DB->get_field_sql("
            SELECT s.name
            FROM
                {{$this->hierarchy->shortprefix}_scale} s,
                {{$this->hierarchy->shortprefix}_scale_assignments} a
            WHERE
                a.frameworkid = ?
                and a.scaleid = s.id
        ", array($item->frameworkid));

        $mform->addElement('static', 'scalename', get_string('scale'), ($scaledesc) ? $scaledesc : get_string('none'));
        $mform->addHelpButton('scalename', 'goalscale', 'totara_hierarchy');

    }
}

class goal_edit_personal_form extends moodleform {

    // Define the form.
    public function definition() {
        global $DB, $TEXTAREA_OPTIONS;

        // Javascript include.
        local_js(array(
            TOTARA_JS_DIALOG,
            TOTARA_JS_UI,
            TOTARA_JS_DATEPICKER,
            TOTARA_JS_ICON_PREVIEW,
            TOTARA_JS_PLACEHOLDER
        ));

        // Attach a date picker to date fields.
        build_datepicker_js(
            'input[name="targetdateselector"]'
        );

        $mform =& $this->_form;

        // Add some extra hidden fields.
        $mform->addElement('hidden', 'goalpersonalid');
        $mform->setType('goalpersonalid', PARAM_INT);
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'targetdate');
        $mform->setType('targetdate', PARAM_INT);

        // Name.
        $mform->addElement('text', 'name', get_string('name'), 'maxlength="1024" size="50"');
        $mform->addRule('name', get_string('goalmissingname', 'totara_hierarchy'), 'required', null);
        $mform->setType('name', PARAM_MULTILANG);

        // Description.
        $mform->addElement('editor', 'description_editor', get_string('description', 'totara_hierarchy'),
                null, $TEXTAREA_OPTIONS);
        $mform->addHelpButton('description_editor', 'goaldescription', 'totara_hierarchy');
        $mform->setType('description_editor', PARAM_CLEANHTML);

        // Scale.
        $scales = $DB->get_records('goal_scale', array());
        $scaledesc = array(0 => get_string('none'));
        foreach ($scales as $scale) {
            $scaledesc[$scale->id] = $scale->name;
        }
        $mform->addElement('select', 'scaleid', get_string('scale'), ($scaledesc) ? $scaledesc : get_string('none'));
        $mform->addHelpButton('scaleid', 'goalscale', 'totara_hierarchy');

        // Target date.
        $mform->addElement('text', 'targetdateselector', get_string('goaltargetdate', 'totara_hierarchy'),
                 array('placeholder' => get_string('datepickerlongyearplaceholder', 'totara_core')));
        $mform->addHelpButton('targetdateselector', 'goaltargetdate', 'totara_hierarchy');
        $mform->setType('targetdateselector', PARAM_MULTILANG);

        $this->add_action_buttons();
    }

    public function set_data($data) {
        global $TEXTAREA_OPTIONS, $CFG;

        $options = $TEXTAREA_OPTIONS;

        if (!empty($data->targetdate)) {
            // Format and name the data correctly for the date selector.
            $data->targetdateselector = userdate($data->targetdate, get_string('datepickerlongyearphpuserdate', 'totara_core'),
                    $CFG->timezone, false);
        }

        if (!empty($data->description)) {
            // Same again for the description.
            $data->descriptionformat = FORMAT_HTML;
            $data = file_prepare_standard_editor($data, 'description', $options, $options['context'],
                    'totara_hierarchy', 'goal', $data->id);
        }

        // Everything else should be fine, set the data.
        parent::set_data($data);
    }

    public function validation($fromform, $files) {
        global $DB;
        $errors = array();
        $fromform = (object)$fromform;

        // Check user exists.
        if (!$DB->record_exists('user', array('id' => $fromform->userid))) {
            $errors['user'] = get_string('userdoesnotexist', "totara_core");
        }

        // Check scale exists.
        if (!empty($fromform->scaleid) && !$DB->record_exists('goal_scale', array('id' => $fromform->scaleid))) {
            $errors['scale'] = get_string('invalidgoalscale', "totara_hierarchy");
        }

        // Check target date is in the future.
        $dateparseformat = get_string('datepickerlongyearparseformat', 'totara_core');
        if (!empty($fromform->targetdateselector)) {
            $targetdate = $fromform->targetdateselector;
            if (!empty($targetdate)) {
                if ($date = totara_date_parse_from_format($dateparseformat, $targetdate)) {
                    if ($date < time()) {
                        $errors['targetdateselector'] = get_string('error:invaliddatepast', 'totara_hierarchy');
                    }
                } else {
                    $errors['targetdateselector'] = get_string('error:invaliddateformat', 'totara_hierarchy',
                            get_string('datepickerlongyearplaceholder', 'totara_core'));
                }
            }
        }

        return $errors;
    }
}
