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
require_once('email_form.php');

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
$courseid = required_param('courseid', PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHA);
$typeid = optional_param('typeid', 0, PARAM_INT);
$sigid = optional_param('sigid', 0, PARAM_INT);
$using_ajax = optional_param('ajax', 0, PARAM_BOOL);

// If the course ID wasn't valid...
if (!$course = $DB->get_record('course', array('id' => $courseid))) {

    // ... print the "invalid course" error message.
    print_error('no_course', 'block_quickmail', '', $courseid);
}

// If an invalid type was specified...
if (!empty($type) and !in_array($type, array('log', 'drafts'))){

    // ... display an appropirate error message.
    print_error('no_type', 'block_quickmail', '', $type);
}

// If a type was provided, but no type ID, then display an error message.
if (!empty($type) and empty($typeid)) {

    // Gather the data for the error message...
    $string = new stdclass;
    $string->tpe = $type;
    $string->id = $typeid;

    // ... and then display it to the user.
    print_error('no_typeid', 'block_quickmail', '', $string);
}

// Load the QuickMail configuration for the current course into the $localconfig object.
$qm_config = quickmail::load_config($courseid);

// Fetch the current course context.
$context = get_context_instance(CONTEXT_COURSE, $courseid);

// Determine if the given user should be able to send mail.
// FIXME: Use the user's capabilities instead of this configuration object.
$has_permission = ( has_capability('block/quickmail:cansend', $context) or !empty($qm_config['allowstudents']));

// If the user doesn't have permission to send mail, then print an error message and quit.
if (!$has_permission) {
    print_error('no_permission', 'block_quickmail');
}

//get a list of mail signatures for the current user
$sigs = quickmail::get_user_signatures();

$alt_params = array('courseid' => $course->id, 'valid' => 1);
$alternates = $DB->get_records_menu('block_quickmail_alternate', $alt_params, '', 'id, address');

$blockname = quickmail::_s('pluginname');
$header = quickmail::_s('email');

// If we're not in AJAX-view, set up the Moodle page.
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->navbar->add($blockname);
$PAGE->navbar->add($header);
$PAGE->set_title($blockname . ': '. $header);
$PAGE->set_heading($blockname . ': '.$header);
$PAGE->set_url('/course/view.php', array('courseid' => $courseid));
$PAGE->set_pagetype($blockname);

//TODO: replace jquery with the local plugin
$PAGE->requires->js('/blocks/quickmail/js/jquery.js');
$PAGE->requires->js('/blocks/quickmail/js/selection.js');


$course_roles = get_roles_used_in_context($context);

$filter_roles = $DB->get_records_select('role',
    sprintf('id IN (%s)', $qm_config['roleselection']));

$roles = quickmail::filter_roles($course_roles, $filter_roles);

$allgroups = groups_get_all_groups($courseid);

$mastercap = true;
$groups = $allgroups;

if (!has_capability('moodle/site:accessallgroups', $context)) {
    $mastercap = false;
    $mygroups = groups_get_user_groups($courseid);
    $gids = implode(',', array_values($mygroups['0']));
    $groups = empty($gids) ?
        array() :
        $DB->get_records_select('groups', 'id IN ('.$gids.')');
}

$globalaccess = empty($allgroups);

// Fill the course users by
$users = array();
$users_to_roles = array();
$users_to_groups = array();

$everyone = get_role_users(0, $context, false, 'u.id, u.firstname, u.lastname, u.email, u.mailformat, u.maildisplay, r.id AS roleid', 'u.lastname, u.firstname');

foreach ($everyone as $userid => $user) {
    $usergroups = groups_get_user_groups($courseid, $userid);

    $gids = ($globalaccess or $mastercap) ?
        array_values($usergroups['0']) :
        array_intersect(array_values($mygroups['0']), array_values($usergroups['0']));

    $userroles = get_user_roles($context, $userid);
    $filterd = quickmail::filter_roles($userroles, $roles);

    // Available groups
    if ((!$globalaccess and !$mastercap) and
        empty($gids) or empty($filterd) or $userid == $USER->id)
        continue;

    $groupmapper = function($id) use ($allgroups) { return $allgroups[$id]; };

    $users_to_groups[$userid] = array_map($groupmapper, $gids);
    $users_to_roles[$userid] = $filterd;
    $users[$userid] = $user;
}

if (empty($users)) {
    print_error('no_users', 'block_quickmail');
}

if (!empty($type)) {
    $email = $DB->get_record('block_quickmail_'.$type, array('id' => $typeid));
} else {
    $email = new stdClass;
    $email->id = null;
    $email->subject = optional_param('subject', '', PARAM_TEXT);
    $email->message = optional_param('message_editor[text]', '', PARAM_RAW);
    $email->mailto = optional_param('mailto', '', PARAM_TEXT);
    $email->format = $USER->mailformat;
}
$email->messageformat = $email->format;
$email->messagetext = $email->message;

$default_sigid = $DB->get_field('block_quickmail_signatures', 'id', array(
    'userid' => $USER->id, 'default_flag' => 1
));
$email->sigid = $default_sigid ? $default_sigid : -1;

// Some setters for the form
$email->type = $type;
$email->typeid = $typeid;

$editor_options = array(
    'trusttext' => true,
    'subdirs' => true,
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'context' => $context,
);

$email = file_prepare_standard_editor($email, 'message', $editor_options, $context, 'block_quickmail', $type, $email->id);

$selected = array();
if (!empty($email->mailto)) {
    foreach (explode(',', $email->mailto) as $id) {
        $selected[$id] = $users[$id];
        unset($users[$id]); }
}

$data = 
array(
    'editor_options' => $editor_options,
    'selected' => $selected,
    'users' => $users,
    'roles' => $roles,
    'groups' => $groups,
    'users_to_roles' => $users_to_roles,
    'users_to_groups' => $users_to_groups,
    'sigs' => array_map(function($sig) { return $sig->title; }, $sigs),
    'alternates' => $alternates
);
$form = new email_form(null, $data);


// If the form was cancelled, then redirect to the QuickMail view screen.
if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php?id='.$courseid));
}

// Start a list of errors for the user.
$errors = array();


// Otherwise, get the data currently associated with the message form.
$data = $form->get_data();

// If we were able to parse valid data from the from...
if ($data) {

    // If no subject was provided, queue a warning for the user.
    if (empty($data->subject)) {
        $errors[] = get_string('no_subject', 'block_quickmail');
    }

    // If no destination was provided, queue a warning for the user.
    if (empty($data->mailto)) {
        $errors[] = get_string('no_users', 'block_quickmail');
    }

    // If no errors occurred, then attempt to send the message.
    if (empty($errors)) {

        // Send e-mail message from the form data.
        $errors = quickmail::send_message_from_form_data($data, $everyone, $editor_options, $context, $type);

        // ??? 
        //$email = $data;
    }
}

// If the message was sent without attachments and _wasn't_ an existing draft or history item,
// create a new draft area for attachments.
if (empty($data->attachments) && !empty($type)) {

    // Get the draft item ID associated with the attachment file manager, if files have already been uploaded.
    $attachid = file_get_submitted_draft_itemid('attachment');

    // And create a new draft file area for the files, if necessary.
    file_prepare_draft_area($attachid, $context->id, 'block_quickmail', 'attachment_' . $type, $typeid);

    // If we just created a draft file area for the files, associate it with the form.
    $data->attachments = $attachid;
}

// Re-load the form to correspond to the e-mailed data.
$form->set_data($data);

// If no errors occurred, then redirect the user to the "sent messages" box.
if (empty($errors)) {

    if (isset($data->send)) {

        redirect(new moodle_url('/blocks/quickmail/emaillog.php', array('courseid' => $course->id)));

    } else if (isset($data->draft)) {

        $warnings['success'] = get_string("changessaved");

    }
}

// If we're not using QuickMail from an AJAX div, then output the header.
if(!$using_ajax) { 
    echo $OUTPUT->header();
    echo $OUTPUT->heading($blockname);
}

foreach ($errors as $type => $error) 
{
    $class = ($type == 'success') ? 'notifysuccess' : 'notifyproblem';
    echo $OUTPUT->notification($warning, $class);
}

echo html_writer::start_tag('div', array('class' => 'no-overflow'));
$form->display();
echo html_writer::end_tag('div');


// If we're not using QuickMail from an AJAX div, then output the footer.
if(!$using_ajax) { 
    echo $OUTPUT->footer();
}
