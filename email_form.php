<?php

// Written at Louisiana State University

require_once($CFG->libdir . '/formslib.php');

class email_form extends moodleform {
    private function reduce_users($in, $user) {
        return $in . '<option value="'.$this->option_value($user).'">'.
               $this->option_display($user).'</option>';
    }

    private function option_display($user) {
        $users_to_groups = $this->_customdata['users_to_groups'];

        if (empty($users_to_groups[$user->id])) {
            $groups = quickmail::_s('no_section');
        } else {
            $only_names = function($group) { return $group->name; };
            $groups = implode(',', array_map($only_names, $users_to_groups[$user->id]));
        }

        return sprintf("%s (%s)", fullname($user), $groups);
    }

    private function option_value($user) {
        $users_to_groups = $this->_customdata['users_to_groups'];
        $users_to_roles = $this->_customdata['users_to_roles'];

        $only_sn = function($role) { return $role->shortname; };

        $roles = implode(',', array_map($only_sn, $users_to_roles[$user->id]));

        // everyone defaults to none
        $roles .= ',none';

        if (empty($users_to_groups[$user->id])) {
            $groups = 0;
        } else {
            $only_id = function($group) { return $group->id; };
            $groups = implode(',', array_map($only_id, $users_to_groups[$user->id]));
            $groups .= ',all';
        }

        return sprintf("%s %s %s", $user->id, $groups, $roles);
    }

    public function definition() 
    {
        global $CFG, $USER, $COURSE, $OUTPUT;

        $mform =& $this->_form;

        if($this->_customdata['raisehand'])
            $mform->addElement('hidden', 'mailto', implode(',', array_map(function ($r) { return $r->id; }, $this->_customdata['users'])));
        else
            $mform->addElement('hidden', 'mailto', '');


        $mform->addElement('hidden', 'userid', $USER->id);
        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->addElement('hidden', 'type', '');
        $mform->addElement('hidden', 'typeid', 0);

	if(!$this->_customdata['raisehand'])
	{

		$links = array();
		$gen_url = function($type) use ($COURSE) {
		    $email_param = array('courseid' => $COURSE->id, 'type' => $type);
		    return new moodle_url('emaillog.php', $email_param);
		};

		$draft_link = html_writer::link ($gen_url('drafts'), quickmail::_s('drafts'));
		$links[] =& $mform->createElement('static', 'draft_link', '', $draft_link);

		$context= get_context_instance(CONTEXT_COURSE, $COURSE->id);

		if(has_capability('block/quickmail:cansend', $context)) {
		    $history_link = html_writer::link($gen_url('log'), quickmail::_s('history'));
		    $links[] =& $mform->createElement('static', 'history_link', '', $history_link); 
		}

		$mform->addGroup($links, 'links', '&nbsp;', array(' | '), false);
	}

        $mform->addElement('static', 'from', quickmail::_s('from'), $USER->email);
	
	
	//~ktemkin: in some cases, don't provide the recipient list
	if(!$this->_customdata['raisehand'])
		{
		
		$mform->addElement('static', 'selectors', '', '
		    <table>
			<tr>
			    <td>
				<strong class="required">'.quickmail::_s('selected').'
				    <img class="req" title="Required field" alt="Required field" src="'.$OUTPUT->pix_url('req').'"/>
				</strong>
			    </td>
			    <td align="right" colspan="2">
				<strong>'.quickmail::_s('role_filter').'</strong>
			    </td>
			</tr>
			<tr>
			    <td width="300">
				<select id="mail_users" multiple size="30">
				    '.array_reduce($this->_customdata['selected'], array($this, 'reduce_users'), '').'
				</select>
			    </td>
			    <td width="100" align="center">
				<p>
				    <input type="button" id="add_button" value="'.$OUTPUT->larrow().' '.quickmail::_s('add_button').'"/>
				</p>
				<p>
				    <input type="button" id="remove_button" value="'.quickmail::_s('remove_button').' '.$OUTPUT->rarrow().'"/>
				</p>
				<p>
				    <input type="button" id="add_all" value="'.quickmail::_s('add_all').'"/>
				</p>
				<p>
				    <input type="button" id="remove_all" value="'.quickmail::_s('remove_all').'"/>
				</p>
			    </td>
			    <td width="300" align="right">
				<div>
				    <select id="roles">
					<option value="none" selected>'.quickmail::_s('no_filter').'</option>
					'.array_reduce($this->_customdata['roles'], function($in, $role) {
					    return $in . '<option value="'.$role->shortname.'">'.$role->name.'</option>';
					 }, '').'
				    </select>
				</div>
				<div class="object_labels"><strong>'.quickmail::_s('potential_sections').'</strong></div>
				<div>
				    <select id="groups" multiple size="5">
					 '.(empty($this->_customdata['groups']) ? '' :
					 '<option SELECTED value="all">'.quickmail::_s('all_sections')).'</option>
					'.array_reduce($this->_customdata['groups'], function($in, $group) {
					    return $in . '<option value="'.$group->id.'">'.$group->name.'</option>';
					 }, '').'
					 <option value="0">'.quickmail::_s('no_section').'</option>
				    </select>
				</div>
				<div class="object_labels"><strong>'.quickmail::_s('potential_users').'</strong></div>
				<div>
				    <select id="from_users" multiple size="20">
					'.array_reduce($this->_customdata['users'], array($this, 'reduce_users'), '').'
				    </select>
				</div>
			    </td>
			</tr>
		    </table>
		');
	}
	else
    {
        //start a buffer for instructor names
        $instructor = '';

        foreach($this->_customdata['users'] as $reciever)
        {
            $instructor .= html_writer::start_tag('div');

            $instructor .= $reciever->firstname . ' '. $reciever->lastname;

            //TODO: respect show email preferences
            $instructor .= ' &lt;'.$reciever->email.'&gt;';

            $instructor .= html_writer::end_tag('div');
        }

		//in raise hand mode, fix this to _instructors_
        $mform->addElement('static', 'to', quickmail::_s('to'), $instructor);

        //pass on the raise-your-hand question ID 
        $mform->addELement('hidden', 'raisehand', $this->_customdata['raisehand']);
	}


	$mform->addElement('static', 'spacer1', '', '');
        $mform->addElement('filemanager', 'attachments', quickmail::_s('attachment'));
	$mform->addElement('static', 'spacer2', '', '');

    $mform->addElement('text', 'subject', quickmail::_s('subject'), array('style' => 'width:495px;'));
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required');

        //if this is a Raise Your Hand e-mail, call it a question
        if(!empty($this->_customdata['raisehand']))
            $mform->addElement('editor', 'message', quickmail::_s('question'));

        //otherwise, call it a message
        else
            $mform->addElement('editor', 'message', quickmail::_s('message'));

        $mform->addRule('message', null, 'required');

    //if we're in "raise your hand" mode
    if($this->_customdata['raisehand'])
    {
        $mform->addElement('static', 'spacer3', '', '');

        //display the inlucded quesiton to the user
        $mform->addElement('static', 'qincluded', '', quickmail::_s('qincluded'));

        //include a boxed-in preview
        $summary_text = html_writer::tag('div', $this->_customdata['questionsummary'], array('class' => 'qsummary'));
        $mform->addElement('static', 'questionsummary', '', $summary_text);

    }

        

	$mform->addElement('static', 'spacer2', '', '');

	$options = $this->_customdata['sigs'] + array(-1 => 'No '. quickmail::_s('sig'));
        $mform->addElement('select', 'sigid', quickmail::_s('signature'), $options);

        $radio = array(
            $mform->createElement('radio', 'receipt', '', get_string('yes'), 1),
            $mform->createElement('radio', 'receipt', '', get_string('no'), 0)
        );

        $mform->addGroup($radio, 'receipt_action', quickmail::_s('receipt'), array(' '), false);

        $buttons = array();
        $buttons[] =& $mform->createElement('submit', 'send', quickmail::_s('send_email'));
        $buttons[] =& $mform->createElement('submit', 'draft', quickmail::_s('save_draft'));
        $buttons[] =& $mform->createElement('submit', 'cancel', get_string('cancel'));

        $mform->addGroup($buttons, 'buttons', quickmail::_s('actions'), array(' '), false);
    }
}
