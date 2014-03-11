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
 * Main administration script.
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


// Check that config.php exists, if not then call the install script
if (!file_exists('../config.php')) {
    header('Location: ../install.php');
    die();
}

// Check that PHP is of a sufficient version as soon as possible
if (version_compare(phpversion(), '5.3.3') < 0) {
    $phpversion = phpversion();
    // do NOT localise - lang strings would not work here and we CAN NOT move it to later place
    echo "Totara 2.2 or later requires at least PHP 5.3.3 (currently using version $phpversion).<br />";
    echo "Please upgrade your server software or install an older Totara version.";
    die();
}

// make sure iconv is available and actually works
if (!function_exists('iconv')) {
    // this should not happen, this must be very borked install
    echo 'Totara requires the iconv PHP extension. Please install or enable the iconv extension.';
    die();
}

define('NO_OUTPUT_BUFFERING', true);

if ((isset($_GET['cache']) and $_GET['cache'] === '0')
        or (isset($_POST['cache']) and $_POST['cache'] === '0')
        or (!isset($_POST['cache']) and !isset($_GET['cache']) and empty($_GET['sesskey']) and empty($_POST['sesskey']))) {
    // Prevent caching at all cost when visiting this page directly,
    // we redirect to self once we known no upgrades are necessary.
    // Note: $_GET and $_POST are used here intentionally because our param cleaning is not loaded yet.
    // Note2: the sesskey is present in all block editing hacks, we can not redirect there, so enable caching.
    define('CACHE_DISABLE_ALL', true);
}

require('../config.php');
require_once($CFG->libdir . '/adminlib.php');    // various admin-only functions
require_once($CFG->libdir . '/upgradelib.php');  // general upgrade/install related functions
require_once($CFG->libdir . '/pluginlib.php');   // available updates notifications
require_once($CFG->dirroot . '/version.php');
require_once($CFG->dirroot . '/totara/core/db/utils.php');

$id             = optional_param('id', '', PARAM_TEXT);
$confirmupgrade = optional_param('confirmupgrade', 0, PARAM_BOOL);
$confirmrelease = optional_param('confirmrelease', 0, PARAM_BOOL);
$confirmplugins = optional_param('confirmplugincheck', 0, PARAM_BOOL);
$showallplugins = optional_param('showallplugins', 0, PARAM_BOOL);
$agreelicense   = optional_param('agreelicense', 0, PARAM_BOOL);
$geterrors = optional_param('geterrors', 0, PARAM_BOOL);
$fetchupdates   = optional_param('fetchupdates', 0, PARAM_BOOL);
$newaddonreq    = optional_param('installaddonrequest', null, PARAM_RAW);
$cache          = optional_param('cache', 0, PARAM_BOOL);

// Set up PAGE.
$url = new moodle_url('/admin/index.php');
$url->param('cache', $cache);
$PAGE->set_url($url);
unset($url);

// Are we returning from an add-on installation request at moodle.org/plugins?
if ($newaddonreq and !$cache and empty($CFG->disableonclickaddoninstall)) {
    $target = new moodle_url('/admin/tool/installaddon/index.php', array(
        'installaddonrequest' => $newaddonreq,
        'confirm' => 0));
    if (!isloggedin() or isguestuser()) {
        // Login and go the the add-on tool page.
        $SESSION->wantsurl = $target->out();
        redirect(get_login_url());
    }
    redirect($target);
}

$PAGE->set_pagelayout('admin'); // Set a default pagelayout

$documentationlink = '<a href="http://docs.moodle.org/en/Installation">Installation docs</a>';

// Check some PHP server settings

if (ini_get_bool('session.auto_start')) {
    print_error('phpvaroff', 'debug', '', (object)array('name'=>'session.auto_start', 'link'=>$documentationlink));
}

if (ini_get_bool('magic_quotes_runtime')) {
    print_error('phpvaroff', 'debug', '', (object)array('name'=>'magic_quotes_runtime', 'link'=>$documentationlink));
}

if (!ini_get_bool('file_uploads')) {
    print_error('phpvaron', 'debug', '', (object)array('name'=>'file_uploads', 'link'=>$documentationlink));
}

if (is_float_problem()) {
    print_error('phpfloatproblem', 'admin', '', $documentationlink);
}

// Set some necessary variables during set-up to avoid PHP warnings later on this page
if (!isset($CFG->release)) {
    $CFG->release = '';
}
if (!isset($CFG->version)) {
    $CFG->version = '';
}
if (!isset($CFG->branch)) {
    $CFG->branch = '';
}

$version = null;
$release = null;
$branch = null;
require("$CFG->dirroot/version.php");       // defines $version, $release, $branch and $maturity
$CFG->target_release = $release;            // used during installation and upgrades

if (!$version or !$release) {
    print_error('withoutversion', 'debug'); // without version, stop
}

if (!isset($maturity)) {
    // Fallback for now. Should probably be removed in the future.
    $maturity = MATURITY_STABLE;
}

// Totara upgrade version checks - only certain upgrade paths are permitted
// do this early to ensure upgrade hasn't started yet
//
// we also need to prevent attempts to downgrade from Moodle release that
// is later than current totara version (e.g. Moodle 2.3 -> Totara 2.2)
// This is already handled by the core upgrade code as it would detected a
// core downgrade and throw and exception

//setup totara version variables
$a = totara_version_info($version, $release);
if (!empty($a->totaraupgradeerror)){
    print_error($a->totaraupgradeerror, 'totara_core');
}

// Turn off xmlstrictheaders during upgrade.
$origxmlstrictheaders = !empty($CFG->xmlstrictheaders);
$CFG->xmlstrictheaders = false;

if (!core_tables_exist()) {
    $PAGE->set_pagelayout('maintenance');
    $PAGE->set_popup_notification_allowed(false);

    // fake some settings
    $CFG->docroot = 'http://docs.moodle.org';

    $strinstallation = get_string('installation', 'install');

    // remove current session content completely
    session_get_instance()->terminate_current();

    if (empty($agreelicense)) {
        $strlicense = get_string('license');

        $PAGE->navbar->add($strlicense);
        $PAGE->set_title($strinstallation.' - Totara '.$TOTARA->release);
        $PAGE->set_heading($strinstallation);
        $PAGE->set_cacheable(false);

        $output = $PAGE->get_renderer('core', 'admin');
        echo $output->install_licence_page();
        die();
    }
    if (empty($confirmrelease)) {
        require_once($CFG->libdir.'/environmentlib.php');
        list($envstatus, $environment_results) = check_moodle_environment(normalize_version($release), ENV_SELECT_RELEASE);
        $strcurrentrelease = get_string('currentrelease');

        $PAGE->navbar->add($strcurrentrelease);
        $PAGE->set_title($strinstallation);
        $PAGE->set_heading($strinstallation . ' - Totara ' . $TOTARA->release);
        $PAGE->set_cacheable(false);

        $output = $PAGE->get_renderer('core', 'admin');
        echo $output->install_environment_page($maturity, $envstatus, $environment_results, $TOTARA->release);
        die();
    }

    // check plugin dependencies
    $failed = array();
    if (!plugin_manager::instance()->all_plugins_ok($version, $failed)) {
        $PAGE->navbar->add(get_string('pluginscheck', 'admin'));
        $PAGE->set_title($strinstallation);
        $PAGE->set_heading($strinstallation . ' - Moodle ' . $CFG->target_release);

        $output = $PAGE->get_renderer('core', 'admin');
        $url = new moodle_url('/admin/index.php', array('agreelicense' => 1, 'confirmrelease' => 1, 'lang' => $CFG->lang));
        echo $output->unsatisfied_dependencies_page($version, $failed, $url);
        die();
    }
    unset($failed);

    //TODO: add a page with list of non-standard plugins here

    $strdatabasesetup = get_string('databasesetup');
    upgrade_init_javascript();

    $PAGE->navbar->add($strdatabasesetup);
    $PAGE->set_title($strinstallation.' - Totara '.$TOTARA->release);
    $PAGE->set_heading($strinstallation);
    $PAGE->set_cacheable(false);

    $output = $PAGE->get_renderer('core', 'admin');
    echo $output->header();

    if (!$DB->setup_is_unicodedb()) {
        if (!$DB->change_db_encoding()) {
            // If could not convert successfully, throw error, and prevent installation
            print_error('unicoderequired', 'admin');
        }
    }

    install_core($version, true);
}

//force autoupdates off
if (empty($CFG->disableupdatenotifications)) {
    set_config('disableupdatenotifications', '1');
    set_config('disableupdateautodeploy', '1');
    set_config('updateminmaturity', MATURITY_STABLE);
    set_config('updatenotifybuilds', 0);
}
// Check version of Moodle code on disk compared with database
// and upgrade if possible.

$stradministration = get_string('administration');
$PAGE->set_context(context_system::instance());

if (empty($CFG->version)) {
    print_error('missingconfigversion', 'debug');
}

// Detect config cache inconsistency, this happens when you switch branches on dev servers.
if ($cache) {
    if ($CFG->version != $DB->get_field('config', 'value', array('name'=>'version'))) {
        purge_all_caches();
        redirect(new moodle_url('/admin/index.php'), 'Config cache inconsistency detected, resetting caches...');
    }
}

if ($version > $CFG->version
        || (isset($CFG->totara_build) && version_compare($a->newtotaraversion, $a->existingtotaraversion, '>'))) {  // upgrade

    // Warning about upgrading a test site.
    $testsite = false;
    if (defined('BEHAT_SITE_RUNNING')) {
        $testsite = 'behat';
    }

    // We purge all of MUC's caches here.
    // Caches are disabled for upgrade by CACHE_DISABLE_ALL so we must set the first arg to true.
    // This ensures a real config object is loaded and the stores will be purged.
    // This is the only way we can purge custom caches such as memcache or APC.
    // Note: all other calls to caches will still used the disabled API.
    cache_helper::purge_all(true);
    // We then purge the regular caches.
    purge_all_caches();

    totara_preupgrade($a);

    $PAGE->set_pagelayout('maintenance');
    $PAGE->set_popup_notification_allowed(false);

    /** @var core_admin_renderer $output */
    $output = $PAGE->get_renderer('core', 'admin');

    if (upgrade_stale_php_files_present()) {
        $PAGE->set_title($stradministration);
        $PAGE->set_cacheable(false);

        echo $output->upgrade_stale_php_files_page();
        die();
    }

    if (empty($confirmupgrade)) {
        $strdatabasechecking = get_string('databasechecking', '', $a);

        $PAGE->set_title($stradministration);
        $PAGE->set_heading($strdatabasechecking);
        $PAGE->set_cacheable(false);

        echo $output->upgrade_confirm_page(get_string('cliupgradesure', 'totara_core', $a), $maturity, $testsite);
        die();

    } else if (empty($confirmrelease)){
        require_once($CFG->libdir.'/environmentlib.php');
        list($envstatus, $environment_results) = check_moodle_environment($release, ENV_SELECT_RELEASE);
        $strcurrentrelease = get_string('currentrelease');

        $PAGE->navbar->add($strcurrentrelease);
        $PAGE->set_title($strcurrentrelease);
        $PAGE->set_heading($strcurrentrelease);
        $PAGE->set_cacheable(false);

        echo $output->upgrade_environment_page($TOTARA->release, $envstatus, $environment_results);
        die();

    } else if (empty($confirmplugins)) {
        $strplugincheck = get_string('plugincheck');

        $PAGE->navbar->add($strplugincheck);
        $PAGE->set_title($strplugincheck);
        $PAGE->set_heading($strplugincheck);
        $PAGE->set_cacheable(false);

        $reloadurl = new moodle_url('/admin/index.php', array('confirmupgrade' => 1, 'confirmrelease' => 1));

        if ($fetchupdates) {
            $updateschecker = available_update_checker::instance();
            if ($updateschecker->enabled()) {
                // No sesskey support guaranteed here, because sessions might not work yet.
                $updateschecker->fetch();
            }
            redirect($reloadurl);
        }

        $deployer = available_update_deployer::instance();
        if ($deployer->enabled()) {
            $deployer->initialize($reloadurl, $reloadurl);

            $deploydata = $deployer->submitted_data();
            if (!empty($deploydata)) {
                // No sesskey support guaranteed here, because sessions might not work yet.
                echo $output->upgrade_plugin_confirm_deploy_page($deployer, $deploydata);
                die();
            }
        }

        echo $output->upgrade_plugin_check_page(plugin_manager::instance(), available_update_checker::instance(),
                $version, $showallplugins, $reloadurl,
                new moodle_url('/admin/index.php', array('confirmupgrade'=>1, 'confirmrelease'=>1, 'confirmplugincheck'=>1)));
        die();

    } else {
        // Always verify plugin dependencies!
        $failed = array();
        if (!plugin_manager::instance()->all_plugins_ok($version, $failed)) {
            $PAGE->set_pagelayout('maintenance');
            $PAGE->set_popup_notification_allowed(false);
            $reloadurl = new moodle_url('/admin/index.php', array('confirmupgrade' => 1, 'confirmrelease' => 1, 'cache' => 0));
            echo $output->unsatisfied_dependencies_page($version, $failed, $reloadurl);
            die();
        }
        unset($failed);

        // Launch main upgrade.
        upgrade_core($version, true);
    }
} else if ($version < $CFG->version) {
    // better stop here, we can not continue with plugin upgrades or anything else
    throw new moodle_exception('downgradedcore', 'error', new moodle_url('/admin/'));
}

// Updated human-readable release version if necessary
if ($release <> $CFG->release) {  // Update the release version
    set_config('release', $release);
}

if ( (!isset($CFG->totara_release) || $CFG->totara_release <> $TOTARA->release)
    || (!isset($CFG->totara_build) || $CFG->totara_build <> $TOTARA->build)
    || (!isset($CFG->totara_version) || $CFG->totara_version <> $TOTARA->version)) {
    // Also set Totara release (human readable version)
    set_config("totara_release", $TOTARA->release);
    set_config("totara_build", $TOTARA->build);
    set_config("totara_version", $TOTARA->version);
}
if ($branch <> $CFG->branch) {  // Update the branch
    set_config('branch', $branch);
}

if (moodle_needs_upgrading()) {
    if (!$PAGE->headerprinted) {
        // means core upgrade or installation was not already done

        /** @var core_admin_renderer $output */
        $output = $PAGE->get_renderer('core', 'admin');

        if (!$confirmplugins) {
            $strplugincheck = get_string('plugincheck');

            $PAGE->set_pagelayout('maintenance');
            $PAGE->set_popup_notification_allowed(false);
            $PAGE->navbar->add($strplugincheck);
            $PAGE->set_title($strplugincheck);
            $PAGE->set_heading($strplugincheck);
            $PAGE->set_cacheable(false);

            if ($fetchupdates) {
                $updateschecker = available_update_checker::instance();
                if ($updateschecker->enabled()) {
                    require_sesskey();
                    $updateschecker->fetch();
                }
                redirect($reloadurl);
            }

            $deployer = available_update_deployer::instance();
            if ($deployer->enabled()) {
                $deployer->initialize($PAGE->url, $PAGE->url);

                $deploydata = $deployer->submitted_data();
                if (!empty($deploydata)) {
                    require_sesskey();
                    echo $output->upgrade_plugin_confirm_deploy_page($deployer, $deploydata);
                    die();
                }
            }

            // Show plugins info.
            echo $output->upgrade_plugin_check_page(plugin_manager::instance(), available_update_checker::instance(),
                    $version, $showallplugins,
                    new moodle_url($PAGE->url),
                    new moodle_url('/admin/index.php', array('confirmplugincheck'=>1)));
            die();
        }

        // Always verify plugin dependencies!
        $failed = array();
        if (!plugin_manager::instance()->all_plugins_ok($version, $failed)) {
            $PAGE->set_pagelayout('maintenance');
            $PAGE->set_popup_notification_allowed(false);
            $reloadurl = new moodle_url('/admin/index.php', array('cache' => 0));
            echo $output->unsatisfied_dependencies_page($version, $failed, $reloadurl);
            die();
        }
        unset($failed);
    }
    // install/upgrade all plugins and other parts
    upgrade_noncore(true);
}

// If this is the first install, indicate that this site is fully configured
// except the admin password
if (during_initial_install()) {
    set_config('rolesactive', 1); // after this, during_initial_install will return false.
    set_config('adminsetuppending', 1);
    // we need this redirect to setup proper session
    upgrade_finished("index.php?sessionstarted=1&amp;lang=$CFG->lang");
}

// make sure admin user is created - this is the last step because we need
// session to be working properly in order to edit admin account
 if (!empty($CFG->adminsetuppending)) {
    $sessionstarted = optional_param('sessionstarted', 0, PARAM_BOOL);
    if (!$sessionstarted) {
        redirect("index.php?sessionstarted=1&lang=$CFG->lang");
    } else {
        $sessionverify = optional_param('sessionverify', 0, PARAM_BOOL);
        if (!$sessionverify) {
            $SESSION->sessionverify = 1;
            redirect("index.php?sessionstarted=1&sessionverify=1&lang=$CFG->lang");
        } else {
            if (empty($SESSION->sessionverify)) {
                print_error('installsessionerror', 'admin', "index.php?sessionstarted=1&lang=$CFG->lang");
            }
            unset($SESSION->sessionverify);
        }
    }

    // Cleanup SESSION to make sure other code does not complain in the future.
    unset($SESSION->has_timed_out);
    unset($SESSION->wantsurl);

    // at this stage there can be only one admin unless more were added by install - users may change username, so do not rely on that
    $adminids = explode(',', $CFG->siteadmins);
    $adminuser = get_complete_user_data('id', reset($adminids));

    if ($adminuser->password === 'adminsetuppending') {
        // prevent installation hijacking
        if ($adminuser->lastip !== getremoteaddr()) {
            print_error('installhijacked', 'admin');
        }
        // login user and let him set password and admin details
        $adminuser->newadminuser = 1;
        complete_user_login($adminuser);
        redirect("$CFG->wwwroot/user/editadvanced.php?id=$adminuser->id"); // Edit thyself

    } else {
        unset_config('adminsetuppending');
    }

} else {
    // just make sure upgrade logging is properly terminated
    upgrade_finished('upgradesettings.php');
}

if (has_capability('moodle/site:config', context_system::instance())) {
    if ($fetchupdates) {
        $updateschecker = available_update_checker::instance();
        if ($updateschecker->enabled()) {
            require_sesskey();
            $updateschecker->fetch();
        }
        redirect(new moodle_url('/admin/index.php', array('cache' => 0)));
    }
}

// Now we can be sure everything was upgraded and caches work fine,
// redirect if necessary to make sure caching is enabled.
if (!$cache and !optional_param('sesskey', '', PARAM_RAW)) {
    redirect(new moodle_url($PAGE->url, array('cache' => 1)));
}

// Check for valid admin user - no guest autologin
require_login(0, false);
if (isguestuser()) {
    // Login as real user!
    $SESSION->wantsurl = (string)new moodle_url('/admin/index.php');
    redirect(get_login_url());
}
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// check that site is properly customized
$site = get_site();
if (empty($site->shortname)) {
    // probably new installation - lets return to frontpage after this step
    // remove settings that we want uninitialised
    unset_config('registerauth');
    redirect('upgradesettings.php?return=site');
}

// setup critical warnings before printing admin tree block
$insecuredataroot = is_dataroot_insecure(true);
$SESSION->admin_critical_warning = ($insecuredataroot==INSECURE_DATAROOT_ERROR);

$adminroot = admin_get_root();

// Check if there are any new admin settings which have still yet to be set
if (any_new_admin_settings($adminroot)){
    redirect('upgradesettings.php');
}

// Everything should now be set up, and the user is an admin
// Check to see if we are downloading latest errors
if ($geterrors) {
    totara_errors_download();
    die();
}
// Print default admin page with notifications.
$errorsdisplayed = defined('WARN_DISPLAY_ERRORS_ENABLED');

$lastcron = $DB->get_field_sql('SELECT MAX(lastcron) FROM {modules}');
$cronoverdue = ($lastcron < time() - 3600 * 24);
$dbproblems = $DB->diagnose();
$maintenancemode = !empty($CFG->maintenance_enabled);

// Available updates for Moodle core
$updateschecker = available_update_checker::instance();
$availableupdates = array();
$availableupdates['core'] = $updateschecker->get_update_info('core',
    array('minmaturity' => $CFG->updateminmaturity, 'notifybuilds' => $CFG->updatenotifybuilds));

// Available updates for contributed plugins
$pluginman = plugin_manager::instance();
foreach ($pluginman->get_plugins() as $plugintype => $plugintypeinstances) {
    foreach ($plugintypeinstances as $pluginname => $plugininfo) {
        if (!empty($plugininfo->availableupdates)) {
            foreach ($plugininfo->availableupdates as $pluginavailableupdate) {
                if ($pluginavailableupdate->version > $plugininfo->versiondisk) {
                    if (!isset($availableupdates[$plugintype.'_'.$pluginname])) {
                        $availableupdates[$plugintype.'_'.$pluginname] = array();
                    }
                    $availableupdates[$plugintype.'_'.$pluginname][] = $pluginavailableupdate;
                }
            }
        }
    }
}

// The timestamp of the most recent check for available updates
$availableupdatesfetch = $updateschecker->get_last_timefetched();

$buggyiconvnomb = (!function_exists('mb_convert_encoding') and @iconv('UTF-8', 'UTF-8//IGNORE', '100'.chr(130).'€') !== '100€');
//check if the site is registered on Moodle.org
$registered = $DB->count_records('registration_hubs', array('huburl' => HUB_MOODLEORGHUBURL, 'confirmed' => 1));

admin_externalpage_setup('adminnotifications');

//get Totara specific info
$oneyearago = time() - 60*60*24*365;
// See MDL-22481 for why currentlogin is used instead of lastlogin
$sql = "SELECT COUNT(id)
          FROM {user}
         WHERE currentlogin > ?";
$activeusers = $DB->count_records_sql($sql, array($oneyearago));
// Check if any errors in log
$errorrecords = $DB->get_records_sql("SELECT id, timeoccured FROM {errorlog} ORDER BY id DESC", null, 0, 1);

$latesterror = array_shift($errorrecords);

require_once($CFG->dirroot . '/totara/core/lib.php');
totara_site_version_tracking();

$output = $PAGE->get_renderer('core', 'admin');
echo $output->admin_notifications_page($maturity, $insecuredataroot, $errorsdisplayed,
        $cronoverdue, $dbproblems, $maintenancemode, $availableupdates, $availableupdatesfetch, $buggyiconvnomb,
        0, $latesterror, $activeusers, $TOTARA->release);

