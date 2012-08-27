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


// Written at Louisiana State University

require_once($CFG->libdir . '/formslib.php');

/**
 * QuickMail form for composing e-mail.
 * 
 * @uses moodleform
 * @package block_quickmail 
 * @version $id$
 * @copyright 2011, 2012 Louisiana State University
 * @copyright 2011, 2012 Binghamton University
 * @author Louisiana State University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class email_form extends moodleform {

    /**
     * Format the user's data for display in the "select recipients" box.
     * 
     * @param stdClass $user    The user whose data is to be formatted.
     * @return string           A string which contains the user any any groups that user is in.
     */
    protected function format_user_name($user) {

        // Get a refernce to the local user => group mapping.
        $users_to_groups =& $this->_customdata['users_to_groups'];

        // If the user isn't in any group...
        if (empty($users_to_groups[$user->id])) {

            // ... identify them as "not in a section"
            $groups = quickmail::_s('no_section');

        // Otherwise, sort them by their section:
        } else {

            // Create a quick "lambda" which will extract the group names.
            $only_names = function($group) { return $group->name; };

            // And use it to get a comma-separated list of groups the user is in.
            $groups = implode(',', array_map($only_names, $users_to_groups[$user->id]));
        }

        // Return a string that identifies both the user, and any groups that that user is in.
        return fullname($user).' ('.$groups.')';
    }

    /**
     * Format the user's data such that it can be used as the value "key" for an option box.
     * 
     * @param mixed $user 
     * @return void
     */
    private function format_user_details($user) {

        // Get a reference to two arrays, which map users => groups and users => roles, respectively.
        $users_to_groups =& $this->_customdata['users_to_groups'];
        $users_to_roles =&  $this->_customdata['users_to_roles'];

        // Create a quick "lambda" which will extract the _short_ names for teach of the roles...
        $only_sn = function($role) { return $role->shortname; };

        // ... and use it to get a comma separated list of roles for the given user.
        $roles = implode(',', array_map($only_sn, $users_to_roles[$user->id]));

        // Add the user to the "no filter" group, which should contain everyone.
        $roles .= ',none';

        // If the user isn't in any groups, indicate this to the JavaScript by setting "groups" to 0.
        if (empty($users_to_groups[$user->id])) {
            $groups = 0;

        // Otherwise, format the groups so the JS can use it.
        } else {

            // This quick "lambda" allows us to get only the group ID for a given group.
            $only_id = function($group) { return $group->id; };

            // Create a string which contains a list of groups that the user is a member of...
            $groups = implode(',', array_map($only_id, $users_to_groups[$user->id]));

            // ... and add each user to the "all" group, which contains all users.
            $groups .= ',all';
        }

        // And return a string that contains the user's ID, groups and roles.
        return $user->id.' '.$groups.' '.$roles;
    }

    /**
     * Define the values compose the user form.
     * 
     * @return void
     */
    public function definition() {

        // Get a reference to the current user and current course;
        global $USER, $COURSE;

        // Get an easy reference to the form to be populated.
        $mform =& $this->_form;

        // Include the current user's ID and the course ID.
        $mform->addElement('hidden', 'userid', $USER->id);
        $mform->addElement('hidden', 'courseid', $COURSE->id);

        // Pass through teach of the request parameters included by the parent e-mail composer.
        foreach($this->_customdata['parameters'] as $name => $value) {
            $mform->addElement('hidden', $name, $value);
        }

        // Add the main components of the form.
        $this->add_history_links();
        $this->add_mail_from();
        $this->add_mail_to();
        $this->add_message_body();
        $this->add_quickmail_action_buttons();

    }

    /**
     * Add the "submit"-style action buttons for this form.
     * 
     * @return void
     */
    protected function add_quickmail_action_buttons() {

        // Get a quick reference to the core form.
        $mform =& $this->_form;

        // Add the submit and cancel buttons to a custom group...
        $buttons = array();
        $buttons[] =& $mform->createElement('submit', 'send', quickmail::_s('send_email'));
        $buttons[] =& $mform->createElement('submit', 'draft', quickmail::_s('save_draft'));
        $buttons[] =& $mform->createElement('cancel');

        // ...and add the group to the form.
        $mform->addGroup($buttons, 'buttons', quickmail::_s('actions'), array(' '), false);

    }


    /**
     * Gets a list of groups, which the user can use to filter message recipients.
     * The values returned by this function are determined by value of the "groups" option when this form is created.
     * 
     * @return array    An associtive array of group id => group name for each of the relevant groups; plus two special groups "all" and 0 (not in a group).
     */
    protected function get_potential_groups() {

        // If "filter by group" is off, or no groups exist...
        if (empty($this->_customdata['groups'])) {

            // ...initialize the group to an empty array.
            $group_options = array();

        // Otherwise, build a "filter by group" select box.
        } else {

            // Always add an "all sections" group as the first element.
            $group_options = array('all' => quickmail::_s('all_sections'));

            // Add each of the existing groups to the "potential sections" dialog box.
            foreach ($this->_customdata['groups'] as $group) {
                $group_options[$group->id] = $group->name;
            }

        }

        // Always add a "no section" element to the _end_ of the array.
        //
        // (Note that PHP's array behavior means that though this has the lowest index,
        // it's at the end of the array, as it was most recently added.)
        $group_options[0] = quickmail::_s('no_section');

        // Return the newly created list of groups.
        return $group_options;
    }

    /**
     * Gets a list of users to which the current user can send QuickMail messages.
     * The format of the user's name and details are deteremined by the format_user_name() and format_user_details() methods respectively. 
     * 
     * @return array    An associative array of user details => formatted user name.
     */
    protected function get_potential_users() {

        // Create an empty array of user details...
        $user_options = array();

        // .. and add the formatted details and name for each user.
        foreach ($this->_customdata['users'] as $user) {
            $user_options[$this->format_user_details($user)] = $this->format_user_name($user);
        }

        // Return the newly created array of user information.
        return $user_options;

    }

    /**
     * Gets a list of rolls which the display can be filtered by.
     * 
     * @return array    An associative array of (role short name) => (role full name).
     */
    protected function get_role_filters() {

        // Create an roles array, which initially contains only the "no filter" selector.
        $role_options = array('none' => quickmail::_s('no_filter'));

        // And add any roles which the user's preferences allow filtering by.
        foreach ($this->_customdata['roles'] as $role) {
            $role_options[$role->shortname] = $role->name;
        }

        // Return the newly created array of role filters.
        return $role_options;
    }

    /**
     * Gets a URL for the e-mail "log" page, which displays the user's send messages and saved drafts. 
     * 
     * @param string $type  The access type, which can be "log" (for sent messages) or "drafts" (for saved but not sent messages).
     * @return moodle_url   The URL of the target page.
     */
    protected function get_history_url($type) {

        // Get a reference to the current course.
        global $COURSE;

        // Build a list of GET parameters for the history URL...
        $email_param = array('courseid' => $COURSE->id, 'type' => $type);

        // ...and return a Moodle URL which points to the history page.
        return new moodle_url('emaillog.php', $email_param);
    }

    /**
     * Gets a HTML link to the e-mail "log" page, which displays the user's send messages and saved drafts. 
     * 
     * @param string $type          The access type, which can be "log" (for sent messages) or "drafts" (for saved but not sent messages).
     * @param string $string_name   The name of the quickmail language string to be used for the link. If null (or not provided), the value of $type will be used.
     * @return void
     */
    protected function get_history_link($type, $string_name = null) {

        // If no string was provided, use the default link text for the type.
        if ($string_name === null) {
            $string = quickmail::_s($type);

        // Otherwise, get the requested language string.
        } else {
            $string = quickmail::_s($string_name);
        }

        // Return a link to the history page.
        return html_writer::link(self::get_history_url($type), $string);
    }

    /**
     * Adds the "draft" and "history" links to the current Moodle form.
     * 
     * @return void
     */
    protected function add_history_links() {

        // Get a quick reference to the current Moodleform.
        $mform =& $this->_form;

        // And create a empty array of link objects to be added.
        $links = array();

        // Add a link to the user's collection of drafts.
        $links[] =& $mform->createElement('static', 'draft_link', '', self::get_history_link('drafts'));

        // And, if the user can send messages, add a link to the messages they've sent.
        if (quickmail::can_send_to_course_participants()) {
            $links[] =& $mform->createElement('static', 'history_link', '', self::get_history_link('log', 'history'));
        }

        // Add the group of links to the form.
        $mform->addGroup($links, 'links', '&nbsp;', array(' | '), false);

    }

    /**
     * Create an interactive button, for JavaScript use.
     * 
     * @param string $id    The button's ID, which can be used to reference it via JavaScript.
     * @param string $text  The button's text. If no text is provided, the language string for the ID will be used.
     * @return string       The HTML code for the button.
     */
    protected function create_interactive_button($id, $text = null) {

        // If no button text was provided, use the language string for the button's ID.
        if ($text === null) {
            $text = quickmail::_s($id);
        }

        // Create the button object.
        $button = html_writer::empty_tag('input', array('value' => $text, 'type' => 'button', 'id' => $id));

        // Wrap the button in a paragraph, and then return it.
        return html_writer::tag('p', $button);
    }

    /**
     * Get the cell for the selection controls, which allow the user to add/remove users as recipients
     * of the e-mail.
     * 
     * @return html_table_cell  A table cell that contains the main add/remove buttons for the "to" select.
     */
    protected function get_selection_buttons() {

        // Get a reference to the global output object, which handles the page rendering.
        global $OUTPUT;

        // Add the center add/remove buttons. 
        $center_buttons = new html_table_cell();
        $center_buttons->text = 
            self::create_interactive_button('add_button', $OUTPUT->larrow() . ' ' . quickmail::_s('add_button')) .
            self::create_interactive_button('remove_button', quickmail::_s('remove_button') . ' ' . $OUTPUT->rarrow()) .
            self::create_interactive_button('add_all') .
            self::create_interactive_button('remove_all');

        // Return the newly-created buttons.
        return $center_buttons;

    }

    /**
     * Get the selection filters, which allow control over which users are recipients of a given e-mail.
     * 
     * @return html_table_cell  A table cell that contains the filter dialogs and user list.
     */
    protected function get_selection_filters() {

        // Get an array of potential users and groups for the "target selection" form, and get a list of roles 
        // we can filter them by.
        $group_options = self::get_potential_groups();
        $user_options = self::get_potential_users();
        $role_options = self::get_role_filters();

        // Add the filters, which allow us to select which users the e-mail will be sent to.
        $filters = new html_table_cell();
        $filters->text =
            html_writer::tag('div', html_writer::select($role_options, '', 'none', null, array('id' => 'roles'))) .
            html_writer::tag('div', quickmail::_s('potential_sections'), array('class' => 'object_labels')) .
            html_writer::tag('div', html_writer::select($group_options, '', 'all', null, array('id' => 'groups', 'multiple' => 'multiple', 'size' => 5))) .
            html_writer::tag('div', quickmail::_s('potential_users'), array('class' => 'object_labels')) . 
            html_writer::tag('div', html_writer::select($user_options, '', '', null, array('id' => 'from_users', 'multiple' => 'multiple', 'size' => 20)));

        // Return the newly created filter selects.
        return $filters;
    }

    /**
     * Get the recipients box, which stores the current recipients of a given e-mail.
     * 
     * @return html_table_cell  A table cell which contains the recipients box.
     */
    protected function get_recipients_box()
    {
        // Add the main box for the "selected recipients"
        $select_filter = new html_table_cell();

        // HACK alert! The original LSU code breaks paradigm by passing in a selected() array, 
        // which conatins the postdata from the _mailto_ hidden element. 
        //
        // In order to make this _appear_ more paradigmatic, I'm reading the postdata inside of the MoodleForm- 
        // this will make the MoodleForm appear to be working normally, despite LSU's legacy jQuery hackishness. 
        //
        // TODO: Replace me with something more paradigmatic- perhaps a progressive enhancement select box?

        //<hack>

        // Create an array of currently-selected users.
        $selected = array();

        // Compute the "default value", which will be used if no post-data is available.
        $default_selected = !empty($this->_customdata['mailto']) ? $this->_customdata['mailto'] : '';

        // Get the value of the submitted "mailto" post-variable.
        $mail_to = optional_param('mailto', $default_selected, PARAM_TEXT);

        // If values were previously seleced, add them to the selected array.
        if(!empty($mail_to)) {

            // And convert it into an array of User IDs.
            $selected_ids = explode(',', $mail_to);

            // For each of the selected users, move the user object from the "user pool" to the "selected" pool.
            foreach($selected_ids as $id) {

                // If the user is able to e-mail the given user...
                if(array_key_exists($id, $this->_customdata['users'])) {

                    // Add the user to the selected pool...
                    $selected[$id] = $this->_customdata['users'][$id];

                    // ... and remove them from the user pool.
                    unset($this->_customdata['users'][$id]);
                   
                }
            }
        }

        // </endhack>


        // Build an array of array of users who are currently selected; this is used to restore from drafts.
        // This replaces an array_reduce from the original QuickMail, which was a lot slower.
        $user_list = '';
        foreach($selected as $user) {
            $user_list .= '<option value="'.$this->format_user_details($user).'">'. $this->format_user_name($user).'</option>';
        }

        // Create the select tag, which lists each of the currently selected users.
        $select_filter->text = html_writer::tag('select', $user_list, array('id' => 'mail_users', 'multiple' => 'multiple', 'size' => 30));

        // And return the filter cell.
        return $select_filter;
    }

    /**
     * Returns the interface labels for the recipient select controls.
     * 
     * @return html_table_row   A table row which contains the labels for each of the recipient select controls.
     */
    private function user_select_interface_labels() {

        global $OUTPUT;

        // Get the HTML code which is used to indicate to the user that a form is required.
        $req_img = html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('req'), 'class' => 'req'));

        // Create the "Selected Recipients" Label"
        $selected_required_label = new html_table_cell();
        $selected_required_label->text = html_writer::tag('strong', quickmail::_s('selected') . $req_img, array('class' => 'required'));

        // Add a label, which indicates the start of the "role filter"
        $role_filter_label = new html_table_cell();
        $role_filter_label->colspan = "2";
        $role_filter_label->text = html_writer::tag('div', quickmail::_s('role_filter'), array('class' => 'object_labels'));

        // Create the interface labels row, and return it.
        return new html_table_row(array($selected_required_label, $role_filter_label));

    }

    /**
     * Returns the interface elements for the recipient select controls.
     * 
     * @return html_table_row   A table row which contains the core interface elements for the recipient select controls.
     */
    private function user_select_core_interface() {

        // Create a new table row, which contains the target recipients.
        //
        return new html_table_row(
            array(
                self::get_recipients_box(),
                self::get_selection_buttons(),
                self::get_selection_filters()
            )
        );

    }

    protected function add_mail_to()
    {
        // Get a quick reference to the active form.
        $mform =& $this->_form;

        // Add a hidden "target users" item, which will store the target user's/users' information.
        // This field will be automatically populated by JavaScript.
        $mform->addElement('hidden', 'mailto', '');

        // Start a new table, which will house the "user select" form.
        $table = new html_table();
        $table->attributes['class'] = 'emailtable';

        // Render the core user selection table.
        $table->data[] = self::user_select_interface_labels();
        $table->data[] = self::user_select_core_interface();

        // Render the "user select" interface. 
        $mform->addElement('static', 'selectors', '', html_writer::table($table));
    }

    /**
     * Add the send address controls. 
     * 
     * @return void
     */
    protected function add_mail_from()
    {
        // Get a reference to the current user.
        global $USER;

        // Get a quick reference to the active form.
        $mform =& $this->_form;

        // If the user is allowed to send from an alternate address...
        if (quickmail::can_send_from_alternate_address()) {

            // ... then load their alternate addresses.
            $alternates = $this->_customdata['alternates'];

        // Otherwise, use an empty array as their list of alternates. 
        } else {
            $alternates = array();
        }

        // If the user has no alternate addresses that they're _allowed_ to use.. 
        // TODO: remove
        if (empty($alternates)) {

            // .. automatically specify the from address as their primary e-mail.
            $mform->addElement('static', 'from', quickmail::_s('from'), $USER->email);

        // Otherwise, allow them to select from a list of alternates...
        } else {

            //... which should consist of their Moodle e-mail, plus their alternates. 
            $options = array_merge(array(0 => $USER->email), $alternates);

            // Display a drop-down menu with the user's alternate addresses.
            $mform->addElement('select', 'alternateid', quickmail::_s('from'), $options);
        }
    }

    protected function add_message_body() {

        // Get a reference to the active course.
        global $COURSE;
        
        // Get a quick reference to the active form.
        $mform =& $this->_form;

        // Get a reference to the current per-course configuration
        $config = quickmail::load_config($COURSE->id);

        // Get a quick reference to the active form.
        $mform =& $this->_form;

        // Add the an input for the subject. 
        $mform->addElement('text', 'subject', quickmail::_s('subject'), array('style' => 'width: 100%;'));

        // And ensure that a valid text response is provided.
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required');

        // Add a rich text editor, which is used to specify the contents of the e-mail.
        $mform->addElement('editor', 'message_editor', quickmail::_s('message'), array('style' => 'width: 100%;') , $this->_customdata['editor_options']);

        // And add a fire manager, which will allow the user to upload attachments.
        $mform->addElement('filemanager', 'attachments', quickmail::_s('attachment'));

        // If the user has a signature available, show the siganature select box.
        if(!empty($this->_customdata['sigs']))  {

            // Get a list of signatures that the user can use with a "no signature" option appended.
            $options = array_merge($this->_customdata['sigs'],  array(-1 => quickmail::_s('nosig')));

            // And display it to the user as a drop-down box.
            $mform->addElement('select', 'sigid', quickmail::_s('signature'), $options);

        } 
        // Otherwise, just add a hidden "no signature" element.
        else {
            $mform->addElement('hidden', 'sigid', '-1');
        }


        // Create a yes/no radio button, which will allow the user to select if they want to recieve a copy.
        $radio = array(
            $mform->createElement('radio', 'receipt', '', get_string('yes'), 1),
            $mform->createElement('radio', 'receipt', '', get_string('no'), 0)
        );

        // Add a group, which will allow the yes/no select to appear inline.
        $mform->addGroup($radio, 'receipt_action', quickmail::_s('receipt'), array(' '), false);

        // Add a help description to the "recieve a copy" radio select.
        $mform->addHelpButton('receipt_action', 'receipt', 'block_quickmail');

        // And select the default according tot he core configuration.
        $mform->setDefault('receipt', !empty($config['receipt']));
    }
}
