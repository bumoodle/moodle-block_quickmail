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
 * MoodleMail, an extended mechanism to send and recieve e-mail via Moodle.
 *
 * QuickMail was originally written at Louisiana State University;
 * MoodleMail extensions were written for Binghamton University by Kyle Temkin <ktemkin@binghamton.edu> 
 *
 * @copyright 2011, 2012 Louisiana State University; and 2011, 2012 Binghamton University
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */


/**
 * QuickMail utility functions.
 * 
 * @uses block
 * @uses _list
 * @package block_quickmail
 * @copyright 2011, 2012 Louisiana State University
 * @copyright 2011, 2012 Binghamton University
 * @author Louisiana State University  
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
abstract class quickmail {


    /**
     *  The frankenstyle name for the current plugin. 
     *  TODO: replace with a reference to the variable from version.php? 
     */
    const PLUGIN_NAME = 'block_quickmail';

    /**
     * Local alias of get_string, which automatically assumes the module's name.
     * 
     * @param string $key   The name of the language string to retrieve. 
     * @param mixed $a      The variable $a, which is substitued into the language string. 
     * @return string       The translated language string requested.
     */
    public static function _s($key, $a = null) {

        // Return the string for the current module.
        return get_string($key, self::PLUGIN_NAME, $a);
    }

    /**
     * Formats the time into a timestamp according to the user's timezone.
     * 
     * @param int $time     The timestamp to be converted into a user time, in with respect to Grenwich Mean Time.
     * @return string       Returns a string which represents the given date. 
     */
    public static function format_time($time) {

        // Call the main Moodle date-conversion function, with a standard e-mail timestamp.
        // TODO: Abstract me to somewhere else?
        return userdate($time, '%A, %d %B %Y, %I:%M %P');

    }

    /**
     * Clean-up all files associated with the given e-mail.
     * 
     * @param string $table     The name of the SQL table to be cleaned up.
     * @param int $contextid    The context identifier which identifies the context from which the e-mail was sent. 
     *                          Used to identify the correct file area.
     * @param int $itemid       The itemid which is used to identify which filearea corresponds to the e-mail to be deleted.
     * @return bool             True iff the delete was successful.
     */
    public static function cleanup($table, $contextid, $itemid) {

        // Get a reference to the active database.
        global $DB;

        // Get the last word in the table name, which identifies the relevant file area.
        // TODO: Clean this up. This seems messy- perhaps it should be passed in instead, generalizing the naming convention?
        $filearea = end(explode('_', $table));

        // Get a reference to the global file storage manager.
        $fs = get_file_storage();

        // Delete all attachments associated with the given e-mail.
        $fs->delete_area_files( $contextid, 'block_quickmail', 'attachment_' . $filearea, $itemid);

        // And then delete the message.
        $fs->delete_area_files( $contextid, 'block_quickmail', $filearea, $itemid);

        // Finally, delete the database records associated with the e-mail.
        return $DB->delete_records($table, array('id' => $itemid));
    }

    /**
     * Remove all files associated with the given "sent" message.
     * 
     * @param int $contextid    The context ID from which the message was originally sent.
     * @param int $itemid       The item ID, which identifies the file area associated with the message.
     * @return bool             True on success.
     */
     function history_cleanup($contextid, $itemid) {
        return quickmail::cleanup('block_quickmail_log', $contextid, $itemid);
     }

    /**
     * Remove all files associated with the given "draft" message.
     * 
     * @param int $contextid    The context ID from which the message was originally sent.
     * @param int $itemid       The item ID, which identifies the file area associated with the message.
     * @return bool             True on success.
     */
     static function draft_cleanup($contextid, $itemid) {
        return quickmail::cleanup('block_quickmail_drafts', $contextid, $itemid);
     }

    static function process_attachments($context, $email, $table, $id) {
        global $CFG, $USER;

        $base_path = "block_quickmail/{$USER->id}";
        $moodle_base = "$CFG->tempdir/$base_path";

        if (!file_exists($moodle_base)) {
            mkdir($moodle_base, $CFG->directorypermissions, true);
        }

        $zipname = $zip = $actual_zip = '';

        if (!empty($email->attachment)) {
            $zipname = "attachment.zip";
            $actual_zip = "$moodle_base/$zipname";

            $safe_path = preg_replace('/\//', "\\/", $CFG->dataroot);
            $zip = preg_replace("/$safe_path\\//", '', $actual_zip);

            $packer = get_file_packer();
            $fs = get_file_storage();

            $files = $fs->get_area_files(
                $context->id,
                'block_quickmail',
                'attachment_' . $table,
                $id,
                'id'
            );

            $stored_files = array();

            foreach ($files as $file) {
                if($file->is_directory() and $file->get_filename() == '.')
                    continue;

                $stored_files[$file->get_filepath().$file->get_filename()] = $file;
            }

            $packer->archive_to_pathname($stored_files, $actual_zip);
        }

        return array($zipname, $zip, $actual_zip);
    }

    static function attachment_names($draft) {
        global $USER;

        $usercontext = get_context_instance(CONTEXT_USER, $USER->id);

        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draft, 'id');

        $only_files = array_filter($files, function($file) {
            return !$file->is_directory() and $file->get_filename() != '.';
        });

        $only_names = function ($file) { return $file->get_filename(); };

        $only_named_files = array_map($only_names, $only_files);

        return implode(',', $only_named_files);
    }

    static function filter_roles($user_roles, $master_roles) {
        return array_uintersect($master_roles, $user_roles, function($a, $b) {
            return strcmp($a->shortname, $b->shortname);
        });
    }

    static function load_config($courseid) {
        global $DB;

        $fields = 'name,value';
        $params = array('coursesid' => $courseid);
        $table = 'block_quickmail_config';

        $config = $DB->get_records_menu($table, $params, '', $fields);

        if (empty($config)) {
            $m = 'moodle';
            $allowstudents = get_config($m, 'block_quickmail_allowstudents');
            $roleselection = get_config($m, 'block_quickmail_roleselection');
            $prepender = get_config($m, 'block_quickmail_prepend_class');
            $receipt = get_config($m, 'block_quickmail_receipt');

            $config = array(
                'allowstudents' => $allowstudents,
                'roleselection' => $roleselection,
                'prepend_class' => $prepender,
                'receipt' => $receipt
            );
        }

        return $config;
    }

    static function default_config($courseid) {
        global $DB;

        $params = array('coursesid' => $courseid);
        $DB->delete_records('block_quickmail_config', $params);
    }

    static function save_config($courseid, $data) {
        global $DB;

        quickmail::default_config($courseid);

        foreach ($data as $name => $value) {
            $config = new stdClass;
            $config->coursesid = $courseid;
            $config->name = $name;
            $config->value = $value;

            $DB->insert_record('block_quickmail_config', $config);
        }
    }

    function delete_dialog($courseid, $type, $typeid) {
        global $CFG, $DB, $USER, $OUTPUT;

        $email = $DB->get_record('block_quickmail_'.$type, array('id' => $typeid));

        if (empty($email))
            print_error('not_valid_typeid', 'block_quickmail', '', $typeid);

        $params = array('courseid' => $courseid, 'type' => $type);
        $yes_params = $params + array('typeid' => $typeid, 'action' => 'confirm');

        $optionyes = new moodle_url('/blocks/quickmail/emaillog.php', $yes_params);
        $optionno = new moodle_url('/blocks/quickmail/emaillog.php', $params);

        $table = new html_table();
        $table->head = array(get_string('date'), quickmail::_s('subject'));
        $table->data = array(
            new html_table_row(array(
                new html_table_cell(quickmail::format_time($email->time)),
                new html_table_cell($email->subject))
            )
        );

        $msg = quickmail::_s('delete_confirm', html_writer::table($table));

        $html = $OUTPUT->confirm($msg, $optionyes, $optionno);
        return $html;
    }

    function list_entries($courseid, $type, $page, $perpage, $userid, $count, $can_delete) {
        global $CFG, $DB, $OUTPUT;

        $dbtable = 'block_quickmail_'.$type;

        $table = new html_table();

        $params = array('courseid' => $courseid, 'userid' => $userid);
        $logs = $DB->get_records($dbtable, $params,
            'time DESC', '*', $page * $perpage, $perpage * ($page + 1));

        $table->head= array(get_string('date'), quickmail::_s('subject'),
            quickmail::_s('attachment'), get_string('action'));

        $table->data = array();

        foreach ($logs as $log) {
            $date = quickmail::format_time($log->time);
            $subject = $log->subject;
            $attachments = $log->attachment;

            $params = array(
                'courseid' => $log->courseid,
                'type' => $type,
                'typeid' => $log->id
            );

            $actions = array();

            $open_link = html_writer::link(
                new moodle_url('/blocks/quickmail/email.php', $params),
                $OUTPUT->pix_icon('i/search', 'Open Email')
            );
            $actions[] = $open_link;

            if ($can_delete) {
                $delete_params = $params + array(
                    'userid' => $userid,
                    'action' => 'delete'
                );

                $delete_link = html_writer::link (
                    new moodle_url('/blocks/quickmail/emaillog.php', $delete_params),
                    $OUTPUT->pix_icon("i/cross_red_big", "Delete Email")
                );

                $actions[] = $delete_link;
            }

            $action_links = implode(' ', $actions);

            $table->data[] = array($date, $subject, $attachments, $action_links);
        }

        $paging = $OUTPUT->paging_bar($count, $page, $perpage,
            '/blocks/quickmail/emaillog.php?type='.$type.'&amp;courseid='.$courseid);

        $html = $paging;
        $html .= html_writer::table($table);
        $html .= $paging;
        return $html;
    }

    /**
     * Returns all e-mail signatures stored for a given user.
     * 
     * @param stdClass   The $USER-style object for the user whose signatures should be retrieved.
     * @return stdClass  The database data for each of the user's signatures.
     */
    public static function get_user_signatures($user = null) {

        // Get a reference to the current user and the global database object.
        global $USER, $DB;

        // If no user was provided, use the currently calling user.
        if($user == null) {
            $user = $USER;
        }

        // Return an array of the user's e-mail signature objects.
        return $DB->get_records('block_quickmail_signatures', array('userid' => $user->id), 'default_flag DESC');
    }


    /**
     * Sends an e-mail based on the QuickMail e-mail form data.
     * 
     * @param mixed $data 
     * @return void
     */
    public static function send_message_from_form_data($data, $user_pool, $editor_options, $context, $type = '') {

        // Get a reference to the current database object.
        global $DB, $COURSE, $USER;

        // Create a basic array which will accumulate any errors which may occur.
        $errors = array();

        // Update the currently sent/saved time to match the current time.
        $data->time = time();

        //
        // And parse the form data in order to get the e-mail fields:
        //

        // Get the format and message from the HTML editor...
        $data->format = $data->message_editor['format'];
        $data->message = $data->message_editor['text'];

        // Get the attachments from the attachment file area...
        $data->attachment = self::attachment_names($data->attachments);


        // If the user hit the "send" button...
        if (isset($data->send)) 
        {

            // ... then add this to the "sent messages" table.
            $table = 'log';
            $data->id = $DB->insert_record('block_quickmail_log', $data);

        } 

        // Otherwise, add this to the "draft messages" table- to be sent later. 
        elseif (isset($data->draft)) 
        {

            // Store the message in the "draft" table.
            $table = 'drafts';

            // If we're attempting to update a draft which already exists...
            if (!empty($typeid) and $type == 'drafts') 
            {

                // ... then, update the existing draft record
                $data->id = $typeid;
                $DB->update_record('block_quickmail_drafts', $data);
            } 

            // Otherwise, create a new draft object.
            else 
            {
                $data->id = $DB->insert_record('block_quickmail_drafts', $data);
            }
        }

        // Store the relevant message data in the database- "checking in" the draft data.
        $data = file_postupdate_standard_editor($data, 'message', $editor_options, $context, 'block_quickmail', $table, $data->id);

        // And update the message data to store the newly modified message data.
        $DB->update_record('block_quickmail_'.$table, $data);

        // Determine if we should prepend the course name / prefix to the e-mail subject line.
        $prepend = !empty($qm_config['prepend_class']);

        // If we should, and the course has a prefix specified, add it to the subject line.
        if ($prepend && !empty($course->$prepender)) {
            $subject = "[{$course->$prepender}] $data->subject";

        // Otherwise, use the subject line directly.
        } else {
            $subject = $data->subject;
        }

        // Copy all of the attachment files from the draft file area into a more permenant storage.
        file_save_draft_area_files($data->attachments, $context->id, 'block_quickmail', 'attachment_' . $table, $data->id);

        // If the user pressed the send button, send the e-mail.
        if (isset($data->send)) {

            // If this data was previously a draft, then clean up the draft data before sending.
            if ($type == 'drafts') {
                self::draft_cleanup($typeid);
            }

            // Convert the attached files into a zip file for sending.
            list($zipname, $zip, $actual_zip) = quickmail::process_attachments($context, $data, $table, $data->id);

            // If a signature has been specified; use it.
            if (!empty($sigs) and $data->sigid > -1) {

                // Get the active signature, as specified.
                $sig = $sigs[$data->sigid];

                // Re-write any links to files in the signature to be accessible by remote users.
                $signaturetext = file_rewrite_pluginfile_urls($sig->signature, 'pluginfile.php', $context->id, 'block_quickmail', 'signature', $sig->id, $editor_options);

                // And append the signature to the message.
                $data->message .= $signaturetext;
            }

            // Re-write any local links to uploaded images and embedded files so they're accesible by the recipient.
            $data->message = file_rewrite_pluginfile_urls($data->message, 'pluginfile.php', $context->id, 'block_quickmail', $table, $data->id, $editor_options);

            // If an alterate e-mail has been provided, use it.
            // TODO: Replace this block with send-from-custom Moodle e-mail, allowing replies from normal e-mails.
            //
            if (!empty($data->alternateid)) {
                $user = clone($USER);
                $user->email = $alternates[$data->alternateid];
            } else {
                $user = $USER;
            }

            // Send a message to each of the listed users.
            foreach (explode(',', $data->mailto) as $userid) {

                // Send the actual e-mail. Replace me with a local function that handles the reply-to functionality.
                $success = email_to_user($user_pool[$userid], $user, $subject, strip_tags($data->message), $data->message, $zip, $zipname);

                // If transmission to a given user failed, record the error.
                if(!$success) {
                    $errors[] = get_string("no_email", 'block_quickmail', $user_pool[$userid]);
                }
            }

            // If the user has requested a copy of their own e-mail, send it to themself as well.
            if ($data->receipt) {
                email_to_user($USER, $user, $subject, strip_tags($data->message), $data->message, $zip, $zipname);
            }

            // If an attachement zip file was created, delete it.
            if (!empty($actual_zip)) {
                unlink($actual_zip);
            }

            // Return the list of errors; if no errors occurred, this will be an empty array.
            return $errors;
        }
    }

    /**
     * Indicates whether the given user has a given capability in a course.
     *
     * @param string   $capability  The capability to check for, as a string, like 'block/quickmail:cansend'.
     * @param stdClass $user        The user to query for; or null to use the current user.
     * @param stdClass $course      The course to query for; or null to use the current course.
     * @return bool                 True iff the current user can send messages to all course participants.
     */
    public static function has_capability_in_course($capability, $user = null, $course = null)
    {
        // Use the user and course globals.
        global $USER, $COURSE;

        // If no values for $user or course were provided, use the global objects.
        $user = ($user === null) ? $USER : user;
        $course = ($course === null) ? $COURSE : user;

        // Get a reference to the current course context.
        $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);

        // Return true iff the active user has the ability to send a message in this course context.
        return (has_capability($capability, $context));

    }

    /**
     * Indicates whether the given user can send messages to participants in the given course using QuickMail.
     * 
     * @param stdClass $user    The user to query for; or null to use the current user.
     * @param stdClass $course  The course to query for; or null to use the current course.
     * @return bool             True iff the current user can send messages to all course participants.
     */
    public static function can_send_to_course_participants($user = null, $course = null) {
        return self::has_capability_in_course('block/quickmail:cansend', $user, $course);
    } 

   /**
    * Indicates whether the given user can send messages to the instructor in the given course using QuickMail. 
     * 
     * @param stdClass $user    The user to query for; or null to use the current user.
     * @param stdClass $course  The course to query for; or null to use the current course.
     * @return bool             True iff the current user can send messages to all course participants.
     */
    public static function can_send_to_course_instructor($user = null, $course = null) {
        return self::has_capability_in_course('block/quickmail:canaskinstructor', $user, $course);
    }
 
     /**
     * Indicates whether the given user can send messages to the instructor in the given course using QuickMail. 
     * 
     * @deprecated              The alternates mechanism will likely be removed in favor of the reply-via-email addresses.
     * @param stdClass $user    The user to query for; or null to use the current user.
     * @param stdClass $course  The course to query for; or null to use the current course.
     * @return bool             True iff the current user can send messages to all course participants.
     */
    public static function can_send_from_alternate_address($user = null, $course = null) {
        return self::has_capability_in_course('block/quickmail:allowalternate', $user, $course);
    }



}

function block_quickmail_pluginfile($course, $record, $context, $filearea, $args, $forcedownload) {
    $fs = get_file_storage();
    global $DB;

    list($itemid, $filename) = $args;
    $params = array(
        'component' => 'block_quickmail',
        'filearea' => $filearea,
        'itemid' => $itemid,
        'filename' => $filename
    );

    $instanceid = $DB->get_field('files', 'id', $params);

    if (empty($instanceid)) {
        send_file_not_found();
    } else {
        $file = $fs->get_file_by_id($instanceid);
        send_stored_file($file);
    }
}
