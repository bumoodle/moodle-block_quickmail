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

// Require the main Moodle configuration, which, in turn, integrates this page into Moodle.
require_once('../../config.php');

// Require the local library functions, and the core e-mail form.
require_once('lib.php');
require_once('emaillib.php');
require_once('renderer.php');

require_once('email_form.php');
require_once('ask_instructor_form.php');

// Only allow logged in users to use QuickMail.
require_login();

// Get the optional and required GET parameters:
//
// Course ID: the course for which this message is being sent. This is used for two purposes:
// - Instructors are limited to sending within the current course.
// - Students with the Ask Instructor permission are limited to messaging their course instructor.
//
// Type: the view to display. This can be one of three options:
// - (empty):   The send new e-mail screen will be displayed.
// - 'log':     An e-mail from the user's history will be displayed; and typeid is required.
// - 'drafts':  An e-mail from the user's draft collection will be displayed; typeid is required.
$courseid = optional_param('courseid', -1, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);
$typeid = optional_param('typeid', 0, PARAM_INT);
$sigid = optional_param('sigid', 0, PARAM_INT);
$using_ajax = optional_param('ajax', 0, PARAM_BOOL);
$id = optional_param('id', 0, PARAM_INT);

// Create a new e-mail object according to which $type we've recieved.
switch($type)
{
    default:
        $composer = new quickmail_email_composer_selectable($courseid, $sigid);

}

// Get a quick reference to the current plugin name and header string...
$blockname = quickmail::_s('pluginname');
$header = quickmail::_s('email');

// ... and set up the report page.
//TODO: abstract the header name to the composer object?
$PAGE->set_course($composer->get_owning_course());
$PAGE->set_context($composer->get_owning_context());
$PAGE->navbar->add($blockname);
$PAGE->navbar->add($header);
$PAGE->set_title($blockname . ': '. $header);
$PAGE->set_heading($blockname . ': '.$header);
$PAGE->set_url('/course/view.php', array('courseid' => $courseid));
$PAGE->set_pagetype($blockname);


// Get the main output renderer for e-mail objects.
$output = $PAGE->get_renderer('block_quickmail_email');

// If no recipients exist for this e-mail, throw an error.
if (!$composer->potential_recipients_exist()) {
    $output->render_no_user_message();
}

// If the form was cancelled, then redirect to the QuickMail view screen.
if ($composer->cancel_requested()) {
   $output->render_email_cancel_handler($composer);
}


// If we're creating a new message from a sent message, or resuming a draft...
//if (in_array($type, array(quickmail_view_type::SENT_MAIL, quickmail_view_type::DRAFTS))) {

    // ... then load the current message from the database.
//    $email = $DB->get_record('block_quickmail_'.$type, array('id' => $typeid));

// Or, if we're using the ask instructor feature, populate the e-mail data from the specified type_id.
//} elseif ($type === quickmail_view_type::ASK_INSTRUCTOR) {

    //TODO


// Some setters for the form

/** TODO
$email->type = $type;
$email->typeid = $typeid;
*/


// Create a new array which will store any errors which occur during send.
$errors = array();

try {

    // If the user has requested that this message be sent...
    if($composer->send_requested()) {

        // ... attempt to send the message.
        $errors = $composer->send_message();
    }

    // Otherwise, if the user has requested that the messaged be saved as a draft...
    if($composer->save_requested()) {

        // ... save a draft.
        $errors = $composer->save_draft();
    }
        

} 
//If any errors have occured during the transmission process...
catch(moodle_quickmail_exception $e) {

    // ... add them to the error list.
    $errors[] = $e->getMessage();
}


// If no errors occured, then finish the form submission action.
if (empty($errors)) {

    // If we just finished sending an e-mail, 
    if ($composer->send_requested()) {

        // ... then redirect the user to their "outbox". 
        redirect($composer->get_success_destination());

    } 
    // Otherwise, if the user requested that changes be saved as a draft...
    elseif ($composer->save_requested()) {

        // ... render the "changes saved" message and continue.
        $this->render_draft_change_saved_message();

    }
}

// Render the page header...
echo $output->header();
echo $output->heading($blockname);

// Render the list of errors that occurred during e-mail sending.
$output->render_email_send_errors($errors);

// Render the core e-mail send form.
$output->render_email_send_form($composer->get_compose_form());

// ... and render the page footer.
echo $output->footer();
