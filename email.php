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
$sigid = optional_param('sigid', quickmail_email_composer::DEFAULT_SIGNATURE, PARAM_INT);
$number = optional_param('number', -1, PARAM_INT);

// Create an array of parameters which will be included in subsequent queries.
$request_parameters = array(
        'type' => $type,
        'typeid' => $typeid,
        'number' => $number
    );


// Create a new e-mail object according to which $type we've recieved.
switch($type)
{

    // If the "continue draft" option is selected, then continue a draft message.
    case quickmail_view_type::DRAFT:
        $composer = new quickmail_email_composer_from_draft($typeid, $request_parameters);
        break;

    // If the "foward" option is selected (which was called "log" in legacy QuickMail).
    case quickmail_view_type::SENT_MAIL:
    case quickmail_view_type::FORWARD:
        $composer = new quickmail_email_composer_forward($typeid, $request_parameters);
        break;

    // If the user has entered the page using an "Ask Instructor" link from a course page...
    case quickmail_view_type::ASK_INSTRUCTOR:
        $composer = new quickmail_email_composer_ask_instructor($courseid, $sigid, $request_parameters);
        break;

    // If the user has entered the page via an "Ask Instructor" link in a quiz question...
    case quickmail_view_type::ASK_QUIZ_QUESTION:
        $composer = new quickmail_email_composer_quiz_question($typeid, $number, $request_parameters);
        break;

    // If no other applicable view has been selected, use the normal "compose" view. 
    default:

        // Create a composer according to the user's permission level- this will either create a new selectable-recipient composer, 
        // an ask-instructor style composer.
        $composer = quickmail_email_composer::create_according_to_permissions($courseid, $sigid, $request_parameters, null, true);
        break;

}

// Get a quick reference to the current plugin name and header string...
$blockname = quickmail::_s('pluginname');
$header = $composer->get_header_string();

// ... and set up the report page.
$PAGE->set_course($composer->get_owning_course());
$PAGE->set_context($composer->get_course_context());
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


// If we just finished successfully sending an e-mail message...
if (empty($errors) && $composer->send_requested()) {

        // ... then redirect the user to their "outbox". 
        redirect($composer->get_success_destination());
} 



// Render the page header...
echo $output->header();
echo $output->heading($blockname.': '.$header);

// If the user requested that changes be saved as a draft...
if (empty($errors) && $composer->save_requested()) {

        // ... render the "changes saved" message and continue.
        $output->render_draft_saved_notification();

}

// Render the list of errors that occurred during e-mail sending.
$output->render_email_send_errors($errors);

// Render the core e-mail send form.
$output->render_email_send_form($composer->get_compose_form());

// ... and render the page footer.
echo $output->footer();
