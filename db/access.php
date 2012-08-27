<?php

// Written at Louisiana State University
// Modified for Moodle@BU 

$capabilities = array(

    // Ask instructor permissions; allow the given users to ask the instructor questions using QuickMail.
    'block/quickmail:canaskinstructor' => array(
        'captype' => 'write',
        'riskbitmask' => RISK_SPAM,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'student' => CAP_ALLOW
        )
    ),


    // Recieve ask instructor permissions; any user with this permission with recieve "ask instructor" e-mails.
    'block/quickmail:recieveaskinstructor' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'editingteacher' => CAP_ALLOW,
        )
    ),


    'block/quickmail:cansend' => array(
        'captype' => 'write',
        'riskbitmask' => RISK_SPAM,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
            'teacher' => CAP_ALLOW
        )
    ),

    // Allow the user to delete QuickMail message history.
    'block/quickmail:candelete' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
            'teacher' => CAP_ALLOW
        )
    ),



    'block/quickmail:allowalternate' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW
        )
    ),
    'block/quickmail:canconfig' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW
        )
    ),
    'block/quickmail:canimpersonate' => array(
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'manager' => CAP_ALLOW,
        )
    )
);
