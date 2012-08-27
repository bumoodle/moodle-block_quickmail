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

// Include the core function library.
require_once($CFG->dirroot . '/blocks/quickmail/lib.php');

/**
 * QuickMail block; the primary user menu for QuickMail/MoodleMail
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
class block_quickmail extends block_list {


    /**
     * Initializes the QuickMail Block. 
     */
    public function init() {

        // Set the title of the block from the language file.
        $this->title = quickmail::_s('pluginname');

    }

    /**
     * Returns a list of areas in which this block is applicable.
     * 
     * @return array    An array indicating the list of site areas which use of this block is applicable.
     */
    public function applicable_formats() {

        // This block should be used in the course context only.
        return array('site' => false, 'my' => false, 'course' => true);

    }

    /**
     * Creates the list content of the QuickMail block.
     * 
     * @return stdClass An object containing the list of items to be rendered, in the format specified by block_list.
     */
    public function get_content() {

        global $CFG, $COURSE, $OUTPUT;

        // If the content for this block has already been generated, use it.
        if ($this->content !== null) {
            return $this->content;
        }

        // Otherwise, create a new content list for this block.
        $this->content = new stdClass;
        $this->content->items = array();
        $this->content->icons = array();
        $this->content->footer = '';

        // Get the local context for this course.
        $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);


        $config = quickmail::load_config($COURSE->id);

        // Determine if the given user can send e-mail messages...

        // ... to thier peers:
        $can_compose = has_capability('block/quickmail:cansend', $context);
        
        // ... and to their instructor:
        $can_ask_instructor = has_capability('block/quickmail:canaskinstructor', $context);

        $icon_class = array('class' => 'icon');

        // If the user can send e-mail messages, grant them access to the composition-related settings:
        if ($can_compose || $can_ask_instructor) {
            $cparam = array('courseid' => $COURSE->id);

            if($can_compose) {
                $send_email_str = quickmail::_s('composenew');
                $send_email = html_writer::link(
                    new moodle_url('/blocks/quickmail/email.php', $cparam),
                    $send_email_str
                );
                $this->content->items[] = $send_email;
                $this->content->icons[] = $OUTPUT->pix_icon('i/email', $send_email_str, 'moodle', $icon_class);
            }

            if($can_ask_instructor) {
                $send_email_str = quickmail::_s('messageinstructor');
                $send_email = html_writer::link(
                    new moodle_url('/blocks/quickmail/email.php', array('courseid' => $COURSE->id, 'type' => 'askinstructor')),
                    $send_email_str
                );
                $this->content->items[] = $send_email;
                $this->content->icons[] = $OUTPUT->pix_icon('i/email', $send_email_str, 'moodle', $icon_class);
            }

            $signature_str = quickmail::_s('signature');
            $signature = html_writer::link(
                new moodle_url('/blocks/quickmail/signature.php', $cparam),
                $signature_str
            );
            $this->content->items[] = $signature;
            $this->content->icons[] = $OUTPUT->pix_icon('signature', $signature_str, 'block_quickmail', $icon_class);

            $draft_params = $cparam + array('type' => 'drafts');
            $drafts_email_str = quickmail::_s('drafts');
            $drafts = html_writer::link(
                new moodle_url('/blocks/quickmail/emaillog.php', $draft_params),
                $drafts_email_str
            );
            $this->content->items[] = $drafts;
            $this->content->icons[] = $OUTPUT->pix_icon('drafts', $drafts_email_str, 'block_quickmail', $icon_class);

            $history_str = quickmail::_s('history');
            $history = html_writer::link(
                new moodle_url('/blocks/quickmail/emaillog.php', $cparam),
                $history_str
            );
            $this->content->items[] = $history;
            $this->content->icons[] = $OUTPUT->pix_icon('history', $history_str, 'block_quickmail', $icon_class);
        }

        /*
        if (has_capability('block/quickmail:allowalternate', $context)) {
            $alt_str = quickmail::_s('alternate');
            $alt = html_writer::link(
                new moodle_url('/blocks/quickmail/alternate.php', $cparam),
                $alt_str
            );

            $this->content->items[] = $alt;
            $this->content->icons[] = $OUTPUT->pix_icon('i/settings', $alt_str, 'moodle', $icon_class);
        }
         */

        if (has_capability('block/quickmail:canconfig', $context)) {
            $config_str = quickmail::_s('config');
            $config = html_writer::link(
                new moodle_url('/blocks/quickmail/config.php', $cparam),
                $config_str
            );
            $this->content->items[] = $config;
            $this->content->icons[] = $OUTPUT->pix_icon('i/settings', $config_str, 'moodle', $icon_class);
        }

        return $this->content;
    }
}
