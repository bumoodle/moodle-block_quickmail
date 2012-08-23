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
 * block_quickmail_email_renderer 
 * 
 * @uses plugin
 * @uses _renderer_base
 * @package 
 * @version $id$
 * @copyright 2011, 2012 Binghamton University
 * @author Kyle Temkin <ktemkin@binghamton.edu> 
 * @license GNU Public License, {@link http://www.gnu.org/copyleft/gpl.html}
 */
class block_quickmail_email_renderer extends plugin_renderer_base
{
    public function render_email_send_form(moodleform $form) {

        //TODO: replace jquery with the local plugin
        $this->page->requires->js('/blocks/quickmail/js/jquery.js');
        $this->page->requires->js('/blocks/quickmail/js/selection.js');

        //TODO: rewrite
        echo html_writer::start_tag('div', array('class' => 'no-overflow'));
        $form->display();
        echo html_writer::end_tag('div');
    }

    public function render_no_users_message() {
        print_error('no_users', 'block_quickmail');
    }

    /**
     * Handles the event in which the e-mail compose form is cancelled. 
     * 
     * @return void
     */
    public function render_email_cancel_handler($composer) {

        //Redirect to the cancel destination, which is determined by the composer object.
        redirect($composer->get_cancel_destination());
    }

    public function render_email_send_errors($errors) {

        //TODO: rewrite

        foreach ($errors as $type => $error) {
            echo $this->notification($error, 'notifyproblem');
        }
    }

    public function render_draft_saved_notification() {
        
        echo $this->notification(get_string('changessaved'), 'notifysuccess');
    }
}
