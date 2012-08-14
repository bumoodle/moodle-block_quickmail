<?php


// Written at Louisiana State University

abstract class lsu_dev {
    abstract static function pluginname();

    static function is_lsu() {
        global $CFG;
        return isset($CFG->is_lsu) and $CFG->is_lsu;
    }

    public static function _s($key, $a = null) {
        $class = get_called_class();

        return get_string($key, $class::pluginname(), $a);
    }

    /**
     * Shorten locally called string even more
     */
    public static function gen_str() {
        $class = get_called_class();

        return function ($key, $a = null) use ($class) {
            return get_string($key, $class::pluginname(), $a);
        };
    }
}

abstract class quickmail extends lsu_dev {
    static function pluginname() {
        return 'block_quickmail';
    }

    static function format_time($time) {
        return date("l, d F Y, h:i A", $time);
    }


    /**
     * Gets an associative array with the details used for Raise Your Hand messages. 
     * 
     * @param int $course_id    The ID of the course in which the question is being asked.
     * @param int $qa_id        The ID of the Question Attempt for which the user has a question.
     * @param int $user_id      The ID of the inquiring user.
     * @access public
     * @return array            An associative array, contaning details relevant to the current RYH message.
     */
    static function get_raise_hand_details($course_id, $qa_id, $user_id)
    {
        global $DB;

        //get course data
        $course = $DB->get_record('course', array('id' => $course_id), 'shortname');

        //and get the relevant question and user data
        $sql = 'SELECT questiontext, questionsummary, responsesummary, slot, quiz.name  FROM {question_attempts} questionattempt
                    JOIN {question} question
                    JOIN {quiz_attempts} quizattempt
                    JOIN {quiz} quiz                
                WHERE questionattempt.questionusageid = quizattempt.uniqueid 
                    AND quizattempt.quiz = quiz.id 
                    AND questionattempt.id = ?
                    AND quizattempt.userid = ?
                    AND question.id = questionattempt.questionid;';

        //use the question ID to get the question summary, number, and quiz name
        $qa_record = $DB->get_record_sql($sql, array($qa_id, $user_id));

        //if we can't find a question with this ID, this ID doesn't belong to this user, or this question isn't part of a quiz, return false
        if($qa_record === false)
            return false;

        return (object)array
            (
                'questiontext' => $qa_record->questiontext,
                'questionsummary' => $qa_record->questionsummary,
                'responsesummary' => $qa_record->responsesummary,
                'quizname' => $qa_record->name,
                'qnum' => $qa_record->slot,
                'courseshortname' => $course->shortname
            );


    }

    /**
     * Gets an array of instructors capable of answering Raise Your Hand questions. 
     * 
     * @param context $context    The course context which should contain the instructors.
     * @access public
     * @return array              An associative array of the user objects for the RYH instructors.
     */
    static function get_raise_hand_instructors($context)
    {
        global $DB;

        //get the role information for editing teachers
        //TODO: replace this with an _efficient_ capabilitycheck
        $role = $DB->get_record('role', array('shortname' => 'editingteacher'), 'id');

        //get the list of instructors
        $users = get_role_users($role, $context, false, 'u.id, u.firstname, u.lastname, u.email, u.mailformat');

        //start an empty array of users to notify
        $to_notify = array();

        foreach($users as $user)
        {
            //get the notification method for raisehand events
            $raisehand_method = get_user_preferences('message_provider_mod_quiz_raisehand_loggedoff', false, $user);

            //if the user has raisehand enabled, then add them to the notification list
            if($raisehand_method == 'email')
                $to_notify[$user->id] = $user;
        }

        return $to_notify;
    }

    /**
     * Adds a question summary to the given message text, for Raise Your Hand messages. 
     * 
     * @param mixed $message        The user-specified message text.
     * @param mixed $course_id      The course ID number that the question belongs to.
     * @param mixed $question_id    The question _attempt_ id for the question whose summary should be added.
     * @param mixed $user           The user choosing to _send_ the given e-mail.
     * @param mixed $html           If true, the message will be sent with HTML formatting.
     * @access public
     * @return string               The HTML or text fragment which contains both the question and message, for sending via e-mail. 
     */
    static function add_question_to_message($message, $course_id, $question_id, $user, $html = true)
    {
        //get the Raise Your Hand details
        $details = self::get_raise_hand_details($course_id, $question_id, $user->id);

        //start a new output buffer
        $output = '';

        //
        // Display the "Joe Suny asks:"
        //

        if($html)
            $output .= html_writer::tag('div', self::_s('userasks', $user),  array('style' => 'font-style: italic;'));
        else
            $output .= self::_s('userasks', $user);


        //
        // Add the user's message
        //

        $output .= $message;

        //
        // Display "with regard to":
        //

        if($html)
            $output .= html_writer::tag('div', self::_s('withregardtoq'),  array('style' => 'font-style: italic;'));
        else
            $output .= self::_s('withregardtoq');

        //
        // Display the question summary
        //

        if($html)
            $output .= nl2br($details->questionsummary);
        else
            $output .= $details->questionsummary;

        
        //
        // Display the last response
        //

        if(!empty($details->responsesummary))
        {

            //user's last response was:

            if($html)
                $output .= html_writer::tag('div', self::_s('lastresponse', $user),  array('style' => 'font-style: italic;'));
            else
                $output .= self::_s('lastresponse', $user);

            //and the response summary
            
            if($html)
                $output .= nl2br($details->responsesummary);
            else
                $output .= $details->responsesummary;
        }

        //and return the created message
        return $output;

    }

    static function cleanup($table, $itemid) {
        global $DB;

        // Clean up the files associated with this email 
        // Fortunately, they are only db references, but
        // they shouldn't be there, nonetheless.
        $params = array('component' => $table, 'itemid' => $itemid);

        $result = (
            $DB->delete_records('files', $params) and
            $DB->delete_records($table, array('id' => $itemid))
        );

        return $result;
    }

    static function history_cleanup($itemid) {
        return quickmail::cleanup('block_quickmail_log', $itemid);
    }

    static function draft_cleanup($itemid) {
        return quickmail::cleanup('block_quickmail_drafts', $itemid);
    }

    static function process_attachments($context, $email, $table, $id) {
        global $CFG, $USER;

        $base_path = "temp/block_quickmail/{$USER->id}";
        $moodle_base = "$CFG->dataroot/$base_path";

        if(!file_exists($moodle_base)) {
            mkdir($moodle_base, 0777, true);
        }

        $zipname = $zip = $actual_zip = '';

        if(!empty($email->attachment)) {
            $zipname = "attachment.zip";
            $zip = "$base_path/$zipname";
            $actual_zip = "$moodle_base/$zipname";

            $packer = get_file_packer();
            $fs = get_file_storage();

            $files = $fs->get_area_files(
                $context->id,
                'block_quickmail_'.$table, 
                'attachment', 
                $id, 
                'id'
            );

            $stored_files = array();

            foreach($files as $file) {
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

        if(empty($config)) {
            $m = 'moodle';
            $allowstudents = get_config($m, 'block_quickmail_allowstudents');
            $roleselection = get_config($m, 'block_quickmail_roleselection');

            $config = array(
                'allowstudents' => $allowstudents,
                'roleselection' => $roleselection
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

    function list_entries($courseid, $type, $page, $perpage, $userid, $count) {
        global $CFG, $DB, $OUTPUT;

        $dbtable = 'block_quickmail_'.$type;

        $table = new html_table();

        $params = array('courseid' => $courseid, 'userid' => $userid);
        $logs = $DB->get_records($dbtable, $params,
            'time DESC', '*', $page * $perpage, $perpage * ($page + 1));

        $table->head= array(get_string('date'), quickmail::_s('subject'),
            quickmail::_s('attachment'), get_string('action'));

        $table->data = array_map(function($log) use ($OUTPUT, $type) {
            $date = quickmail::format_time($log->time);
            $subject = $log->subject;
            $attachments = $log->attachment;

            $params = array(
                'courseid' => $log->courseid,
                'type' => $type,
                'typeid' => $log->id
            );

            $open_link = html_writer::link(
                new moodle_url('/blocks/quickmail/email.php', $params),
                $OUTPUT->pix_icon('i/search', 'Open Email')
            );

            $delete_link = html_writer::link (
                new moodle_url('/blocks/quickmail/emaillog.php',
                    $params + array('action' => 'delete')
                ),
                $OUTPUT->pix_icon("i/cross_red_big", "Delete Email")
            );

            $actions = implode(' ', array($open_link, $delete_link));

            return array($date, $subject, $attachments, $actions);
        }, $logs);

        $paging = $OUTPUT->paging_bar($count, $page, $perpage, '/blocks/quickmail/emaillog.php?courseid='.$courseid);

        $html = $paging;
        $html .= html_writer::table($table);
        $html .= $paging;
        return $html;
    }
}

