<?php

define('NO_DEBUG_DISPLAY', false);

require("../../config.php");
require_once("lib.php");
require_once($CFG->libdir.'/enrollib.php');
require_once($CFG->libdir . '/filelib.php');


require('razorpay-php/Razorpay.php');
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

require_login();


$data = new stdClass();

$data->amount = $_SESSION['amount'];
$data->first_name = $_SESSION['firstname'];
$data->last_name = $_SESSION['lastname'];
$data->city = $_SESSION['city'];
$data->email = $_SESSION['receiptemail'];
$cost = $_SESSION['amount'];
$plugin = enrol_get_plugin('razorpay');

$secretkey = $plugin->get_config('secretkey');
$keyId = $plugin->get_config('publishablekey');

$custom = explode('-', $_SESSION['custom']);
$data->userid           = (int)$custom[0];
$data->courseid         = (int)$custom[1];
$data->instanceid       = (int)$custom[2];
$data->payment_gross    = $data->amount;
$data->payment_currency = $_SESSION['currency_code'];
$data->timeupdated      = time();

$orderId = $_SESSION['razorpay_order_id'];

// Get the user and course records.

if (! $user = $DB->get_record("user", array("id" => $data->userid))) {
    message_razorpay_error_to_admin("Not a valid user id", $data);
    redirect($CFG->wwwroot);
}

if (! $course = $DB->get_record("course", array("id" => $data->courseid))) {
    message_razorpay_error_to_admin("Not a valid course id", $data);
    redirect($CFG->wwwroot);
}

if (! $context = context_course::instance($course->id, IGNORE_MISSING)) {
    message_razorpay_error_to_admin("Not a valid context id", $data);
    redirect($CFG->wwwroot);
}

$PAGE->set_context($context);

if (! $plugininstance = $DB->get_record("enrol", array("id" => $data->instanceid, "status" => 0))) {
    message_razorpay_error_to_admin("Not a valid instance id", $data);
    redirect($CFG->wwwroot);
}

if ($data->courseid != $plugininstance->courseid) {
    message_razorpay_error_to_admin("Course Id does not match to the course settings, received: ".$data->courseid, $data);
    redirect($CFG->wwwroot);
}


$cost = format_float($cost, 2, false);

// Let's say each article costs 15.00 bucks.

try {

    $success = false;

    $error = "Payment Failed";

    if (empty($_POST['razorpay_payment_id']) === false)
    {
        $api = new Api($keyId, $secretkey);

        try
        {

            $attributes = array(
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $_POST['razorpay_payment_id'],
                'razorpay_signature' => $_POST['razorpay_signature']
            );
            print_r($attributes);

            $api->utility->verifyPaymentSignature($attributes);
            
            $success = true;
      

        }
        catch(SignatureVerificationError $e)
        {
            $success = false;
            $error = 'Razorpay Error : ' . $e->getMessage();
        }
    }

    if ($success === true)
    {

        $DB->insert_record("enrol_razorpay", $data);

        if ($plugininstance->enrolperiod) {
                $timestart = time();
                $timeend   = $timestart + $plugininstance->enrolperiod;
        } else {
                $timestart = 0;
                $timeend   = 0;
        }
    
        // Enrol user.
        $plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);
    
        // Pass $view=true to filter hidden caps if the user cannot see them.
        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                                                 '', '', '', '', false, true)) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }
    
            $mailstudents = $plugin->get_config('mailstudents');
            $mailteachers = $plugin->get_config('mailteachers');
            $mailadmins   = $plugin->get_config('mailadmins');
            $shortname = format_string($course->shortname, true, array('context' => $context));
    
        $coursecontext = context_course::instance($course->id);
    
        if (!empty($mailstudents)) {
                $a = new stdClass();
                $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
                $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";
    
                $userfrom = empty($teacher) ? core_user::get_support_user() : $teacher;
                $subject = get_string("enrolmentnew", 'enrol', $shortname);
                $fullmessage = get_string('welcometocoursetext', '', $a);
                $fullmessagehtml = html_to_text('<p>'.get_string('welcometocoursetext', '', $a).'</p>');
            
                // Send test email.
                ob_start();
                $success = email_to_user($user, $userfrom, $subject, $fullmessage, $fullmessagehtml);
                $smtplog = ob_get_contents();
                ob_end_clean();
        }
    
        if (!empty($mailteachers) && !empty($teacher)) {
                $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
                $a->user = fullname($user);
                
                $subject = get_string("enrolmentnew", 'enrol', $shortname);
                $fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
                $fullmessagehtml = html_to_text('<p>'.get_string('enrolmentnewuser', 'enrol', $a).'</p>');
            
                // Send test email.
                ob_start();
                $success = email_to_user($teacher, $user, $subject, $fullmessage, $fullmessagehtml);
                $smtplog = ob_get_contents();
                ob_end_clean();
        }
    
        if (!empty($mailadmins)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);
            $admins = get_admins();
            foreach ($admins as $admin) {
                $subject = get_string("enrolmentnew", 'enrol', $shortname);
                $fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
                $fullmessagehtml = html_to_text('<p>'.get_string('enrolmentnewuser', 'enrol', $a).'</p>');
            
                // Send test email.
                ob_start();
                $success = email_to_user($admin, $user, $subject, $fullmessage, $fullmessagehtml);
                $smtplog = ob_get_contents();
                ob_end_clean();
            }
        }
    
        $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
    
        $fullname = format_string($course->fullname, true, array('context' => $context));
    
        if (is_enrolled($context, null, '', true)) { // TODO: use real stripe check.
            redirect($destination, get_string('paymentthanks', '', $fullname));
    
        } else {   // Somehow they aren't enrolled yet!
            $PAGE->set_url($destination);
            echo $OUTPUT->header();
            $a = new stdClass();
            $a->teacher = get_string('defaultcourseteacher');
            $a->fullname = $fullname;
            notice(get_string('paymentsorry', '', $a), $destination);
        }
    }
    else
    {
        message_razorpay_error_to_admin("Unknown reason",$data);
    }

} catch (Exception $e) {

    // Something else happened, completely unrelated to Stripe.
    echo 'Something else happened, completely unrelated to Stripe';
}


/**
 * Send payment error message to the admin.
 *
 * @param string $subject
 * @param stdClass $data
 */
function message_razorpay_error_to_admin($subject, $data) {
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

    foreach ($data as $key => $value) {
        $message .= s($key) ." => ". s($value)."\n";
    }

    $subject = "RozorPay PAYMENT ERROR: ".$subject;
    $fullmessage = $message;
    $fullmessagehtml = html_to_text('<p>'.$message.'</p>');
        
    // Send test email.
    ob_start();
    $success = email_to_user($admin, $admin, $subject, $fullmessage, $fullmessagehtml);
    $smtplog = ob_get_contents();
    ob_end_clean();
}
