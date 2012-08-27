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

require_once($CFG->dirroot.'/mod/quiz/attemptlib.php'); 
require_once($CFG->dirroot.'/mod/quiz/accessmanager.php'); 

/**
 * A generic class which represents an e-mail composition interface for QuickMail.
 * 
 * @package block_quickmail
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @copyright 2011, 2012 Louisiana State University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
abstract class quickmail_email_composer
{
    /**
     *  Represents the default value of the signature parmeter; used to "skip" the signature ID positional argument.
     */
    const DEFAULT_SIGNATURE = 0;

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
     *
     * @var array
     */
    protected $signatures;


    /**
     * An array of parameters which are passed to the internal form; and included
     * with any executed queries.
     * 
     * @var mixed
     */
    protected $request_parameters;


    /**
     * An internal MoodleForm which is used to compose the e-mail message.
     * 
     * @var moodleform
     */
    protected $form = null;


    // 
    // Public methods:
    //

    /**
     * Creates a new QuickMail e-mail composer object according to the permissions that the given user has.
     * If the user cannot use a normal composer, as an exmaple
     *
     * @throws moodle_quickmail_permission_exception    Throw if the user does not have permission to access QuickMail while the $must_have_permission flag is set.
     *
     * @param int $course_id                The ID number of the course from which e-mail is to be sent.
     * @param int $signature_id             The default signature for the quick-mail composer.
     * @param array $request_parameters     An array of request parameters which will be included with any executed POST/GET query.
     * @param stdClass $user                A reference to the user who is sending the given e-mail. If not provided, the currently logged-in user will be used instead.
     * @param bool $must_have_permission    If this is set, an exception will be thrown if a user does not have permission.
     * @return quickmail_email_composer     A quickmail_email_composer which is appropriately limited given the user's permissions, or null if the user shouldn't be able to send.
     */
    public static function create_according_to_permissions($course_id, $signature_id = 0, $requrest_parameters = array(), $user = null, $must_have_permission = false) {
    
        // In this base class, we assume that all e-mails are sent from the Course context.
        $context = get_context_instance(CONTEXT_COURSE, $this->course->id);

        // If the user has permission to message all course participants...
        if (quickmail::can_message_participants($context, $user)) {

            // ... return a full-access composer object.
            return new quickmail_email_composer_selectable($course_id, $signature_id, $request_parameters, $user);
        }
        // Otherwise, if they have permission to message their instructor...
        else if (quickmail::can_message_instructor($context, $user)) {

            /// ... return a recipient-limited composer object.
            return new quickmail_email_composer_ask_instructor($course_id, $signature_id, $request_parameters, $user);
        }
        // If the user does not have either of the above permissions, and must_have_permission is set, throw an exception.
        else if($must_have_permission) {
            throw new moodle_quickmail_permissions_exception('no_permission', 'block_quickmail');
        }
        // Otherwise, return null, indicating that no composer exists which matches the user's permission level.
        else {
            return null;
        }

    }


    /**
     * Creates a new QuickMail e-mail composer.
     *
     * @param int $course_id                The ID number of the course from which e-mail is to be sent.
     * @param int $signature_id             The default signature for the quick-mail composer.
     * @param array $request_parameters     An array of request parameters which will be included with any executed POST/GET query.
     * @param stdClass $user                   A reference to the user who is sending the given e-mail. If not provided, the currently logged-in user will be used instead.
     * @return quickmail_email_composer
     */
    public function __construct($course_id, $signature_id = 0, $request_parameters = array(), $user = null) {

        // Get a reference to the currently logged-in user.
        global $USER;

        // Set the active user. If no user was provided, then use the currently logged-in user. 
        $this->user = ($user === null) ? $USER : $user;

        // Store the array of request parameters; for use in form creation.
        $this->request_parameters = $request_parameters;

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
     * Returns the current context for this e-mail.
     * 
     * @return context  Returns the context which owns this e-mail.
     */
    public function get_owning_context() {

        return $this->context;

    }

    /**
     * Returns the _course context_ for this e-mail; which may be different than the core context
     * if QuickMail is being used inside of a module.
     * 
     * @return context
     */
    public function get_course_context() {

        return $this->context->get_course_context();

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
     * Returns the "header" for the current page; which is used for breadcrumbs and the page header.
     * 
     * @return string   The header text which describes the function of the current page.
     */
    public function get_header_string() {

        return get_string('composenew', 'block_quickmail');
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
     * @throws moodle_quickmail_exception
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
     * @return int  The ID number that
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



    /**
     * Returns the core editor options for this E-mail composer.
     * Used by the main e-mail editor and attachments filearea.
     * 
     * @return void
     */
    protected function get_compose_editor_options()
    {
        // Assume the following message options.
        return array(
            'maxbytes' => $this->course->maxbytes,
            'trusttext' => trusttext_trusted($this->context),
            'subdirs' => true,
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'context' => $this->context,
        );
    }


    /**
     * Initializes the internal compose e-mail form.
     * 
     * @return void
     */
    protected function initialize_form() {

        // Get the core form data for the e-mail send form.
        $data = $this->form->get_data();

        // If no form data exists, then use the form's defaults.
        if(!$data) {
            $data = $this->get_initial_form_values();
        }

        // If no existing attachement file area exists, create a new draft filearea for attachments.
        if (empty($data->attachments)) {

            // Get the draft item ID associated with the attachment file manager, if files have already been uploaded.
            $attachid = file_get_submitted_draft_itemid('attachment');

            // And create a new draft file area for the files, if necessary.
            file_prepare_draft_area($attachid, $this->context->id, 'block_quickmail', 'attachment_' . $this->get_file_area(), $this->get_file_id());

            // If we just created a draft file area for the files, associate it with the form.
            $data->attachments = $attachid;

        }

        // Create a draft area, which stores any inline images or files which are linked inline.
        $data = file_prepare_standard_editor($data, 'message', $this->get_compose_editor_options(), $this->context, 'block_quickmail', $this->get_file_area(), $this->get_file_id());

        // Pass the updated attachment data back into the form.
        // TODO: Find a better way to do this?
        $this->form->set_data($data);

    }


    /**
     * Returns an object which contains all of the form's defaults.
     * 
     * @return object   An object which contains all of the form's defaults; suitable for use with the relevant moodleform's set_data method.
     */
    protected function get_initial_form_values()
    {
        // Create a new empty object, which will story each of the form's defaults.
        $data = new stdClass();

        // And specify the base defaults: every field is empty, and the user's message format is the same as their preferred mail format.
        $data->id = null;
        $data->subject = '';
        $data->message = '';
        $data->mailto = '';
        $data->format = $this->user->mailformat;

        // Create copies of the following two fields in the format that Moodle expects.
        $data->messagetext = $data->message;
        $data->messageformat = $data->format;

        // Return the newly created defaults.
        return $data;
    }


    /**
     * Creates a "Compose Message" MoodleForm, which provides the primary interface for the user to compose an e-mail message.
     * 
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
        if(!quickmail::can_message_participants($this->context, $this->user)) {
            throw new moodle_quickmail_permissions_exception('no_permission', 'block_quickmail');
        }
    }
    
   /**
     * An abstract function which will return true if and only if the e-mail has addressible recipients.
     * 
     * @access public
     * @return boolean  True iff the current composer can address recipients.
     */
    abstract public function potential_recipients_exist();


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
        $no_subject = $this->get_subject_line($data) == false;
        $no_users = $this->get_recipients($data) == false;

        //If neither was provided, throw an exception.
        if($no_subject && $no_users) {
            throw new moodle_quickmail_exception('no_subject_users', 'block_quickmail');     
        } 
        
        // If one of the two is missing, throw an exception:
        if($no_subject) {
            throw new moodle_quickmail_exception('no_subject', 'block_quickmail');     
        } 
        if($no_users) {
            throw new moodle_quickmail_exception('no_selected', 'block_quickmail');     
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


    /**
     * Saves a record of an e-mail which was sent using QuickMail.
     * Drafts are records which are unsent; 
     * 
     * @param stdClass $data        The e-mail data to be commited to the database; contains all of the e-mail's information.
     * @param string $table_suffix  The table suffix; which indicates the table to which this data will be saved. 
     * @param mixed $existing_id    The existing database ID, if applicable. If a database ID is provided, the appropriate records
     *                              will be updated, instead of created. 
     * @return void
     */
    protected function save_record($data, $table_suffix = 'log', $existing_id = null) {
    
        // Get a reference to the global database object.
        global $DB;

        // Validate all of the formdata before sending.
        $this->validate_data(); 

        // Prepare the data for saving in the database.
        $this->prepare_for_save($data);

        // Get the format and message from the HTML editor...
        $data->format = $data->message_editor['format'];
        $data->message = $data->message_editor['text'];

        // Get the attachments from the attachment file area...
        $data->attachment = quickmail::attachment_names($data->attachments);

        // If an existing ID has been provided...
        if($existing_id !== null) {

            // ... then, update the existing record.
            $data->id = $existing_id;
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

    /**
     * Gets the subject line for the e-mail, prefixed with the course name as per the course settings.
     * 
     * @param stdClass $data    The e-mail compose form's data; which contains the e-mail's subject.
     * @return string           The subject line, with prefix added.
     */
    protected function get_prefixed_subject($data)
    {
        // Get the course-property which should be prepended to the class name, if any.
        $prepend = $this->options->prepend_class;

        // Get the unmodified subject line for the current e-mail.
        $subject = $this->get_subject_line($data);

        // If we should, and the course has a prefix specified...
        if (!empty($prepend) && !empty($this->course->$prepend)) {

            // ... add it to the subject line, and return the result. 
            return '['.$this->course->$prepend.'] '.$subject;

        } else {

            // Otherwise, use the subject line directly.
           return $subject;
        }

    }

    /**
     * Returns the subject line for the given e-mail. This simple method is intended to allow
     * extending classes to easly override the subject line.
     * 
     * @param array $data   The form data to process. If not provided, the internal form's data will be used.
     * @return string       The given e-mail's subject line; or an empty string if no subject line exists.
     */
    protected function get_subject_line($data = null) {

        // If no form data was provided, load the form data from the internal form.
        if($data === null) {
            $data = $this->form->get_data();
        }

        // If no subject data exists...
        if(empty($data->subject)) {
            
            // ... return an empty string.
            return '';

        } else {

            // Otherwise, return the form's subject directly.
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

    /**
     * Save a 'draft' record of an e-mail, which can later be resumed and sent.
     * 
     * @param int $existing_id      If provided, the draft with the given ID will be updated.
     * @param bool $update_form     If true, the form's data will be updated according to the modifications made during the send process- 
     *                              e.g. the appending of the signature. Be careful if you se this to false- as the internal file areas may not be properly updated.
     * @return void
     */
    public function save_draft($existing_id = null, $update_form = true) {

        // Get the message information, as submitted by the user.
        $data = $this->form->get_data();

        // Update the currently sent/saved time to match the current time.
        $data->time = time();

        // Save record of the e-mail to be sent in the database.
        $this->save_record($data, quickmail_view_type::DRAFT, $existing_id);

        // If $update_form is set, then migrate any data changes back to the core form.
        if($update_form) {
            $this->form->set_data($data);
        }
    }


    /**
     * Send the message which was composed using this QuickMail composer.
     * 
     * @param bool $update_form     If true, the form's data will be updated according to the modifications made during the send process- 
     *                              e.g. the appending of the signature. Be careful if you se this to false- as the internal file areas may not be properly updated.
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
         
        // Convert the attached files into a zip file for sending.
        list($zipname, $zip, $actual_zip) = quickmail::process_attachments($this->context, $data, 'log', $data->id);

        // Append the selected signature to the message, if appropriate.
        $this->append_signature_to_message($data);

        // Get the subject line for the e-mail, with any prefixes added.
        $subject = $this->get_prefixed_subject($data);

        // Re-write any local links to uploaded images and embedded files so they're accesible by the recipient.
        $data->message = file_rewrite_pluginfile_urls($data->message, 'pluginfile.php', $this->context->id, 'block_quickmail', 'log', $data->id, $this->get_compose_editor_options());

        // Get a list of selected users.
        $recipients = $this->get_recipients($data);

         // Send a message to each of the listed users.
        foreach ($recipients as $recipient) {

            // Send the actual e-mail. Replace me with a local function that handles the reply-to functionality.
            $success = email_to_user($recipient, $this->user, $subject, strip_tags($data->message), $data->message, $zip, $zipname);

            // If transmission to a given user failed, record the error.
            if(!$success) {
                $errors[] = get_string("no_email", 'block_quickmail', $recipient);
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

    /**
     * Returns a collection of recipients who have been selected by the given user.
     * 
     * @param stdClass $data    The form-data to be parsed.
     * @return array            An array of user IDs
     */
    protected function get_recipients($data) {

        // Start a new array of recipients.
        $recipients = array();

        // And break the "mail-to" string into a list of recipients.
        $recipient_ids =  explode(',', $data->mailto);

        // For each of the recipients in the mail-to string, attempt to get the user's information.
        foreach($recipient_ids as $id) {

            // If the user is in the local pool of possible recipients, the user has the ability to message them; and they should be added to
            // the recipients list.
            if(array_key_exists($id, $this->users)) {
                $recipients[$id] = $this->users[$id]; 
            }
            // Otherwise, the user doesn't have permission to access the given user; throw an exception.
            else {
                throw new moodle_quickmail_exception('no_permission_user', 'block_quickmail');
            }
        }

        // Return the newly created list of recipients.
        return $recipients;
    }

    /**
     * Prepares the given e-mail data for storage in the Moodle database.
     * Modifies the data object in place.
     * 
     * @return void
     */
    protected function prepare_for_save($data) {

        // Get the list of recipients that the user has selected, minus any recipients that the user
        // is not allowed to message. (This prevents a user from modifying the mailto postvar to get user information.)
        $recipients = $this->get_recipients($data);

        // Ensure that the mailto field matches the list of recipients.
        $data->mailto = implode(',', array_keys($recipients));

        // Ensure that the subject line is up-to-date.
        $data->subject = $this->get_subject_line($data);
    }

} 

/**
 * Class which represents an e-mail composition interface which allows the user to select recipients
 * from their classes. This class represents the primary operating mode of QuickMail.
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
    public function __construct($course_id, $signature_id = 0, $request_parameters = array(), $user = null)
    {
        // Call the parent constructor to set up the main QuickMail information.
        parent::__construct($course_id, $signature_id, $request_parameters, $user);

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
        if (has_capability('moodle/site:accessallgroups', $this->context, $this->user)) {

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
        if (!has_capability('moodle/site:accessallgroups', $this->context, $this->user)) {

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
            'user'  => $this->user,
            'roles' => $this->roles,
            'groups' => $this->groups,
            'users_to_roles' => $this->users_to_roles,
            'users_to_groups' => $this->users_to_groups,
            'sigs' => array_map(function($sig) { return $sig->title; }, $this->signatures),
            'alternates' => array(), //$alternates
            'parameters' => $this->request_parameters
        );

        // Create the e-mail composition form.
        $this->form = new email_form(null, $data);

        // Initialize the Moodleform to the default data provided.
        $this->initialize_form();
    }

}

/**
 * A class representing an e-mail composition interface which is designed to re-create 
 * an e-mail from a database record. Suitable as a base for forwarding, replying, or continuing from a draft.
 * 
 * @uses quickmail
 * @uses _email_composer_selectable
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_from_existing extends quickmail_email_composer_selectable {


    /**
     *  Indicates the code for a lack of a signature.
     */
    const NO_SIGNATURE = -1;


    /**
     * The record from which this e-mail composer was created. 
     * 
     * @var int
     */
    protected $record;


    /**
     * Stores the "view type" that the record was created from.
     * 
     * @var int     A member of the quickmail_view_type "enumeration".
     */
    protected $type;


    /**
     * Creates a new QuickMail message composer object from an existing saved e-mail,
     * like a draft message, or previously sent message.
     * 
     * @param int $record_id    The ID number of the e-mail record to be loaded.
     * @param string $type      The type of message to be loaded; typically a member of the quickmail_view_type psuedo-enumeration.
     * @param array $request_parameters     An array of request parameters which will be included with any executed POST/GET query.
     * @param stdClass $user    The user who is composing the given e-mail. If not provided, the logged-in user will be used. 
     * @return void
     */
    public function __construct($record_id, $type = quickmail_view_type::DRAFT, $request_parameters = array(), $user = null) {

        // Get a reference to the global database object.
        global $DB;

        // Attempt to load the saved message data from the database.
        $this->record = $DB->get_record('block_quickmail_'.$type, array('id' => $record_id));

        // If we weren't able to load the record from the database, throw an error.
        if($this->record === false) {
            throw new moodle_quickmail_exception('not_valid_typeid', 'block_quickmail', $record_id);
        }

        // Process the record data, as it was retrieved from the database.
        $this->preprocess_data_record();

        // Save the "view type", which specifies which table we're loading the data from.
        $this->type = $type;

        // Call the parent constructor to complete the creation of the e-mail composer.
        parent::__construct($this->record->courseid, self::NO_SIGNATURE, $request_parameters, $user);

    }

    /**
     * Processes the data which was restored from the database, converting it to a form suitable for use in the
     * internal composition form.
     * 
     * @return void
     */
    protected function preprocess_data_record() {

        //Convert the stored format and message into a format which is compatible with the modern Moodleform.
        $this->record->messageformat = $this->record->format;
        $this->record->messagetext = $this->record->message;
    }


    /**
     * Returns the default file area which will store the message to be composed.
     * 
     * @return void
     */
    protected function get_file_area() {
        return $this->type;
    }

    /**
     * Returns the itemid for the existing file areas; if appropriate.
     * 
     * @return void
     */
    protected function get_file_id() {
        return $this->record->id;
    }




     /**
     * Creates a MoodleForm which will represent the e-mail message to be sent.
     * 
     * @return void
     */
    protected function create_message_form()
    {
        // Gather the data which will be passed to the e-mail composition form.
        $data = array(
            'editor_options' => $this->get_compose_editor_options(),
            'users' => $this->users,
            'user' => $this->user,
            'roles' => $this->roles,
            'groups' => $this->groups,
            'users_to_roles' => $this->users_to_roles,
            'users_to_groups' => $this->users_to_groups,
            'alternates' => array(), //$alternates
            'mailto' => $this->record->mailto,
            'parameters' => $this->request_parameters
        );

        // Create the e-mail composition form.
        $this->form = new email_form(null, $data);

        // Populate the form with the existing data loaded from the database.
        $this->form->set_data($this->record);

        // Initialize the Moodleform to the data retrieved from the database.
        $this->initialize_form();
    }

    /**
     * Returns an object which contains all of the form's default values, as restored from the database.
     * 
     * @return object   An object which contains all of the form's defaults; suitable for use with the relevant moodleform's set_data method.
     */
    protected function get_initial_form_values()
    {
        // Return the data loaded from the database.
        return $this->record;
    }





}

/**
 * An e-mail composition interface which is designed to handle e-mails which are being re-created from a 
 * previous draft.
 * 
 * @uses quickmail_email_composer_from_existing
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_from_draft extends quickmail_email_composer_from_existing {

    /**
     * Creates a new QuickMail message composer object from an existing saved draft. 
     * A convenience shorthand for quickmail_email_composer_from_existing.
     * 
     * @param int $record_id                The ID number of the e-mail record to be loaded.
     * @param string $type                  The type of message to be loaded; typically a member of the quickmail_view_type psuedo-enumeration.
     * @param array $request_parameters     An array of request parameters which will be included with any executed POST/GET query.
     * @param stdClass $user                The user who is composing the given e-mail. If not provided, the logged-in user will be used. 
     * @return void
     */
    public function __construct($record_id, $request_parameters = array(), $user = null) {

        // Call the parent constructor to complete the creation of the e-mail composer.
        parent::__construct($record_id, quickmail_view_type::DRAFT, $request_parameters, $user);

    }

    /**
     * Send the message which was composed using this QuickMail composer.
     * 
     * @param bool $update_form     If true, the form's data will be updated according to the modifications made during the send process- 
     *                              e.g. the appending of the signature. Be careful if you se this to false- as the internal file areas may not be properly updated.
     * @return aray                 A list of non-fatal error messages which have occurred during e-mail sending.
     */
    public function send_message($update_form = true) {

        // Send the e-mail message normally...
        $errors = parent::send_message($update_form);

        // If no errors have occurred...
        if(empty($errors)) {

            // ... remove the draft, as we've sent out the relevant e-mail.
            self::draft_cleanup($this->record->id);
        }

        // Return the list of encountered errors.
        return $errors;
    }

}

/**
 * An e-mail composition interface which is designed to handle the forwarding of an existing e-mail.
 * 
 * @uses quickmail_email_composer_from_existing
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_forward extends quickmail_email_composer_from_existing {

    /**
     * Creates a new QuickMail message composer object from an existing message.
     * A convenience shorthand for quickmail_email_composer_from_existing.
     * 
     * @param int $record_id    The ID number of the e-mail record to be loaded.
     * @param string $type      The type of message to be loaded; typically a member of the quickmail_view_type psuedo-enumeration.
     * @param stdClass $user    The user who is composing the given e-mail. If not provided, the logged-in user will be used. 
     * @return void
     */
    public function __construct($record_id, $request_parameters = array(), $user = null) {

        // Call the parent constructor to complete the creation of the e-mail composer.
        parent::__construct($record_id, quickmail_view_type::SENT_MAIL, $request_parameters, $user);

    }


     /**
     * Processes the data which was restored from the database, converting it to a form suitable for use in the
     * internal composition form.
     * 
     * @return void
     */
    protected function preprocess_data_record() {

        //Perform the core preprocessing for an e-mail restored from the database.
        parent::preprocess_data_record();

        //And add 'Fwd:' to the message's subject line.
        $this->record->subject = get_string('fwd', 'block_quickmail').': '.$this->record->subject;
    }


    /**
     * Returns the "header" for the current page; which is used for breadcrumbs and the page header.
     * 
     * @return string   The header text which describes the function of the current page.
     */
    public function get_header_string() {
        return get_string('forward', 'block_quickmail');
    }




}

/**
 * An e-mail composition form which is designed to allow a student to e-mail their instructor.
 * 
 * @uses quickmail
 * @uses _email_composer
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_ask_instructor extends quickmail_email_composer
{


    /**
     * Stores an array of users who can recieve ask instructor messages; or null to indicate that the data has not yet been retrieved from the database.
     * Since requesting users with a capability is an expensive call, this data is only retrieved on request; and cached here thereafter. 
     *
     * Use get_ask_instructor_users() to read the value of this variable instead.
     * 
     * @var array
     */
    protected $instructors = null;

    /**
     * Returns all users who should recieve Ask Instructor messages in the given context.
     * 
     * @return array            An array of user objects.
     */
    protected function get_ask_instructor_users()  {

        // If we don't already have a cached list of Ask Instructor instructors, then retrieve the user list from the database.
        if($this->instructors === null) {
            $this->instructors = get_users_by_capability($this->context, 'block/quickmail:recieveaskinstructor', 'u.id, u.firstname, u.lastname, u.email, u.mailformat');
        }

        // Return the list of instructors who should recieve Ask Instructor users in this context.
        return $this->instructors;
    }

    /**
     * Returns the "header" for the current page; which is used for breadcrumbs and the page header.
     * 
     * @return string   The header text which describes the function of the current page.
     */
    public function get_header_string() {

        return get_string('askinstructor', 'block_quickmail');
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
            'instructors' => $this->get_ask_instructor_users(), 
            'sigs' => array_map(function($sig) { return $sig->title; }, $this->signatures),
            'alternates' => array(), //$alternates
            'user' => $this->user,
            'parameters' => $this->request_parameters
        );

        // Create the e-mail composition form.
        $this->form = new ask_instructor_form(null, $data);

        // Initialize the Moodleform to the default data provided.
        $this->initialize_form();
    }


    /**
     * A function which will return true if and only if the e-mail has addressible recipients.
     * 
     * @access public
     * @return boolean  True iff the current composer can address recipients.
     */
    public function potential_recipients_exist() {

        // Return true if there is at least one user who should recieve Ask Instructor messages.
        return count($this->get_ask_instructor_users()) > 0;

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
        if(!quickmail::can_message_instructor($this->context, $this->user)) {
            throw new moodle_quickmail_permissions_exception('no_permission', 'block_quickmail');
        }
    }

    /**
     * Returns a collection of recipients who have been selected by the given user.
     * 
     * @param stdClass $data    The form-data to be parsed.
     * @return array            An array of user IDs
     */
    protected function get_recipients($data) {

        // In Ask Instructor mode, the recipients are always equal to the list of Ask Instructor users.
        return $this->get_ask_instructor_users();
    }

    /**
     * Prepares the given e-mail data for storage in the Moodle database.
     * Modifies the data object in place.
     * 
     * @return void
     */
    protected function prepare_for_save($data) {

        // Call the parent prepare-for-save to perform the core modifications...
        parent::prepare_for_save($data);

        // ... and then indicate that "ask instructor" messages should not be forwardable.
        $data->noforward = 1;
    }


}


/**
 * An e-mail composition form which is designed to allow a student to ask a question regarding a
 * Quiz question.
 * 
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class quickmail_email_composer_quiz_question extends quickmail_email_composer_ask_instructor
{

    /**
     * The Questions Usage By Activity that owns the quiz question about which this message is being written.
     * 
     * @var question_usage_by_activity
     */
    protected $quba;


    /**
     * The Question Attempt from which the user clicked "Ask Instructor".
     * 
     * @var question_attempt
     */
    protected $question_attempt;


    /**
     * The question definition for the question from which the user clicked "Ask Instructor". 
     * 
     * @var question_definition
     */
    protected $question;


    /**
     * An object representing the component which owns the relevant question. 
     * In most cases, the component will be a Quiz object.
     *
     * Since fetching the component requires the fetching of a whole database row, 
     * this field is populated on demand. It is recommended that you use get_component() instead.
     * 
     * @var mixed
     */
    protected $component = null;


    /**
     * The question number, for display. 
     * 
     * @var mixed
     */
    protected $number;



    /**
     * Creates a new QuickMail e-mail object. 
     * 
     * @param int $course_id        The course ID which this 
     * @param int $signature_id 
     * @return void
     */
    public function __construct($attempt_id, $number = -1, $request_parameters = array(), $user = null) {

        // Get a reference to the currently logged-in user; and the global database connection.
        global $DB, $USER;

        // Set the active user. If no user was provided, then use the currently logged-in user. 
        $this->user = ($user === null) ? $USER : $user;

        // Attempt to get data regarding the question attempt from the database.
        $record = $DB->get_record('question_attempts', array('id' => $attempt_id));

        // If we weren't able to load a valid question attempt record for the given ID, then print an error.
        if($record === false) {
            throw new moodle_quickmail_exception('not_valid_askinstructor_id', 'block_quickmail', $attempt_id);
        }

        // Load the question usage for the given attempt.
        $this->quba = question_engine::load_questions_usage_by_activity($record->questionusageid);

        // Ask the usage to fetch the question attempt...
        $this->question_attempt = $this->quba->get_question_attempt($record->slot);

        // ... and get the core question definition, as well.
        $this->question = $this->question_attempt->get_question();

        // Store the question number, which is used in the e-mail body.
        $this->number = $number;

        // Store the extra request parameters, which are added to any request.
        $this->request_parameters = $request_parameters;

        // And create the context in which this e-mail will be sent.
        $this->create_context();

        // Set the current course from the given context. 
        $this->set_course_by_context($this->context);

        // Verify that the active user has permission to send e-mails using QuickMail.
        $this->verify_permissions();

        // And load the current user's signatures.
        $this->load_signatures();
    }

     /**
     * Creates the local context field from the known question information.
     * 
     * @return void
     */
    protected function create_context() {

        // Get the context in which this question was asked, by "tracing up" the following chain:
        // We've already found the QUBA that owns the Question Attempt; the context for this question is the same context that owns the QUBA.
        $this->context = $this->quba->get_owning_context();

    }

    /**
     * Set the Course from which this e-mail is being sent via context.
     * 
     * @param context $context      The context from which the owning course will be set. If no context
     *                              is provided, the object's owning context will be used.
     * @return void
     */
    public function set_course_by_context($context = null) {

        // If no context was provided, use the context which owns this composer.
        if($context === null) {
            $context = $this->context;
        }

        // Attempt to get the course context which the $context belongs to.
        // If $context is a course context, it will be used.
        $course_context = $context->get_course_context();

        // Get the CourseID from the context, and use it to set the current course.
        $this->set_course_by_id($course_context->instanceid);
    }

    /**
     * returns an appropriate subject line for the ask instructor e-mail.
     * 
     * @param array $data   the data loaded from the core form. if not provided, the form data will be loaded automatically.
     * @return void
     */
    protected function get_subject_line($data = null) {

        // Unfortunately, we can't easily get the name of the activity from the QUBA;
        // and course modules don't have a standard naming convention. 
        //
        // We'll instead handle this on a case-by-case basis.
        switch($this->quba->get_owning_component()) {

            // If this QUBA is owned by a Quiz module...
            case 'mod_quiz':

                // ... use the quiz's information to derive a subject line.
                return $this->quiz_get_subject_line();

            // Otherwise, come up with a "best fit" subject line with the information we have:
            default:

                // If we have a valid number, then use it...
                if($this->number > 0) {
                    return quickmail::_s('question_number', $this->number);
                } 
                // ... otherwise, fall back on a generic "ask instructor" phrase. 
                else {
                    return quickmail::_s('question_no_number');
                }

        }

    }

    /**
     * Returns a reference to the component which owns the relevant question; or null if
     * this module doesn't have enough information to retrieve a component.
     * 
     * @return object   Any information retrieved regardint the given component. Used mostly by the per-component methods.
     */
    protected function get_component() {

        // If an existing component has been loaded, then 
        // return the pre-loaded value.
        if ($this->component !== null) {
            return $this->component;
        }


        // Unfortunately, we can't easily get the owning component of a QUBA.
        //
        // We'll instead handle this on a case-by-case basis.
        switch($this->quba->get_owning_component()) {

            // If this QUBA is owned by a Quiz module, get the releveant quiz component.
            case 'mod_quiz':
                return $this->quiz_get_component();

            // Otherwise, this module doesn't support retrieving or using the relevant component.
            // We'll return null, indicating that no component was able to be loaded.
            default:
                return null;
        }
    }


    /**
     * Returns the quiz component associatd with the given question. Expects this question is owned by a mod_quiz component;
     * an error will occur if this is not the case.
     *
     * This function will always query the database; it is recommended you use get_component instead.
     * 
     * @return quiz
     */
    private function quiz_get_component() {

        // Get a reference to the global database object.
        global $DB;

        // Get the ID of the quiz attempt from which this message is being sent.
        // Note that we match the 'uniqueid' column of the question_attmpts table with the owning QUBA's id.
        $quiz_id = $DB->get_field('quiz_attempts', 'quiz', array('uniqueid' => $this->quba->get_id()), MUST_EXIST);

        // Get a copy of the quiz details for the given quiz.
        $this->component = quiz::create($quiz_id, $this->user->id); 

        //Return a copy of the quiz component.
        return $this->component;

    }

    /**
     * Returns an appropriate subject line for an Ask Instructior message regarding
     * a quiz question.
     * 
     * @return void
     */
    private function quiz_get_subject_line() {

        // Get a reference to the global database object.
        global $DB;

        // Get a copy of the quiz details for the given quiz.
        $quiz = $this->get_component();

        // Add the quiz name to the subject line...
        $subject_line =  $quiz->get_quiz_name();

        // ... and, if a question number is provided, use it as well:
        if($this->number >= 0) {
            $subject_line .= ', '.quickmail::_s('question_number', $this->number);
        }

        // Return the completed subject line.
        return $subject_line;

    }


    /**
     * Creates a MoodleForm which will represent the e-mail message to be sent.
     * 
     * @return void
     */
    protected function create_message_form() {

        // Gather the data which will be passed to the e-mail composition form.
        // TODO: Pass it a reference to the current object instead?
        $data = array(
            'editor_options' => $this->get_compose_editor_options(),
            'instructors' => $this->get_ask_instructor_users(), 
            'subject' => $this->get_subject_line(),
            'sigs' => array_map(function($sig) { return $sig->title; }, $this->signatures),
            'user' => $this->user,
            'alternates' => array(), //$alternates
            'parameters' => $this->request_parameters,
            'nodraft' => true
        );

        // Create the e-mail composition form.
        $this->form = new ask_instructor_form(null, $data);

        // Initialize the Moodleform to the default data provided.
        $this->initialize_form();
    }

    /**
     * Returns an object which contains all of the form's defaults.
     * 
     * @return object   An object which contains all of the form's defaults; suitable for use with the relevant moodleform's set_data method.
     */
    protected function get_initial_form_values()
    {
        // Create a new empty object, which will story each of the form's defaults.
        $data = new stdClass();

        // And specify the base defaults: every field is empty, and the user's message format is the same as their preferred mail format.
        $data->id = null;
        $data->subject = '';
        $data->message = $this->get_quoted_question_summary();
        $data->mailto = '';
        $data->format = $this->user->mailformat;

        // Create copies of the following two fields in the format that Moodle expects.
        $data->messagetext = $data->message;
        $data->messageformat = $data->format;

        // Return the newly created defaults.
        return $data;
    }

    /**
     * Returns a summary of the question from which the user clicked "Ask Instructor",
     * quoted for inclusion in an e-mail.
     * 
     * @return string   A HTML snippet with the relevant question, formatted for inclusion in an e-mail.
     */
    protected function get_quoted_question_summary() {

        // Add several blank lines to separate the body text from the quoted question.
        $summary = html_writer::empty_tag('br');
        $summary = html_writer::empty_tag('br');
        $summary .= html_writer::empty_tag('br');
        $summary .= html_writer::empty_tag('br');

        // Get an origin "link", which desrbives the origin of the Ask Instructor e-mail.
        $summary .= $this->get_origin_link();

        // Quote the question text, for the instructor's reference:
        $summary .= html_writer::start_tag('blockquote', array('style' => 'border-left: 1px solid #999; color: #999; padding: 1em; margin: 0em 1em;'));
        $summary .= $this->get_question_summary();
        $summary .= html_writer::end_tag('blockquote');

        // And return the question enclosed in quotes.
        return $summary;
    }

    /**
     * Returns a URL which can be used to view a full version of the relevant question; intended
     * as a shortcut for the instructor.
     * 
     * @return moodle_url   A URL at which the relevant question can be viewed.
     */
    protected function quiz_get_review_url() {

        // Get a reference to the global database object.
        global $DB;

        // Determine the quiz_attempt which owns the given question by looking for the quiz attempt that owns this QUBA (Question Usage By Activity).
        $attempt_id = $DB->get_field('quiz_attempts', 'id', array('uniqueid' => $this->quba->get_id()), MUST_EXIST);

        // Load the quiz attempt object that owns this question.
        $attempt = quiz_attempt::create($attempt_id);

        // Return a URL which can be used to view the relevant question.
        return $attempt->review_url($this->question_attempt->get_slot());
    }


    /**
     * Gets the HTML code for a link to the question's origin; which should allow the instructor (and student)
     * to easily view the full question attempt.
     * 
     * @return string   A HTML snippet which describes the attempt and links to its origin.
     */
    protected function get_origin_link() {

        // Create a new paragraph with no lower margin, which will act as a label for the quoted summary.
        $link = html_writer::start_tag('p', array('style' => 'margin-bottom: 0px;'));

        // Depending on which component owns this question, we'll provide a varying origin link.
        switch($this->quba->get_owning_component())
        {

            // If this e-mail was created from a quiz question, get a link to that question:
            case 'mod_quiz':

                // Get the two parameters which are used to compose the "origin" text...
                $data = array(

                            // ... a link where the instructor/student can "review" (or continue) the question...
                            'link' => html_writer::link($this->quiz_get_review_url(), $this->get_subject_line()),

                            // ... and the date/time when the question was last attempted.
                            'time' => userdate($this->question_attempt->get_last_action_time())
                        );

                // Using these two parameters, build the "From a question..." text.
                $link .= quickmail::_s('origin_response', $data);
                break;

            // If we don't have a better way of determining the question's origin, add a short header that specifies
            // that this was taken from question text.
            default:
                $link .= quickmail::_s('origin_unknown');
                break;

        }

        // End the paragraph.
        $link .= html_writer::end_tag('p');

        // Return the link.
        return $link;
    
    }


    /**
     * Returns a HTML summary of the question from which the user clicked "Ask Instructor".
     * 
     * @return string   A cleaned HTML string which contains a summary of the relevant question.
     */
    protected function get_question_summary() {

        // Get a reference to the page which is currently using this composer.
        global $PAGE;

        // Create a set of question display options with all feedback hidden.
        // TODO: Perhaps show feedback according to the question's state?
        $display_options = new question_display_options();
        $display_options->hide_all_feedback();

        // Render _only_ the core question text and prompt. 
        // Here, we use only a single method of the question's renderer, rather than the attempt's render method;
        // as this allows us to render the question without its relevant UI chrome and controls.
        $question_renderer = $this->question->get_renderer($PAGE);
        $question_text = $question_renderer->formulation_and_controls($this->question_attempt, $display_options);

        // Clean the text, removing any relevant scripts so it's more safe for e-mail.
        return clean_text($question_text);

    }

    /**
     * Save a 'draft' record of an e-mail, which can later be resumed and sent.
     * 
     * @param int $existing_id      If provided, the draft with the given ID will be updated.
     * @param bool $update_form     If true, the form's data will be updated according to the modifications made during the send process- 
     *                              e.g. the appending of the signature. Be careful if you se this to false- as the internal file areas may not be properly updated.
     * @return void
     */
     public function save_draft($existing_id = null, $update_form = true) {

         // We won't allow drafts for Ask Instructor quiz questions, as this would allow students to save question questions when
         // they shouldn't be able to. (Ask Instr. can be disabled on a per-context level; but this allows it to be used in short-term quizzes.)
         throw new moodle_quickmail_exception('no_draft_allowed', 'block_quickmail');

     }
   

}
