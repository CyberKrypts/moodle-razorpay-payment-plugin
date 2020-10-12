<?php

$_SESSION['courseid'] = $course->id;
$_SESSION['amount'] = $cost;
$_SESSION['currency'] = $instance->currency;
$_SESSION['coursename']=$coursefullname;
$_SESSION['course_short'] = $courseshortname;
$_SESSION[]=$userfullname;
// p($USER->country)
$_SESSION['receiptemail']=$USER->email;
$_SESSION['city'] = $USER->city;
$_SESSION['firstname'] = $USER->firstname;
$_SESSION['lastname'] = $USER->lastname;
$_SESSION['custom'] = "{$USER->id}-{$course->id}-{$instance->id}";
$_SESSION['currency_code'] = $instance->currency;

require('razorpay-php/Razorpay.php');

defined('MOODLE_INTERNAL') || die();

require_login();

use Razorpay\Api\Api;

global $DB, $USER, $CFG;
$plugin = enrol_get_plugin('razorpay');

$secretkey = $plugin->get_config('secretkey');
$keyId = $plugin->get_config('publishablekey');


$courseid =  $_SESSION['courseid'];
$amount = $_SESSION['amount'];
$currency = $_SESSION['currency_code'];
$description = $_SESSION['coursename'];
$receiptemail = $_SESSION['receiptemail']; 
$username_rp = $_SESSION['firstname'] . " " . $_SESSION['lastname'];
if (empty($secretkey) || empty($courseid) || empty($amount) || empty($currency) || empty($description) || empty($receiptemail)) {
  redirect($CFG->wwwroot . '/course/view.php?id=' . $courseid);
} else {

  // Create the Razorpay Order

  $api = new Api($keyId, $secretkey);
  $course_slug = $courseshortname." ".$course->id;
  $receipt = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $course_slug)));
  // We create an razorpay order using orders api
  $orderData = [
    'receipt'         => $receipt,
    'amount'          => $amount * 100,
    'currency'        => $currency,
  ];

  $razorpayOrder = $api->order->create($orderData);

  $razorpayOrderId = $razorpayOrder['id'];

  $_SESSION['razorpay_order_id'] = $razorpayOrderId;
  // echo $razorpayOrderId;
  $displayAmount = $amount = $orderData['amount'];

  // if ($displayCurrency !== 'INR') {
  //   $url = "https://api.fixer.io/latest?symbols=$displayCurrency&base=INR";
  //   $exchange = json_decode(file_get_contents($url), true);
  //   $displayAmount = $exchange['rates'][$displayCurrency] * $amount / 100;
  // }

  $data = [
    "key"               => $keyId,
    "amount"            => $amount,
    "name"              => $_SESSION['course_short'],
    "description"       => $description,
    "image"             => "",
    "prefill"           => [
      "name"              => $username_rp,
      "email"             => $receiptemail,
    ],
    "notes"             => [
      "course_id" => $receipt,
    ],
    "order_id"          => $razorpayOrderId,
  ];

  // if ($displayCurrency !== 'INR') {
  //   $data['display_currency']  = $displayCurrency;
  //   $data['display_amount']    = $displayAmount;
  // }
}

?>

<?php 
echo "<h3>$instancename</h3>";
echo "<p>This course requires a payment.</p>";
echo "<strong>cost : {$instance->currency} {$cost}</strong>";
?>

<form id="checkout-selection" action="<?php echo $CFG->wwwroot."/enrol/razorpay/charge.php"; ?>" method="POST">
<script src="https://checkout.razorpay.com/v1/checkout.js" data-key="<?php echo $data['key'] ?>" data-amount="<?php echo $data['amount'] ?>" data-currency="INR" data-name="<?php echo $data['name'] ?>" data-image="<?php echo $data['image'] ?>" data-description="<?php echo $data['description'] ?>" data-prefill.name="<?php echo $data['prefill']['name'] ?>" data-prefill.email="<?php echo $data['prefill']['email'] ?>" data-notes.shopping_order_id="3456" data-order_id="<?php echo $data['order_id'] ?>" <?php if ($displayCurrency !== 'INR') { ?> data-display_amount="<?php echo $data['display_amount'] ?>" <?php } ?> <?php if ($displayCurrency !== 'INR') { ?> data-display_currency="<?php echo $data['display_currency'] ?>" <?php } ?>>
    </script>
    <!-- <input type="submit" value="Pay now"> -->
</form>
