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


require_once($CFG->libdir . '/formslib.php');

/**
 * Per-course configuration options for QuickMail
 * 
 * @uses moodleform
 * @package block_quickmail 
 * @version $id$
 * @copyright 2011, 2012 Louisiana State University
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class config_form extends moodleform {


    public function definition() {

        global $COURSE;

        $mform =& $this->_form;

        /**
         * Deprecated; should be removed soon.
        $mform->addElement('select', 'allowstudents', quickmail::_s('allowstudents'), $student_select);
         */
        $mform->addElement('hidden', 'allowstudents', true);

        // Add an multi-select box which will allow the instructor to select the roles to filter by.
        $roles =& $mform->addElement('select', 'roleselection', quickmail::_s('select_roles'), $this->_customdata['roles']);
        $roles->setMultiple(true);
        $mform->addRule('roleselection', null, 'required');

        // Allow the instructor to select whether the course name or ID number (CRN) should be prefixed on each e-mail.
        $options = array(
            0 => get_string('none'),
            'idnumber' => get_string('idnumber'),
            'shortname' => get_string('shortname')
        );
        $mform->addElement('select', 'prepend_class', quickmail::_s('prepend_class'), $options);

        // Allow the instructor to determine if a user should recieve a copy of his/her own e-mail by default 
        $yes_no = array(0 => get_string('no'), 1 => get_string('yes'));
        $mform->addElement('select', 'receipt', quickmail::_s('receipt'), $yes_no);

        // Add a link which will allow the user to restore the system defaults.
        $reset_link = html_writer::link( new moodle_url('/blocks/quickmail/config.php', array( 'courseid' => $this->_customdata['courseid'], 'reset' => 1)), quickmail::_s('reset'));
        $mform->addElement('static', 'reset', '', $reset_link);

        //Add a submit button
        $mform->addElement('submit', 'save', get_string('savechanges'));
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);

    }
}
