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
 * contains main Panopto getters
 *
 * @package block_panopto
 * @copyright  Panopto 2009 - 2016 /With contributions from Spenser Jones (sjones@ambrose.edu)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

global $CFG;
if (empty($CFG)) {
    require_once('../../config.php');
}

require_once($CFG->libdir . '/dmllib.php');
require_once('block_panopto_lib.php');
require_once('panopto_auth_soap_client.php');
require_once('panopto_user_soap_client.php');
require_once('panopto_session_soap_client.php');

/**
 * Panopto data object. Contains info required for provisioning a course with Panopto.
 *
 * @package block_panopto
 * @copyright  Panopto 2009 - 2015
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panopto_data {

    /**
     * @var string $instancename course id class is being provisioned for
     */
    public $instancename;

    /**
     * @var int $moodlecourseid current active Moodle course id
     */
    public $moodlecourseid;

    /**
     * @var string $servername
     */
    public $servername;

    /**
     * @var int $applicationkey
     */
    public $applicationkey;

    /**
     * @var object $soapclient instance of the soap client
     */
    public $soapclient;

    /**
     * @var object $sessionmanager instance of the session soap client
     */
    public $sessionmanager;

    /**
     * @var object $usermanager instance of the user soap client
     */
    public $usermanager;

    /**
     * @var object $authmanager instance of the auth soap client
     */
    public $authmanager;

    /**
     * @var int $sessiongroupid is for the current session
     */
    public $sessiongroupid;

    /**
     * @var string $uname username
     */
    public $uname;

    /**
     * @var int PAGE_SIZE size of the pages
     */
    const PAGE_SIZE = 50;

    /**
     * @var int $requireversion Panopto only supports versions of Moodle newer than v2.7(2014051200).
     */
    private static $requiredversion = 2014051200;

    /**
     * main constructor
     *
     * @param int $moodlecourseid course id class is being provisioned for. Can be null for bulk provisioning and manual provisioning.
     */
    public function __construct($moodlecourseid) {
        global $USER;

        // Fetch global settings from DB.
        $this->instancename = get_config('block_panopto', 'instance_name');

        // Get servername and application key specific to Moodle course if ID is specified.
        if (isset($moodlecourseid) && !empty($moodlecourseid)) {
            $this->servername = self::get_panopto_servername($moodlecourseid);
            $this->applicationkey = self::get_panopto_app_key($moodlecourseid);

            $this->moodlecourseid = $moodlecourseid;
            $this->sessiongroupid = self::get_panopto_course_id($moodlecourseid);
        }

        if (isset($USER->username)) {
            $username = $USER->username;
        } else {
            $username = 'guest';
        }
        $this->uname = $username;
    }

    /**
     * Returns SystemInfo.
     */
    public function get_recorder_download_urls() {

        $this->ensure_session_manager();

        return $this->sessionmanager->get_recorder_download_urls();
    }

    /**
     * Returns if the logged in user can provision.
     *
     * @param int $courseid the Moodle id of the course we are checking
     */
    public function can_user_provision($courseid) {
        global $USER;

        // Get the context of the course so we can get capaibilities.
        $context = context_course::instance($courseid, MUST_EXIST);

        return has_capability('block/panopto:provision_aspublisher', $context, $USER->id) ||
            has_capability('block/panopto:provision_asteacher', $context, $USER->id) ||
            has_capability('moodle/course:update', $context, $USER->id);
    }

    /**
     * Return the correct role for a user, given a context.
     *
     * @param int $contextid
     * @param int $userid
     */
    public static function get_role_from_context($context, $userid) {
        $role = 'Viewer';

        $canprovisionaspublisher = has_capability('block/panopto:provision_aspublisher', $context, $userid);
        $canprovisionasteacher = has_capability('block/panopto:provision_asteacher', $context, $userid);

        if ($canprovisionaspublisher) {
            if ($canprovisionasteacher) {
                $role = 'Creator/Publisher';
            } else {
                $role = 'Publisher';
            }
        } else if ($canprovisionasteacher) {
            $role = 'Creator';
        }

        return $role;
    }

    /**
     * Return the session manager, if it does not yet exist try to create it.
     */
    public function ensure_session_manager() {
        // If no session soap client exists instantiate one.
        if (!isset($this->sessionmanager)) {
            $this->sessionmanager = self::instantiate_session_soap_client(
                $this->uname,
                $this->servername,
                $this->applicationkey
            );

            if (!isset($this->sessionmanager)) {
                error_log(get_string('api_manager_unavailable', 'block_panopto', 'session'));
            }
        }
    }

    /**
     * Return the auth manager, if it does not yet exist try to create it.
     */
    public function ensure_auth_manager() {
        // If no session soap client exists instantiate one.
        if (!isset($this->authmanager)) {
            // If no auth soap client for this instance, instantiate one.
            $this->authmanager = self::instantiate_auth_soap_client(
                $this->uname,
                $this->servername,
                $this->applicationkey
            );

            if (!isset($this->authmanager)) {
                error_log(get_string('api_manager_unavailable', 'block_panopto', 'auth'));
            }
        }
    }

    /**
     * Return the user manager, if it does not yet exist try to create it.
     */
    public function ensure_user_manager() {
        // If no session soap client exists instantiate one.
        if (!isset($this->usermanager)) {
            // If no auth soap client for this instance, instantiate one.
            $this->usermanager = self::instantiate_user_soap_client(
                $this->uname,
                $this->servername,
                $this->applicationkey
            );

            if (!isset($this->usermanager)) {
                error_log(get_string('api_manager_unavailable', 'block_panopto', 'user'));
            }
        }
    }

    /**
     * Create the Panopto course folder and populate its ACLs.
     *
     * @param object $provisioninginfo info for course being provisioned
     */
    public function provision_course($provisioninginfo) {
        global $CFG, $USER;

        if (isset($provisioninginfo->fullname) && !empty($provisioninginfo->fullname) &&
            isset($provisioninginfo->externalcourseid) && !empty($provisioninginfo->externalcourseid)) {

            $this->ensure_session_manager();

            if (isset($this->sessiongroupid)) {
                $courseinfo = $this->sessionmanager->set_external_course_access_for_roles(
                    $provisioninginfo->fullname,
                    $provisioninginfo->externalcourseid,
                    $this->sessiongroupid
                );
            } else {
                $courseinfo = $this->sessionmanager->provision_external_course_with_roles(
                    $provisioninginfo->fullname,
                    $provisioninginfo->externalcourseid
                );
            }

            if (isset($courseinfo) && isset($courseinfo->Id)) {
                // Store the Panopto folder Id in the foldermap table so we know it exists.
                self::set_course_foldermap(
                    $this->moodlecourseid,
                    $courseinfo->Id,
                    $this->servername,
                    $this->applicationkey,
                    $provisioninginfo->externalcourseid
                );

                // Sync access of the privisioning user so they don't need to relog to view the folder.
                self::sync_external_user($USER->id);

                $this->ensure_auth_manager();

                // If we succeeded in provisioning lets send the Panopto server some updated integration information.
                $this->authmanager->report_integration_info(
                    get_config('block_panopto', 'instance_name'),
                    get_config('block_panopto', 'current_version'),
                    $CFG->version
                );

            } else {
                // Give the user some basic info they can use to debug or send to AE.
                $courseinfo = new stdClass;
                $courseinfo->moodlecourseid = $this->moodlecourseid;
                $courseinfo->servername = $this->servername;
                $courseinfo->applicationkey = $this->applicationkey;
                $courseinfo->missingrequiredversion = true;
            }
        } else {
            // Give the user some basic info they can use to debug or send to AE.
            $courseinfo = new stdClass;
            $courseinfo->moodlecourseid = $this->moodlecourseid;
            $courseinfo->servername = $this->servername;

            if (isset($provisioninginfo->accesserror) && $provisioninginfo->accesserror === true) {
                $courseinfo->accesserror = true;
            } else {
                error_log(get_string('unknown_provisioning_error', 'block_panopto'));
                $courseinfo->unknownerror = true;
            }
        }

        return $courseinfo;
    }

    /**
     *  Fetch course name and membership info from DB in preparation for provisioning operation.
     *
     */
    public function get_provisioning_info() {
        global $DB;

        // If old role mappings exists, do not remap. Otherwise, set role mappings to defaults.
        $mappings = self::get_course_role_mappings($this->moodlecourseid);
        if (empty($mappings['creator'][0]) && empty($mappings['publisher'][0])) {

            // These settings are returned as a comma seperated string of role Id's.
            $defaultpublishermapping = explode("," , get_config('block_panopto', 'publisher_role_mapping'));
            $defaultcreatormapping = explode("," , get_config('block_panopto', 'creator_role_mapping'));

            // Set the role mappings for the course to the defaults.
            self::set_course_role_mappings(
                $this->moodlecourseid,
                $defaultpublishermapping,
                $defaultcreatormapping
            );

            // Grant course users the proper Panopto permissions based on the default role mappings.
            // This will make the role mappings be recognized when provisioning.
            self::set_course_role_permissions(
                $this->moodlecourseid,
                $defaultpublishermapping,
                $defaultcreatormapping
            );
        }

        $provisioninginfo = new stdClass;

        // If we are provisioning a course with a panopto_id set we should provision that folder.
        // We need to keep this because users can map to folders that weren't created in Moodle so Moodle has no knowledge of the externalcourseid.
        $hasvalidpanoptoid = isset($this->sessiongroupid) && !empty($this->sessiongroupid);

        if ($hasvalidpanoptoid) {
            $mappedpanoptocourse = $this->get_folders_by_id();
        }

        // The get_folders_by_id api wrapper returns -1 if the api returns a folder not found error.
        $foundmappedfolder = isset($mappedpanoptocourse) && ($mappedpanoptocourse !== -1);

        // The get_folders_by_id api wrapper returns false if the api call got a user has no access error.
        $userhasaccesstofolder = $foundmappedfolder && ($mappedpanoptocourse !== false);

        if ($userhasaccesstofolder) {
            $provisioninginfo->sessiongroupid = $this->sessiongroupid;
            $provisioninginfo->fullname = $mappedpanoptocourse->Name;
        } else if ($foundmappedfolder && !$userhasaccesstofolder) {
            // API call returned false, course exists but the user does not have access to the folder.
            error_log(get_string('provisioning_access_error', 'block_panopto'));
            $provisioninginfo->accesserror = true;
            return $provisioninginfo;
        } else {
            if ($hasvalidpanoptoid && !$foundmappedfolder) {
                // If we had a sessiongroupid set from a previous folder, but that folder was not found on Panopto.
                // Set the current sessiongroupid to null to allow for a fresh provisioning/folder.
                // Provisioning will fail if this is not done, the wrong API endpoint will be called.
                error_log(get_string('folder_not_found_error', 'block_panopto'));
                $this->sessiongroupid = null;
                $provisioninginfo->couldnotfindmappedfolder = true;
            }

            $provisioninginfo->shortname = $DB->get_field(
                'course',
                'shortname',
                array('id' => $this->moodlecourseid)
            );

            $provisioninginfo->longname = $DB->get_field(
                'course',
                'fullname',
                array('id' => $this->moodlecourseid)
            );

            if (!isset($provisioninginfo->shortname) || empty($provisioninginfo->shortname)) {
                $provisioninginfo->shortname = substr($provisioninginfo->longname, 0, 5);
            }

            $provisioninginfo->fullname = '';
            if (get_config('block_panopto', 'prefix_new_folder_names')) {
                $provisioninginfo->fullname .= $provisioninginfo->shortname . ': ';
            }
            $provisioninginfo->fullname .= $provisioninginfo->longname;
        }

        // Always set this, even in the case of an already existing folder we will overwrite the old Id with this one.
        $provisioninginfo->externalcourseid = $this->instancename . ':' . $this->moodlecourseid;

        return $provisioninginfo;
    }

    /**
     * Initializes and syncs a possible new import
     *
     * @param int $newimportid the id of the course being imported
     *
     */
    public function init_and_sync_import($newimportid) {
        $importinfo = array();
        $currentimportsources = self::get_import_list($this->moodlecourseid);
        $possibleimportsources = array_merge(
            array($newimportid),
            self::get_import_list($newimportid)
        );

        $this->ensure_session_manager();

        foreach ($possibleimportsources as $possiblenewimportsource) {
            $importinarray = in_array($possiblenewimportsource, $currentimportsources);

            if (!$importinarray) {
                // If a course is already listed as an import we don't need to add it to the import array, but we can still resync the groups.
                $currentimportsources[] = $possiblenewimportsource;
                self::add_new_course_import($this->moodlecourseid, $possiblenewimportsource);
            }

            $importpanopto = new panopto_data($newimportid);
            $provisioninginfo = $this->get_provisioning_info();

            if (!isset($importpanopto->sessiongroupid)) {
                error_log(get_string('import_not_mapped', 'block_panopto'));
            } else if (!isset($provisioninginfo->accesserror)) {
                // Only do this code if we have proper access to the target Panopto course folder.
                $importresult = $this->sessionmanager->set_copied_external_course_access_for_roles(
                    $provisioninginfo->fullname,
                    $provisioninginfo->externalcourseid,
                    $importpanopto->sessiongroupid
                );
                if (isset($importresult)) {
                    $importinfo[] = $importresult;
                } else {
                    error_log(get_string('missing_required_version', 'block_panopto'));
                    return false;
                }
            }
        }

        return $importinfo;
    }

    public function get_folders_by_external_id() {
        $ret = false;

        if (isset($this->sessiongroupid)) {
            $this->ensure_session_manager();

            $provisioninginfo = $this->get_provisioning_info();
            $ret = $this->sessionmanager->get_folders_by_external_id($provisioninginfo->externalcourseid);
        }

        return $ret;
    }

    public function get_folders_by_id() {
        $ret = false;

        if (isset($this->sessiongroupid)) {
            $this->ensure_session_manager();

            $ret = $this->sessionmanager->get_folders_by_id($this->sessiongroupid);

        } else {
            // In this case the course is not mapped and the folder does not exist.
            // I think -1 is fitting here. This case is handled differenty than false in our upgrade script.
            $ret = null;
        }

        return $ret;
    }

    public function get_folders_list() {
        $ret = false;

        if (isset($this->sessiongroupid)) {
            $this->ensure_session_manager();

            $ret = $this->sessionmanager->get_folders_list();
        }

        return $ret;
    }

    /**
     * Sync a user with all of the courses he is enrolled in
     *
     * @param int $userid the Moodle id of the user we are trying to sync.
     */
    public static function sync_external_user($userid) {
        global $DB, $CFG, $USER;

        if (isset($USER->username)) {
            $username = $USER->username;
            $userisguest = false;
        } else {
            $userisguest = true;
            $username = 'guest';
        }

        $setupasadmin = false;
        $servergroupidlist = array();
        $instancename = get_config('block_panopto', 'instance_name');

        $numservers = get_config('block_panopto', 'server_number');
        $numservers = isset($numservers) ? $numservers : 0;

        // Increment numservers by 1 to take into account starting at 0.
        ++$numservers;

        for ($serverwalker = 1; $serverwalker <= $numservers; ++$serverwalker) {

            // Generate strings corresponding to potential servernames in the config.
            $thisservername = get_config('block_panopto', 'server_name' . $serverwalker);
            $thisappkey = get_config('block_panopto', 'application_key' . $serverwalker);

            $hasservername = !(!isset($thisservername) || trim($thisservername) === '');
            $hasappkey = !(!isset($thisappkey) || trim($thisappkey) === '');

            if ($hasservername && $hasappkey) {
                $panoptodataobj = new panopto_data(null);
                $panoptodataobj->uname = $username;
                $panoptodataobj->servername = $thisservername;
                $panoptodataobj->applicationkey = $thisappkey;

                $servergroupidlist[$panoptodataobj->servername] = array(
                    'panopto' => $panoptodataobj,
                    'externalgroupids' => array()
                );
            }
        }

        if (get_config('block_panopto', 'auto_add_admins') && !$userisguest) {
            // All Moodle administrators should get access to all Panopto course folders as teachers, since they can all provision in the config page.
            // Whitespace after id is required.
            $sql = "SELECT id " .
                "FROM {user} " .
                "WHERE";
            $siteadmins = explode(",", $CFG->siteadmins);
            list($usql, $params) = $DB->get_in_or_equal($siteadmins);
            $sql .= " id $usql ";
            $superadmins = $DB->get_records_sql($sql, $params);
            foreach ($superadmins as $possibleuser) {
                if ($possibleuser->id === $userid) {
                    $setupasadmin = true;
                    break;
                }
            }
        }

        if ($setupasadmin) {
            $coursestosync = $DB->get_records(
                'block_panopto_foldermap',
                null,
                'moodleid'
            );

            $currentcourses = array();
            foreach ($coursestosync as $course) {
                $moodlecourse = $DB->get_record('course', array('id' => $course->moodleid));

                if (isset($moodlecourse) && !empty($moodlecourse) && $moodlecourse !== false) {
                    $courseobj = new stdClass;
                    $courseobj->id = $course->moodleid;
                    $currentcourses[] = $courseobj;
                } else {
                    // The course does not exist in Moodle, move it to the old table for cleanup.
                    error_log(get_string('moodle_course_not_exist', 'block_panopto'));
                    self::delete_panopto_relation($course->moodleid, true);
                }
            }
        } else {
            $currentcourses = enrol_get_users_courses($userid, true);
        }

        // Go through each course.
        foreach ($currentcourses as $course) {
            $coursecontext = context_course::instance($course->id);
            $coursegroups = array();

            $coursepanopto = new panopto_data($course->id);

            // Check to see if we are already going to provision a specific Panopto server, if we are just add the groups to the already made array
            // If not add the server to the list of servers.
            if (isset($coursepanopto->servername) && !empty($coursepanopto->servername) &&
                isset($coursepanopto->applicationkey) && !empty($coursepanopto->applicationkey) &&
                isset($coursepanopto->sessiongroupid) && !empty($coursepanopto->sessiongroupid)) {

                $role = self::get_role_from_context($coursecontext, $userid);

                // If the admin is already a creator or publisher do nothing.
                if ($setupasadmin && $role === 'Viewer') {
                    $role = 'Creator';
                }

                // Build a list of ExternalGroupIds using a specific format.
                // E.g. moodle31:courseId_viewers/moodle31:courseId_creators.
                $groupname = $coursepanopto->instancename . ':' . $course->id;
                if (strpos($role, 'Viewer') !== false) {
                    $coursegroups[] = $groupname . "_viewers";
                }

                if (strpos($role, 'Creator') !== false) {
                    $coursegroups[] = $groupname . "_creators";
                }

                if (strpos($role, 'Publisher') !== false) {
                    $coursegroups[] = $groupname . "_publishers";
                }

                if (!array_key_exists($coursepanopto->servername, $servergroupidlist)) {
                    $servergroupidlist[$coursepanopto->servername] = array(
                        'panopto' => $coursepanopto,
                        'externalgroupids' => array_merge(array(), $coursegroups)
                    );
                } else {
                    $servergroupidlist[$coursepanopto->servername]['externalgroupids'] = array_merge(
                        $servergroupidlist[$coursepanopto->servername]['externalgroupids'],
                        $coursegroups
                    );
                }
            }
        }

        $userinfo = $DB->get_record('user', array('id' => $userid));
        // For each Panopto server that has groups we need to provision.
        foreach ($servergroupidlist as $servername => $servergroup) {

            // Only try to sync the users if he Panopto server is up.
            if (is_server_alive('https://' . $servername . '/Panopto') || is_server_alive('http://' . $servername . '/Panopto')) {

                $servergroup['panopto']->ensure_user_manager();

                $servergroup['panopto']->usermanager->sync_external_user(
                    $userinfo->firstname,
                    $userinfo->lastname,
                    $userinfo->email,
                    $servergroup['externalgroupids']
                );
            } else {
                error_log(get_string('panopto_server_error', 'block_panopto', $servername));
            }
        }

        return;
    }

    /**
     * Create the provisioning information needed to create permissions on Panopto for the new course
     *
     * @param int $courseid the id of the course being updated
     * @param int $newimportid courseid that the target course imports from
     */
    public static function add_new_course_import($courseid, $newimportid) {
        global $DB;
        $rowarray = array('target_moodle_id' => $courseid, 'import_moodle_id' => $newimportid);

        $currentrow = $DB->get_record('block_panopto_importmap', $rowarray);
        if (!$currentrow) {
            $row = (object) $rowarray;
            return $DB->insert_record('block_panopto_importmap', $row);
        }

        return;
    }

    /**
     * Get the courseid's of the courses being imported to this course
     *
     * @param int $courseid
     */
    public static function get_import_list($courseid) {
        global $DB;

        $courseimports = $DB->get_records(
            'block_panopto_importmap',
            array('target_moodle_id' => $courseid),
            null,
            'import_moodle_id'
        );

        $retarray = array();
        if (isset($courseimports) && !empty($courseimports)) {
            foreach ($courseimports as $courseimport) {
                $retarray[] = $courseimport->import_moodle_id;
            }
        }

        return $retarray;
    }

    /**
     * Get the courseid's of the courses importing the given course
     *
     * @param int $courseid
     */
    public static function get_import_target_list($courseid) {
        global $DB;

        $courseimports = $DB->get_records(
            'block_panopto_importmap',
            array('import_moodle_id' => $courseid),
            null,
            'target_moodle_id'
        );

        $retarray = array();
        if (isset($courseimports) && !empty($courseimports)) {
            foreach ($courseimports as $courseimport) {
                $retarray[] = $courseimport->target_moodle_id;
            }
        }

        return $retarray;
    }

    /**
     * Get ongoing Panopto sessions for the currently mapped course.
     */
    public function get_session_list() {
        $sessionlist = array();
        if ($this->servername && $this->applicationkey && $this->sessiongroupid) {
            $this->ensure_session_manager();
        }

        $sessionlist = $this->sessionmanager->get_session_list($this->sessiongroupid);

        return $sessionlist;
    }

    /**
     * Instance method caches Moodle instance name from DB (vs. block_panopto_lib version).
     *
     * @param string $moodleusername name of the Moodle user
     */
    public function panopto_decorate_username($moodleusername) {
        return ($this->instancename . "\\" . $moodleusername);
    }

    /**
     * Lets us know if we have a value inside the config for a Panopto server, we don't want any of our events to fire on an unconfigured block.
     *
     */
    public static function is_main_block_configured() {

        $numservers = get_config('block_panopto', 'server_number');
        $numservers = isset($numservers) ? $numservers : 0;

        // Increment numservers by 1 to take into account starting at 0.
        ++$numservers;

        $isconfigured = false;
        if ($numservers > 0) {
            for ($serverwalker = 1; $serverwalker <= $numservers; ++$serverwalker) {
                $possibleserver = get_config('block_panopto', 'server_name' . $serverwalker);
                $possibleappkey = get_config('block_panopto', 'application_key' . $serverwalker);

                if (isset($possibleserver) && !empty($possibleserver) &&
                    isset($possibleappkey) && !empty($possibleappkey)) {
                    $isconfigured = true;
                    break;
                }
            }
        }

        return $isconfigured;
    }

    /**
     * Lets us know is we are using at least the minumum required version for the Panopto block
     *
     */
    public static function has_minimum_version() {
        global $CFG;

        $hasminversion = true;
        $versionobject = new stdClass;
        $versionobject->requiredversion = self::$requiredversion;
        $versionobject->currentversion = $CFG->version;

        if ($CFG->version < self::$requiredversion) {
            $hasminversion = false;
            error_log(get_string('missing_moodle_required_version', 'block_panopto', $versionobject));
        }

        return $hasminversion;
    }

    /**
     * We need to retrieve the current course mapping in the constructor, so this must be static.
     *
     * @param int $sessiongroupid id of the Panopto folder we are trying to get the Moodle courses associated with.
     */
    public static function get_moodle_course_id($sessiongroupid) {
        global $DB;
        return $DB->get_records(
            'block_panopto_foldermap',
            array('panopto_id' => $sessiongroupid),
            null,
            'moodleid'
        );
    }

    /**
     * We need to retrieve the current course mapping in the constructor, so this must be static.
     *
     * @param int $moodlecourseid id of the current Moodle course
     */
    public static function get_panopto_course_id($moodlecourseid) {
        global $DB;
        return $DB->get_field('block_panopto_foldermap', 'panopto_id', array('moodleid' => $moodlecourseid));
    }

    /**
     *  Retrieve the servername for the current course
     *
     * @param int $moodlecourseid id of the current Moodle course
     */
    public static function get_panopto_servername($moodlecourseid) {
        global $DB;
        return $DB->get_field('block_panopto_foldermap', 'panopto_server', array('moodleid' => $moodlecourseid));
    }

    /**
     *  Retrieve the app key for the current course
     *
     * @param int $moodlecourseid id of the current Moodle course
     */
    public static function get_panopto_app_key($moodlecourseid) {
        global $DB;
        return $DB->get_field('block_panopto_foldermap', 'panopto_app_key', array('moodleid' => $moodlecourseid));
    }

    /**
     * Get the current role mappings set for the current course from the db.
     *
     * @param int $moodlecourseid id of the current Moodle course
     */
    public static function get_course_role_mappings($moodlecourseid) {
        global $DB;

        $pubroles = array();
        $creatorroles = array();

         // Get creator roles as an array.
        $creatorrolesraw = $DB->get_records(
            'block_panopto_creatormap',
            array('moodle_id' => $moodlecourseid),
            'role_id'
        );

        if (isset($creatorrolesraw) && !empty($creatorrolesraw)) {
            foreach ($creatorrolesraw as $creatorrole) {
                $creatorroles[] = $creatorrole->role_id;
            }
        }

         // Get publisher roles as an array.
        $pubrolesraw = $DB->get_records(
            'block_panopto_publishermap',
            array('moodle_id' => $moodlecourseid),
            'role_id'
        );

        if (isset($pubrolesraw) && !empty($pubrolesraw)) {
            foreach ($pubrolesraw as $pubrole) {
                $pubroles[] = $pubrole->role_id;
            }
        }

        return array('publisher' => $pubroles, 'creator' => $creatorroles);
    }

    /**
     *  Set the Panopto ID in the db for the current course
     *  Called by Moodle block instance config save method, so must be static.
     *
     * @param int $moodlecourseid id of the current Moodle course.
     * @param int $sessiongroupid the id of the current session group.
     * @param int $servername name of the server the sessiongroup is located on.
     * @param int $appkey the appkey needed to access the Identity provider on the server.
     */
    public static function set_course_foldermap($moodlecourseid, $sessiongroupid, $servername, $appkey, $externalcourseid) {
        global $DB;
        $row = (object) array(
            'moodleid' => $moodlecourseid,
            'panopto_id' => $sessiongroupid,
            'panopto_server' => $servername,
            'panopto_app_key' => $appkey
        );

        $oldrecord = $DB->get_record('block_panopto_foldermap', array('moodleid' => $moodlecourseid));

        if ($oldrecord) {
            $row->id = $oldrecord->id;
            return $DB->update_record('block_panopto_foldermap', $row);
        } else {
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    /**
     *  Set the Panopto ID in the db for the current course
     *  Called by Moodle block instance config save method, so must be static.
     *
     * @param int $moodlecourseid id of the current Moodle course
     * @param int $sessiongroupid the id of the current session group
     */
    public static function set_panopto_course_id($moodlecourseid, $sessiongroupid) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodlecourseid))) {
            return $DB->set_field(
                'block_panopto_foldermap',
                'panopto_id',
                $sessiongroupid,
                array('moodleid' => $moodlecourseid)
            );
        } else {
            $row = (object) array('moodleid' => $moodlecourseid, 'panopto_id' => $sessiongroupid);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    /**
     * Set the Panopto server name in the db for the current course
     *
     * @param int $moodlecourseid id of the current Moodle course
     * @param string $panoptoservername the name of the Panopto server
     */
    public static function set_panopto_server_name($moodlecourseid, $panoptoservername) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodlecourseid))) {
            return $DB->set_field(
                'block_panopto_foldermap',
                'panopto_server',
                $panoptoservername,
                array('moodleid' => $moodlecourseid)
            );
        } else {
            $row = (object) array('moodleid' => $moodlecourseid, 'panopto_server' => $panoptoservername);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    /**
     * Set the Panopto app key associated with the current course on the db
     *
     * @param int $moodlecourseid id of the current Moodle course
     * @param string $panoptoappkey
     */
    public static function set_panopto_app_key($moodlecourseid, $panoptoappkey) {
        global $DB;
        if ($DB->get_records('block_panopto_foldermap', array('moodleid' => $moodlecourseid))) {
            return $DB->set_field(
                'block_panopto_foldermap',
                'panopto_app_key',
                $panoptoappkey,
                array('moodleid' => $moodlecourseid)
            );
        } else {
            $row = (object) array('moodleid' => $moodlecourseid, 'panopto_app_key' => $panoptoappkey);
            return $DB->insert_record('block_panopto_foldermap', $row);
        }
    }

    /**
     * Set the selected Panopto role mappings for the current course on the db
     *
     * @param int $moodlecourseid id of the current Moodle course
     * @param array $publisherroles a list of publisher roles
     * @param array $creatorroles a list of creator roles
     */
    public static function set_course_role_mappings($moodlecourseid, $publisherroles, $creatorroles) {
        global $DB;

        // Delete all old records to prevent non-existant mapping staying when they shouldn't.
        $DB->delete_records('block_panopto_publishermap', array('moodle_id' => $moodlecourseid));

        foreach ($publisherroles as $pubrole) {
            $row = (object) array('moodle_id' => $moodlecourseid, 'role_id' => $pubrole);
            $DB->insert_record('block_panopto_publishermap', $row);
        }

        // Delete all old records to prevent non-existant mapping staying when they shouldn't.
        $DB->delete_records('block_panopto_creatormap', array('moodle_id' => $moodlecourseid));

        foreach ($creatorroles as $creatorrole) {
            $row = (object) array('moodle_id' => $moodlecourseid, 'role_id' => $creatorrole);
            $DB->insert_record('block_panopto_creatormap', $row);
        }
    }

    /**
     * Delete the Panopto foldermap row, called when a course is deleted.
     * This function is unused but kept in case we decide to reintroduce the cleaning of table rows.
     *
     * @param int $moodlecourseid id of the target Moodle course
     */
    public static function delete_panopto_relation($moodlecourseid, $movetoinactivetable) {
        global $DB;
        $deletedrecords = array();
        $existingrecords = $DB->get_records('block_panopto_foldermap', array('moodleid' => $moodlecourseid));
        if ($existingrecords) {
            if ($movetoinactivetable) {
                $DB->insert_records('block_panopto_old_foldermap', $existingrecords);
            }

            $deletedrecords['foldermap'] = $DB->delete_records(
                'block_panopto_foldermap',
                array('moodleid' => $moodlecourseid)
            );
        }

        if ($DB->get_records('block_panopto_importmap', array('target_moodle_id' => $moodlecourseid))) {
            $deletedrecords['imports'] = $DB->delete_records(
                'block_panopto_importmap',
                array('target_moodle_id' => $moodlecourseid)
            );
        }

        if ($DB->get_records('block_panopto_importmap', array('import_moodle_id' => $moodlecourseid))) {
            $deletedrecords['exports'] = $DB->delete_records(
                'block_panopto_importmap',
                array('import_moodle_id' => $moodlecourseid)
            );
        }

        return $deletedrecords;
    }

    /**
     * Get list of available courses from db based on user's access level on course.
     */
    public function get_course_options() {
        $panoptocourses = $this->get_folders_list();
        if (!empty($panoptocourses)) {
            $options = array();
            foreach ($panoptocourses as $courseinfo) {
                $options[$courseinfo->Id] = $courseinfo->Name;
            }
        } else if (isset($panoptocourses)) {
            $options = array('Error' => array('-- No Courses Available --'));
        } else {
            $options = array('Error' => array('!! Unable to retrieve course list !!'));
        }

        return array('courses' => $options, 'selected' => $this->sessiongroupid);
    }

    /**
     * Used to instantiate a user soap client for a given instance of panopto_data.
     * Should be called only the first time a soap client is needed for an instance.
     *
     * @param string $username the name of the current user
     * @param string $servername the name of the active server
     * @param string $applicationkey the key need for the user to be authenticated
     */
    public static function instantiate_user_soap_client($username, $servername, $applicationkey) {
        // Compute web service credentials for given user.
        $apiuseruserkey = panopto_decorate_username($username);
        $apiuserauthcode = panopto_generate_auth_code($apiuseruserkey . '@' . $servername, $applicationkey);

        // Instantiate our SOAP client.
        return new panopto_user_soap_client($servername, $apiuseruserkey, $apiuserauthcode);
    }

    /**
     * Used to instantiate a session soap client for a given instance of panopto_data.
     *
     * @param string $username the name of the current user
     * @param string $servername the name of the active server
     * @param string $applicationkey the key need for the user to be authenticated
     */
    public static function instantiate_session_soap_client($username, $servername, $applicationkey) {
        // Compute web service credentials for given user.
        $apiuseruserkey = panopto_decorate_username($username);
        $apiuserauthcode = panopto_generate_auth_code($apiuseruserkey . '@' . $servername, $applicationkey);

        // Instantiate our SOAP client.
        return new panopto_session_soap_client($servername, $apiuseruserkey, $apiuserauthcode);
    }

    /**
     * Used to instantiate a soap client for calling Panopto's iAuth service.
     * Should be called only the first time an  auth soap client is needed for an instance.
     */
    public static function instantiate_auth_soap_client($username, $servername, $applicationkey) {
        // Compute web service credentials for given user.
        $apiuseruserkey = panopto_decorate_username($username);
        $apiuserauthcode = panopto_generate_auth_code($apiuseruserkey . '@' . $servername, $applicationkey);

        // Instantiate our SOAP client.
        return new panopto_auth_soap_client($servername, $apiuseruserkey, $apiuserauthcode);
    }

    /**
     * Gives selected capabilities to specified roles given a context.
     *
     * @param int $context the context of the roles being given the capability
     * @param array $roles an array of roles to be given the capability
     * @param string $capability The capability being given to the roles
     */
    public static function add_context_capability_to_roles($context, $roles, $capability) {
        foreach ($roles as $role) {
            if (isset($role) && trim($role) !== '') {
                assign_capability(
                    $capability,
                    CAP_ALLOW,
                    $role,
                    $context,
                    $overwrite = false
                );
            }
        }
    }

    /**
     * Gives selected capabilities to specified roles given a context.
     *
     * @param int $context the context of the roles being affected
     * @param array $roles an array of roles to be unnassigned
     * @param string $capability The capability being removed to the roles
     */
    public static function remove_context_capability_from_roles($context, $roles, $capability) {
        foreach ($roles as $role) {
            unassign_capability($capability, $role->id, $context);
        }
    }

    /**
     * Gives selected capabilities to specified roles.
     *
     * @param int $courseid the id of the course being focused for this operation
     * @param array $publisherroles an array of roles to be made publishers
     * @param array $creatorroles an array of roles to be made creators for the course
     */
    public static function set_course_role_permissions($courseid, $publisherroles, $creatorroles) {
        $coursecontext = context_course::instance($courseid);

        // Clear capabilities from all of course's roles to be reassigned.
        self::clear_capabilities_for_course($courseid);

        self::add_context_capability_to_roles($coursecontext, $publisherroles, 'block/panopto:provision_aspublisher');
        self::add_context_capability_to_roles($coursecontext, $creatorroles, 'block/panopto:provision_asteacher');

        $publishersystemroles = explode(',', get_config('block_panopto', 'publisher_system_role_mapping'));

        // Remove all system level publisher roles and re-add them below to roles that still need them.
        $systemcontext = context_system::instance();
        $systemrolearray = get_all_roles($systemcontext);
        self::remove_context_capability_from_roles($systemcontext, $systemrolearray, 'block/panopto:provision_aspublisher');

        if (count($publishersystemroles)) {
            self::add_context_capability_to_roles($systemcontext, $publishersystemroles, 'block/panopto:provision_aspublisher');

            // Mark dirty (Moodle standard for capability changes at context level).
            $systemcontext->mark_dirty();
        }

        // Mark dirty (Moodle standard for capability changes at context level).
        $coursecontext->mark_dirty();

        self::set_course_role_mappings($courseid, $publisherroles, $creatorroles);
    }

    /**
     * Clears capabilities from all roles so that they may be reassigned as specified.
     *
     * @param int $courseid the id of the course being targets for clearing
     */
    public static function clear_capabilities_for_course($courseid) {
        $coursecontext = context_course::instance($courseid);

        // Get all roles for current course.
        $currentcourseroles = get_all_roles($coursecontext);

        // Remove publisher and creator capabilities from all roles.
        foreach ($currentcourseroles as $role) {
            unassign_capability('block/panopto:provision_aspublisher', $role->id, $coursecontext);
            unassign_capability('block/panopto:provision_asteacher', $role->id, $coursecontext);

            // Mark dirty (Moodle standard for capability changes at context level).
            $coursecontext->mark_dirty();
        }
    }
}

function is_server_alive($url = null) {
    if ($url == null) {
        return false;
    }
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $data = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if (($httpcode >= 200 && $httpcode < 300) || $httpcode == 302) {
        return true;
    } else {
        return false;
    }
}
/* End of file panopto_data.php */
