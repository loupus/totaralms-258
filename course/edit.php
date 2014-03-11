<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Edit course settings
 *
 * @package    moodlecore
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../config.php');
require_once('lib.php');
require_once('edit_form.php');
require_once($CFG->dirroot.'/totara/core/js/lib/setup.php');
require_once($CFG->dirroot.'/totara/customfield/fieldlib.php');

$usetags = (!empty($CFG->usetags) && get_config('moodlecourse', 'coursetagging') == 1) ? true : false;

if ($usetags) {
    require_once($CFG->dirroot.'/tag/lib.php');
}
require_once($CFG->dirroot.'/cohort/lib.php');
require_once($CFG->dirroot.'/totara/cohort/lib.php');
require_once($CFG->dirroot.'/totara/program/lib.php');

$id         = optional_param('id', 0, PARAM_INT);       // course id
$categoryid = optional_param('category', 0, PARAM_INT); // course category - can be changed in edit form
$returnto = optional_param('returnto', 0, PARAM_ALPHANUM); // generic navigation return page switch
$nojs = optional_param('nojs', 0, PARAM_INT);

$PAGE->set_pagelayout('admin');
$pageparams = array('id'=>$id);
if (empty($id)) {
    $pageparams = array('category'=>$categoryid);
}
$PAGE->set_url('/course/edit.php', $pageparams);

// basic access control checks
if ($id) { // editing course
    if ($id == SITEID){
        // don't allow editing of  'site course' using this form
        print_error('cannoteditsiteform');
    }

    $course = course_get_format($id)->get_course();
    if ($usetags) {
        $course->otags = array_keys(tag_get_tags_array('course', $course->id, 'official'));
    }
    require_login($course);
    $category = $DB->get_record('course_categories', array('id'=>$course->category), '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);
    require_capability('moodle/course:update', $coursecontext);

    customfield_load_data($course, 'course', 'course');

} else if ($categoryid) { // creating new course in this category
    $course = null;
    require_login();
    $category = $DB->get_record('course_categories', array('id'=>$categoryid), '*', MUST_EXIST);
    $catcontext = context_coursecat::instance($category->id);
    require_capability('moodle/course:create', $catcontext);
    $PAGE->set_context($catcontext);

} else {
    require_login();
    print_error('needcoursecategroyid');
}

// Set up JS
local_js(array(
        TOTARA_JS_UI,
        TOTARA_JS_ICON_PREVIEW,
        TOTARA_JS_DIALOG,
        TOTARA_JS_TREEVIEW
        ));

// Enrolled audiences.
if (empty($course->id)) {
    $enrolledselected = '';
} else {
    $enrolledselected = totara_cohort_get_course_cohorts($course->id, null, 'c.id');
    $enrolledselected = !empty($enrolledselected) ? implode(',', array_keys($enrolledselected)) : '';
}
$PAGE->requires->strings_for_js(array('coursecohortsenrolled'), 'totara_cohort');
$jsmodule = array(
        'name' => 'totara_cohortdialog',
        'fullpath' => '/totara/cohort/dialog/coursecohort.js',
        'requires' => array('json'));
$args = array('args'=>'{"enrolledselected":"' . $enrolledselected . '",'.
        '"COHORT_ASSN_VALUE_ENROLLED":' . COHORT_ASSN_VALUE_ENROLLED . '}');
$PAGE->requires->js_init_call('M.totara_coursecohort.init', $args, true, $jsmodule);
unset($enrolledselected);

// Visible audiences.
if (!empty($CFG->audiencevisibility)) {
    if(empty($course->id)) {
        $visibleselected = '';
    } else {
        $visibleselected = totara_cohort_get_visible_learning($course->id);
        $visibleselected = !empty($visibleselected) ? implode(',', array_keys($visibleselected)) : '';
    }
    $PAGE->requires->strings_for_js(array('coursecohortsvisible'), 'totara_cohort');
    $jsmodule = array(
                    'name' => 'totara_visiblecohort',
                    'fullpath' => '/totara/cohort/dialog/visiblecohort.js',
                    'requires' => array('json'));
    $args = array('args'=>'{"visibleselected":"' . $visibleselected . '", "type":"course"}');
    $PAGE->requires->js_init_call('M.totara_visiblecohort.init', $args, true, $jsmodule);
    unset($visibleselected);
}

// Icon picker.
$PAGE->requires->string_for_js('chooseicon', 'totara_program');
$iconjsmodule = array(
                'name' => 'totara_iconpicker',
                'fullpath' => '/totara/core/js/icon.picker.js',
                'requires' => array('json'));
$currenticon = isset($course->icon) ? $course->icon : 'default';
$iconargs = array('args' => '{"selected_icon":"' . $currenticon . '","type":"course"}');
$PAGE->requires->js_init_call('M.totara_iconpicker.init', $iconargs, false, $iconjsmodule);

// Prepare course and the editor
$editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes'=>$CFG->maxbytes, 'trusttext'=>false, 'noclean'=>true);
$overviewfilesoptions = course_overviewfiles_options($course);
if (!empty($course)) {
    //add context for editor
    $editoroptions['context'] = $coursecontext;
    $course = file_prepare_standard_editor($course, 'summary', $editoroptions, $coursecontext, 'course', 'summary', 0);
    if ($overviewfilesoptions) {
        file_prepare_standard_filemanager($course, 'overviewfiles', $overviewfilesoptions, $coursecontext, 'course', 'overviewfiles', 0);
    }

    // Inject current aliases
    $aliases = $DB->get_records('role_names', array('contextid'=>$coursecontext->id));
    foreach($aliases as $alias) {
        $course->{'role_'.$alias->roleid} = $alias->name;
    }

} else {
    //editor should respect category context if course context is not set.
    $editoroptions['context'] = $catcontext;
    $course = file_prepare_standard_editor($course, 'summary', $editoroptions, null, 'course', 'summary', null);
    if ($overviewfilesoptions) {
        file_prepare_standard_filemanager($course, 'overviewfiles', $overviewfilesoptions, null, 'course', 'overviewfiles', 0);
    }
}

// first create the form
$editform = new course_edit_form(NULL, array('course'=>$course, 'category'=>$category, 'editoroptions'=>$editoroptions, 'returnto'=>$returnto));
if ($editform->is_cancelled()) {
        switch ($returnto) {
            case 'category':
                $url = new moodle_url($CFG->wwwroot.'/course/index.php', array('categoryid' => $categoryid));
                break;
            case 'catmanage':
                $url = new moodle_url($CFG->wwwroot.'/course/manage.php', array('categoryid' => $categoryid));
                break;
            case 'topcatmanage':
                $url = new moodle_url($CFG->wwwroot.'/course/manage.php');
                break;
            case 'topcat':
                $url = new moodle_url($CFG->wwwroot.'/course/');
                break;
            default:
                if (!empty($course->id)) {
                    $url = new moodle_url($CFG->wwwroot.'/course/view.php', array('id'=>$course->id));
                } else {
                    $url = new moodle_url($CFG->wwwroot.'/course/');
                }
                break;
        }
        redirect($url);

} else if ($data = $editform->get_data()) {
    // process data if submitted

    if (empty($course->id)) {
        // In creating the course
        $course = create_course($data, $editoroptions);
        if ($usetags) {
            if (isset($data->otags)) {
                tag_set('course', $course->id, tag_get_name($data->otags));
            } else {
                tag_set('course', $course->id, array());
            }
        }

        $data->id = $course->id;
        customfield_save_data($data, 'course', 'course');

        // Get the context of the newly created course
        $context = context_course::instance($course->id, MUST_EXIST);

        if (!empty($CFG->creatornewroleid) and !is_viewing($context, NULL, 'moodle/role:assign') and !is_enrolled($context, NULL, 'moodle/role:assign')) {
            // deal with course creators - enrol them internally with default role
            enrol_try_internal_enrol($course->id, $USER->id, $CFG->creatornewroleid);

        }
        if (!is_enrolled($context)) {
            // Redirect to manual enrolment page if possible
            $instances = enrol_get_instances($course->id, true);
            foreach($instances as $instance) {
                if ($plugin = enrol_get_plugin($instance->enrol)) {
                    if ($plugin->get_manual_enrol_link($instance)) {
                        // we know that the ajax enrol UI will have an option to enrol
                        $url = new moodle_url('/enrol/users.php', array('id'=>$course->id));
                    }
                }
            }
        }
    } else {
        // Save any changes to the files used in the editor
        update_course($data, $editoroptions);
        if ($usetags) {
            if (isset($data->otags)) {
                tag_set('course', $course->id, tag_get_name($data->otags));
            } else {
                tag_set('course', $course->id, array());
            }
        }
        customfield_save_data($data, 'course', 'course');
    }

    ///
    /// Update course cohorts if user has permissions
    ///
    if (has_capability('moodle/cohort:manage', context_system::instance())) {
        // Enrolled audiences.
        $currentcohorts = totara_cohort_get_course_cohorts($course->id, null, 'c.id, e.id AS associd');
        $currentcohorts = !empty($currentcohorts) ? $currentcohorts : array();
        $newcohorts = !empty($data->cohortsenrolled) ? explode(',', $data->cohortsenrolled) : array();

        if ($todelete = array_diff(array_keys($currentcohorts), $newcohorts)) {
            // Delete removed cohorts
            foreach ($todelete as $cohortid) {
                totara_cohort_delete_association($cohortid, $currentcohorts[$cohortid]->associd, COHORT_ASSN_ITEMTYPE_COURSE);
            }
        }

        if ($newcohorts = array_diff($newcohorts, array_keys($currentcohorts))) {
            // Add new cohort associations
            foreach ($newcohorts as $cohortid) {
                totara_cohort_add_association($cohortid, $course->id, COHORT_ASSN_ITEMTYPE_COURSE);
            }
        }

        // Visible audiences.
        if (!empty($CFG->audiencevisibility) && has_capability('totara/coursecatalog:manageaudiencevisibility', context_system::instance())) {
            $visiblecohorts = totara_cohort_get_visible_learning($course->id);
            $visiblecohorts = !empty($visiblecohorts) ? $visiblecohorts : array();
            $newvisible = !empty($data->cohortsvisible) ? explode(',', $data->cohortsvisible) : array();
            if ($todelete = array_diff(array_keys($visiblecohorts), $newvisible)) {
                // Delete removed cohorts.
                foreach ($todelete as $cohortid) {
                    totara_cohort_delete_association($cohortid, $visiblecohorts[$cohortid]->associd,
                                                    COHORT_ASSN_ITEMTYPE_COURSE, COHORT_ASSN_VALUE_VISIBLE);
                }
            }

            if ($newvisible = array_diff($newvisible, array_keys($visiblecohorts))) {
                // Add new cohort associations.
                foreach ($newvisible as $cohortid) {
                    totara_cohort_add_association($cohortid, $course->id, COHORT_ASSN_ITEMTYPE_COURSE, COHORT_ASSN_VALUE_VISIBLE);
                }
            }
         }
        cache_helper::purge_by_event('changesincourse');
    }

    // Redirect user to newly created/updated course.
    redirect(new moodle_url('/course/view.php', array('id' => $course->id)));
}


// Print the form

$site = get_site();

$streditcoursesettings = get_string("editcoursesettings");
$straddnewcourse = get_string("addnewcourse");
$stradministration = get_string("administration");
$strcategories = get_string("categories");

if (!empty($course->id)) {
    $PAGE->navbar->add($streditcoursesettings);
    $title = $streditcoursesettings;
    $fullname = $course->fullname;
} else {
    $PAGE->navbar->add($stradministration, new moodle_url('/admin/index.php'));
    $PAGE->navbar->add($strcategories, new moodle_url('/course/index.php'));
    $PAGE->navbar->add($straddnewcourse);
    $title = "$site->shortname: $straddnewcourse";
    $fullname = $site->fullname;
}

$PAGE->set_title($title);
$PAGE->set_heading($fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading($streditcoursesettings);

$editform->display();

echo $OUTPUT->footer();
