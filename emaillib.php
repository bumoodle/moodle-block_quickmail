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
 * Generic class for QuickMail e-mail frontends.
 * 
 * @package block_quickmail
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
abstract class quickmail_email_composer
{


    /**
     * The context from which this QuickMail message is being sent. 
     * 
     * @var context
     */
    protected $context;

    /**
     * The user who is sending the current message.
     * This is typically equal to $USER, but is given here for flexibility.
     * 
     * @var mixed
     */
    protected $user;


    /**
     * The course in which this QuickMail message is being sent.
     * 
     * @var stdClass    Course object.
     */
    protected $course;

    /**
     * The per-course options set in the QuickMail configuration.
     * 
     * @var stdClass
     */
    protected $options;

    /**
     * An array of signatures which the user can use.
     */
    protected $signatures;


    /**
     * An internal MoodleForm which is used to compose the e-mail message.
     * 
     * @var mixed
     */
    protected $form = null;


    // 
    // Public methods:
    //

    /**
     * Creates a new QuickMail e-mail object. 
     * 
     * @param int $course_id        The course ID which this 
     * @param int $signature_id 
     * @return void
     */
    public function __construct($course_id, $signature_id = 0, array $message_defaults = array(), $user = null)
    {
        // Get a reference to the currently logged-in user.
        global $USER;

        // Set the active user. If no user was provided, then use the currently logged-in user. 
        $this->user = ($user === null) ? $USER : $user;

        // Set the current course.
        $this->set_course_by_id($course_id);

        // And create the context in which this e-mail will be sent.
        $this->create_context();

        // Verify that the active user has permission to send e-mails using QuickMail.
        $this->verify_permissions();

        // And load the current user's signatures.
        $this->load_signatures();
    }

    /**
     * Returns the current context for this e-mailthis->.
     * 
     * @return context  Returns the context which owns this e-mail.
     */
    public function get_owning_context() {

        return $this->context;

    }

    /**
     * Returns the course from which this e-mail is being sent.
     * 
     * @return stdClass     Returns the course from which this e-mail is being set.
     */
    public function get_owning_course() {
    
        return $this->course;
    }

    
    /**
     * Returns the location which the user should be redirected to if the user cancels composition.
     * Defaults to the course main page.
     * 
     * @return moodle_url   The URL which the user should be redirected to if message composition is cancelled.
     */
    public function get_cancel_destination() {

        // By default, redirect the user to the "view course" screen.
        return new moodle_url('/course/view.php', array('id' => $this->course->id));
    }

    /**
     * Returns the location which the user should be redirected to if the message is sent successfully.
     * 
     * @return moodle_url   The URL which the user should be redirected to if the message is sent successfully.
     */
    public function get_success_destination() {

        // By default, redirect the user to the e-mail log page.
        return new moodle_url('/blocks/quickmail/emaillog.php', array('courseid' => $this->course->id));

    }


    /**
     * Returns a "message compose" form, which can be used to compose an outgoing message.
     * 
     * @return moodleform   The MoodleForm used for composing the QuickMail message.
     */
    public function get_compose_form() {

        // If a form has not yet been created for this composer, create the core message form.
        if($this->form == null) {
            $this->create_message_form();
        }

        // And return a reference to the form.
        return $this->form;

    }

    /**
     * Set the Course from which this e-mail is being sent.
     * 
     * @param int $course_id 
     * @return void
     */
    public function set_course_by_id($course_id) {

        // Get a reference to the core database.
        global $DB;

        // Get the course records corresponding to the given ID.
        $this->course = $DB->get_record('course', array('id' => $course_id)); 

        // If we didn't recieve a valid course, throw an error.
        if($this->course === false) {
            throw new moodle_quickmail_exception('no_course', 'block_quickmail', '', $course_id);
        }

        // Load the QuickMail configuration for the given course.
        $this->options = (object)quickmail::load_config($course_id);
    }
    

    /**
     * Creates the local context field from the known course information.
     * 
     * @return void
     */
    protected function create_context() {

        // In this base class, we assume that all e-mails are sent from the Course context.
        $this->context = get_context_instance(CONTEXT_COURSE, $this->course->id);

    }

    /**
     * Returns the ID number for a given user's default signature.
     * 
     * @return void
     */
    protected function get_default_signature_id()
    {
        // Get a reference to the global database object.
        global $DB;

        // Attempt to load the default signature form the database.
        return $DB->get_field('block_quickmail_signatures', 'id', array( 'userid' => $this->user->id, 'default_flag' => 1));
    }

    /**
     * Returns the default file area which will store the message to be composed.
     * 
     * @return void
     */
    protected function get_file_area() {
        return 'log';
    }

    /**
     * Returns the itemid for the existing file areas; if appropriate.
     * 
     * @return void
     */
    protected function get_file_id() {
        return null;
    }

    protected function get_compose_editor_options()
    {
        // Assume the following message options.
        // TODO: modify these options
        return array(
            'trusttext' => true,
            'subdirs' => true,
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'context' => $this->context,
        );


    }


    /**
     * Creates a file area for the attachments. 
     * 
     * @param boolean $always   If false, then an existing file area will be used, if one exists. If true, a file area will always be created; and any previous filearea will be discarded.
     * @return void
     */
    protected function create_attachment_area($always = false) {

        // Get the core form data for the e-mail send form.
        $data = $this->form->get_data();

        // If no form data exists, then create a new data object.
        if(!$data) {
            $data = new stdClass;
        }

        // If no existing attachement file area exists, create a new draft filearea for attachments.
        if (empty($data->attachments) || $always) {

            // Get the draft item ID associated with the attachment file manager, if files have already been uploaded.
            $attachid = file_get_submitted_draft_itemid('attachment');

            // And create a new draft file area for the files, if necessary.
            file_prepare_draft_area($attachid, $this->context->id, 'block_quickmail', 'attachment_' . $this->get_file_area(), $this->get_file_id());

            // If we just created a draft file area for the files, associate it with the form.
            $data->attachments = $attachid;

            // Pass the updated attachment data back into the form.
            // TODO: Find a better way to do this?
            $this->form->set_data($data);
        }

    }


    /**
     * Creates a "Compose Message" MoodleForm 
     * 
     * @param mixed $action 
     * @param mixed $customdata 
     * @access public
     * @return void
     */
    abstract protected function create_message_form();



    /**
     * Loads all of a user's signatures from the database.
     * 
     * @return void
     */
    protected function load_signatures() {

        // Get a reference to the global database object.
        global $DB;

        // Load the user's signatures from the database.
        $this->signatures = $DB->get_records('block_quickmail_signatures', array('userid' => $this->user->id), 'default_flag DESC');

    }

   

    /**
     * Verifies that the current user has permission to send e-mails using QuickMail.
     *
     * @throws moodle_quickmail_exception   Throws an exception if the user does not have sufficient priveleges to use QuickMail. 
     * @return void
     */
    protected function verify_permissions() {

        // The user can send e-mails using this default base class iff they have the 'cansend' capability.
        // If they don't, throw an exception
        if(!has_capability('block/quickmail:cansend', $this->context)) {
            throw new moodle_quickmail_exception('no_permission', 'block_quickmail');
        }
    }

   /**
     * An abstract function which will return true if and only if the e-mail has addressible recipients.
     * 
     * @access public
     * @return boolean  True iff the current composer can address recipients.
     */
    public abstract function potential_recipients_exist();


    /**
     * Validates the given data, and determines if it can be used for sending an e-mail message.
     * If an error is detected, it will throw an exception.
     * 
     * @throws moodle_quickmail_exception   Thrown if the message is missing necessary information (subject, recipients). 
     * @param array $data                   An array of message data which will be checked. If no data is provided, the compose form's data will be used.
     * @return void
     */
    public function validate_data($data = null) {

        // If no data was provided, use the data from the internal form.
        if($data === null) {
            $data = $this->form->get_data();
        }

        // If we didn't get any form data, then throw an exception.
        if(!$data) {
            throw new moodle_quickmail_exception('no_subject_users', 'block_quickmail'); 
        }  

        // Determine if recipients and a subject were provided.
        $no_subject = empty($data->subject);
        $no_users = empty($data->mailto);

        //If neither was provided, throw an exception.
        if($no_subject && $no_users) {
            throw new moodle_quickmail_exception('no_subject_users', 'block_quickmail');     
        } 
        
        // If one of the two is missing, throw an exception:
        if($no_subject) {
            throw new moodle_quickmail_exception('no_subject', 'block_quickmail');     
        } 
        if($no_users) {
            throw new moodle_quickmail_exception('no_users', 'block_quickmail');     
        }
    }

    /**
     * Returns true if the user has indicated that the given e-mail is ready to send.
     * 
     * @return bool     True iff the user has submitted this form by pressing the "send" button.
     */
    public function send_requested() {

        // Get the data for the form.
        $data = $this->form->get_data();

        // Return true iff we recieved valid form data, and the user has just pressed the send button.
        return ($data && !empty($data->send));
    
    }

    /**
     * Returns true if the user has indicated that the given e-mail is ready to send.
     * 
     * @return bool     True iff the user has submitted this form by pressing the "send" button.
     */
    public function save_requested() {

        // Get the data for the form.
        $data = $this->form->get_data();

        // Return true iff we recieved valid form data, and the user has just pressed the send button.
        return ($data && !empty($data->draft));
    
    }

    /**
     * Returns true iff the user has cancelled submission of the given form.
     * 
     * @return bool     True iff the user has hit the "cancel" button on the form.
     */
    public function cancel_requested() {

        // If a form has not yet been created for this composer, create the core message form.
        if($this->form == null) {
            $this->create_message_form();
        }

        // Query the inner form and inquire as to cancellation.
        return $this->form->is_cancelled();

    }

    protected function save_record($data, $table_suffix = 'log', $existing_id = null) {
    
        // Validate all of the formdata before sending.
        $this->validate_data(); 

        // Get a reference to the global database object.
        global $DB;

        // Get the format and message from the HTML editor...
        $data->format = $data->message_editor['format'];
        $data->message = $data->message_editor['text'];

        // Get the attachments from the attachment file area...
        $data->attachment = quickmail::attachment_names($data->attachments);

        // If an existing ID has been provided...
        if($existing_id !== null) {

            // ... then, update the existing record.
            $data->id = $typeid;
            $DB->update_record('block_quickmail_'.$table_suffix, $data);

        } else {
            
            // Otherwise, add a new data row to the database.
            $data->id = $DB->insert_record('block_quickmail_'.$table_suffix, $data);
        }

        // Store the relevant message body data in the database- "checking in" the draft data.
        $data = file_postupdate_standard_editor($data, 'message', $this->get_compose_editor_options(), $this->context, 'block_quickmail', $table_suffix, $data->id);

        // Copy all of the attachment files from the draft file area into a more permenant storage.
        file_save_draft_area_files($data->attachments, $this->context->id, 'block_quickmail', 'attachment_'.$table_suffix, $data->id);

        // And update the message data to store the newly modified message data.
        $DB->update_record('block_quickmail_'.$table_suffix, $data);
    }

    protected function get_prefixed_subject($data)
    {
        // Determine if we should prepend the course name / prefix to the e-mail subject line.
        $prepend = !empty($qm_config['prepend_class']);

        // If we should, and the course has a prefix specified...
        if ($prepend && !empty($this->course->$prepender)) {

            // ... add it to the subject line, and return the result. 
            return '['.$this->course->$prepender.'] '.$data->subject;

        } else {

            // Otherwise, use the subject line directly.
           return $data->subject;
        }

    }

    /**
     * Adds the currently selected signature to the message.
     * Modifies $data inplace.
     * 
     * @param stdClass $data    The message data to be modified.
     * @return void
     */
    protected function append_signature_to_message($data)
    {
        // If the current user doesn't have any signatures; or the message has "no signature" selected, then do nothing.
        if(empty($this->signatures) || $data->sigid < 0) {
            return;
        }

        // Get the active signature, as specified.
        $sig = $this->signatures[$data->sigid];

        // Re-write any links to files in the signature to be accessible by remote users.
        $signaturetext = file_rewrite_pluginfile_urls($sig->signature, 'pluginfile.php', $this->context->id, 'block_quickmail', 'signature', $sig->id, $editor_options);

        // And append the signature to the message.
        $data->message .= $signaturetext;
    }

    public function save_draft($existing_id = null, $update_form = true) {

        // Get the message information, as submitted by the user.
        $data = $this->form->get_data();

        // Update the currently sent/saved time to match the current time.
        $data->time = time();

        // Save record of the e-mail to be sent in the database.
        $this->save_record($data, 'draft', $existing_id);

        // If $update_form is set, then migrate any data changes back to the core form.
        if($update_form) {
            $this->form_set_data($data);
        }
    }


    /**
     * Send the message which was composed in this 
     * 
     * @param bool $update_form     If true, the form's data will be updated according to the modifications made during the send process- e.g. the appending of the signature. Be careful if you se this to false- as the internal file areas may not be properly updated.
     * @return void
     */
    public function send_message($update_form = true) {

        // Validate all of the formdata before sending.
        $this->validate_data(); 

        // Create a basic array which will accumulate any errors which may occur.
        $errors = array();

        //get the message information, as submitted by the user.
        $data = $this->form->get_data();

        // update the currently sent/saved time to match the current time.
        $data->time = time();

        // save record of the e-mail to be sent in the database.
        $this->save_record($data);
         

        // If this data was previously a draft, then clean up the draft data before sending.
        // TODO
        //if ($type == 'drafts') {
        //    self::draft_cleanup($typeid);
        // }

        // Convert the attached files into a zip file for sending.
        list($zipname, $zip, $actual_zip) = quickmail::process_attachments($this->context, $data, 'log', $data->id);

        // Append the selected signature to the message, if appropriate.
        $this->append_signature_to_message($data);

        // Get the subject line for the e-mail, with any prefixes added.
        $subject = $this->get_prefixed_subject($data);

        // Re-write any local links to uploaded images and embedded files so they're accesible by the recipient.
        $data->message = file_rewrite_pluginfile_urls($data->message, 'pluginfile.php', $this->context->id, 'block_quickmail', 'log', $data->id, $this->get_compose_editor_options());

        // Get a list of selected users.
        $recipients = explode(',', $data->mailto);

         // Send a message to each of the listed users.
        foreach ($recipients as $userid) {

            // Send the actual e-mail. Replace me with a local function that handles the reply-to functionality.
            $success = email_to_user($this->users[$userid], $this->user, $subject, strip_tags($data->message), $data->message, $zip, $zipname);

            // If transmission to a given user failed, record the error.
            if(!$success) {
                $errors[] = get_string("no_email", 'block_quickmail', $user_pool[$userid]);
            }
        }

        // If the user has requested a copy of their own e-mail, send them a copy as well.
        if ($data->receipt) {
            email_to_user($USER, $user, $subject, strip_tags($data->message), $data->message, $zip, $zipname);
        }

        // If an attachement zip file was created, delete it.
        if (!empty($actual_zip)) {
            unlink($actual_zip);
        }

        // If $update_form is set, then migrate any data changes back to the core form.
        if($update_form) {
            $this->form->set_data($data);
        }

        // Return the list of errors; if no errors occurred, this will be an empty array.
        return $errors;
    }


    protected function save_message_record($data, $table, $existing = null)
    {

    }

} 

/**
 * Class which represents e-mails which have "selectable" recipients- the primary 
 * operating mode of QuickMail.
 * 
 * @uses quickmail
 * @uses _email
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_selectable extends quickmail_email_composer
{
    /**
     * Stores the list of roles which denote a potential QuickMail recipient.
     * A user must have one of these roles to recieve QuickMail messages in this course.
     * 
     * @var array Array of roles which can be used to filter recipients.
     */
    protected $roles;


    /**
     * Stores the groups which can be used to filter QuickMail recipients
     *
     * @var array Array of groups which can be used to filter recipients.
     */
    protected $groups;

    /**
     * Stores an array of users who can be selected as recipients of QuickMail messages.
     *
     * @var array
     */
    protected $users;

    /**
     * Stores a list of groups which a given potential recipient is a member of.
     * Used for filtering by section.
     * 
     * @var array
     */
    protected $users_to_groups;


    /**
     * Stores a list of roles which a given potential recipient has.
     * 
     * @var mixed
     */
    protected $users_to_roles;

    /**
     * Creates a new QuickMail e-mail object. 
     * 
     * @param int $course_id        The course ID which this 
     * @param int $signature_id 
     * @return void
     */
    public function __construct($course_id, $signature_id = 0)
    {
        // Call the parent constructor to set up the main QuickMail information.
        parent::__construct($course_id, $signature_id);

        // And load the additional information used to filter recipients.
        $this->load_roles();
        $this->load_groups();
        $this->load_recipients();
    }


    /**
     * Loads the roles which are capable of recieveing QuickMail messages in the current context.
     * 
     * @return void
     */
    protected function load_roles() {

        // Get a reference to the global database object.
        global $DB;

        // Get all of the roles available in the current context.
        $course_roles = get_roles_used_in_context($this->context);
        
        // Get the role information that QuickMail uses for filtering.
        $filter_roles = $DB->get_records_select('role', 'id IN ('.$this->options->roleselection.')');

        // Get the total list of course roles, filtered by the roles selected in the quickmail configuration. 
        $this->roles = quickmail::filter_roles($course_roles, $filter_roles);
    }

    /**
     * Loads all of the groups which are visible to the current user.
     * 
     * @return void
     */
    protected function load_groups() {

        // If the current user can see all groups in a given context...
        if (has_capability('moodle/site:accessallgroups', $this->context)) {

            // ... then load all of the groups present in this context.
            $this->groups = groups_get_all_groups($this->course->id);

        } else {

            // Otherwise, load all of the groups that the current user is in.
            $this->groups = groups_get_user_groups($this->course->id);
        }

    }

    /**
     * Load all of the potential recipient users in the current course, and create the user->group and user->roles mappings,
     * which are used for filtering.
     * 
     * @return void
     */
    protected function load_recipients() {

        // Create three empty arrays: one for the core list of users in the course context, 
        // and two for mappings which associate user information with the role/group metadata.
        $this->users = array();
        $this->users_to_roles = array();
        $this->users_to_groups = array();

        // Get the _group ids_ for each of the groups that the user can see.
        // Since the local array of groups is indexed by its group ids, this is as easy as extracting the array indices.
        $visible_groups = array_keys($this->groups);

        // Get all users by their role in the given course.
        $everyone = get_role_users(0, $this->context, false,
            'u.id, u.firstname, u.lastname, u.email, u.mailformat, u.maildisplay, r.id AS roleid', 'u.lastname, u.firstname');

        // For each of the users in the course, get the course and role metadata.
        foreach ($everyone as $user_id => $user) {

            // If the target user _is_ the logged in user, skip this iteration;
            // as it wouldn't make sense for the user to send e-mail to themselves via QuickMail.
            if($user->id == $this->user->id) {
                continue;
            }

            // Get a list of roles for the _target_ user...
            // FIXME: is this redundant with the query above?
            $user_roles = get_user_roles($this->context, $user_id);

            // ... and filter those roles according to the current QuickMail settings.
            $user_roles = quickmail::filter_roles($user_roles, $this->roles);

            // Get the list of groups that the target user (not the current user) is in.
            $user_groups = groups_get_user_groups($this->course->id, $user_id);

            // Get the IDs of all of the groups that the _target_ user is in which are visible to the logged-in user.
            $group_ids = array_intersect(array_values($visible_groups), array_values($user_groups['0']));

            // In order for the current user to be able to e-mail the target user, two conditions must be met:
            // - The current user must have a visible role, according to the configuration options.
            // - The current user must be in a group which is visible to the current user.
            // 
            // If those two conditions are met, add the user as a potential recipient.
            if (!empty($group_ids) && !empty($user_roles))
            {
                // For each of the groups that the user is in (after filtering), add the group info to the user->group mapping.
                foreach($group_ids as $group_id) {
                    $this->users_to_groups[$user_id][] = $this->groups[$group_id];
                }

                // Add each of the user's roles to the user->roles mapping.
                $this->users_to_roles[$user_id] = $user_roles;

                // And add the user to the user collection.
                $this->users[$user_id] = $user;
            }
        }

    }

    /**
     * Returns true if and only if users exist who can be targeted as recipients of this e-mail. 
     * 
     * @return void
     */
    public function potential_recipients_exist() {
        return !empty($this->users);
    }

    /**
     * Returns an array of groups which the current user is capable of seeing, or null if the user can view all groups.
     * 
     * @return array    An array of groups which the user can see; or null if the user can see all groups.
     */
    protected function get_group_filter() {

        //TODO: Replace this with a SQL join? 

        // If the user is not allowed to access all groups in the current context,
        // create a "filter" which identifies which groups the user can access. 
        if (!has_capability('moodle/site:accessallgroups', $this->context)) {

            // Get an array of groups which the current user is a member of.
            $member_groups = groups_get_user_groups($this->course->id);

            // If the user isn't in any groups, they shouldn't be able to see anything.
            if(empty($mygroups['0'])) {
                
                // Return an empty array of "seeable" groups.
                return array();

            } else {
                
                // Otherwise, create a SQL clause which will get all of the relevant groups...
                $gids = implode(',', array_values($mygroups['0']));

                // ... and use it to get an array of group information.
                return $DB->get_records_select('groups', 'id IN ('.$gids.')');

            }

        // Otherwise, the user can access all groups, and no filter is necessary.
        } else {
            return null;
        }
    }

    /**
     * Creates a MoodleForm which will represent the e-mail message to be sent.
     * 
     * @return void
     */
    protected function create_message_form()
    {
        // Gather the data which will be passed to the e-mail composition form.
        // TODO: Pass it a reference to the current object instead?
        $data = array(
            'editor_options' => $this->get_compose_editor_options(),
            'users' => $this->users,
            'roles' => $this->roles,
            'groups' => $this->groups,
            'users_to_roles' => $this->users_to_roles,
            'users_to_groups' => $this->users_to_groups,
            'sigs' => array_map(function($sig) { return $sig->title; }, $this->signatures),
            'alternates' => array(), //$alternates
        );

        // Create the e-mail composition form.
        $this->form = new email_form(null, $data);

        // Create an attachement area for this message, if necessary.
        $this->create_attachment_area();
    }

}

#class quickmail_email_


/*
class quickmail_email_composer_ask_instructor extends quickmail_email_composer
{

}
 */
