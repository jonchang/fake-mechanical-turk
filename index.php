<?php

define('FAKE_MTURK', 1);
require_once("functions.php");
require_once("config.php");

# Passed from MTurk:
# https://dl.dropboxusercontent.com/u/8859543/build/index.html?url=https%3A%2F%2Fdl.dropboxusercontent.com%2Fu%2F8859543%2Ffishpics%2FChelmonops_truncatus.jpg&assignmentId=3O6CYIULED1J2V0HZWG64GCRLFFWUU&hitId=3R16PJFTS3RR3DFR5A1JFHPKETXK4N&workerId=AKUM1FLS9WBYZ&turkSubmitTo=https%3A%2F%2Fworkersandbox.mturk.com

if (!isset($_GET['p'])) {
    die("No trial set.");
}

if (!isset($_GET['assignmentId']) && !isset($_POST['assignmentId'])) {
    # Non-MTurk entry. Generate an assignment based off of the worker ID
    if (!isset($_GET['workerId']) && !isset($_POST['workerId'])) {
        # Use IP for worker ID
        $_GET['workerId'] = $_SERVER['REMOTE_ADDR'];
    }
    $_GET['assignmentId'] = md5($_GET['workerId']);
    $_GET['hitId'] = md5($_GET['assignmentId']);
    $_GET['turkSubmitTo'] = "http://$_SERVER[HTTP_HOST]/success.php";
}

$path = $_GET['p'];
$trial = preg_replace('/\/mturk\/externalSubmit$/', '', $path);
$trial = preg_replace("/\W/", '', $trial);

if (!preg_match('/^\w+$/', $trial)) {
    die('bad trial specified');
}

if (!isset($trials[$trial])) {
    die("trial doesn't exist in config");
}

$current = $trials[$trial];

msg_array($_GET, 'GET: ');
msg_array($_POST, 'POST: ');

# Instantiate new SQLite connection.
$db = new SQLitePDO(preg_replace("/\W/", '', $trial));

if (isset($_POST['assignmentId'])) {
    # This is an MTurk postback to us. Unmangle the sequence ID.
    preg_match("/(\w+)__(\d+)/", $_POST['assignmentId'], $matches);
    unset($_POST['assignmentId']);
    list(, $assignment, $sequence) = $matches;
    $answers = json_encode($_POST);

    $db->add_result($assignment, $answers, $sequence);
} else if (isset($_GET['hitId']) && isset($_GET['assignmentId']) && isset($_GET['workerId']) && isset($_GET['turkSubmitTo'])) {
    # This is an initial MTurk assignment. So we need to potentially generate a new plan.
    # sanitize all inputs
    $worker = preg_replace("/\W/", '', $_GET['workerId']);
    $assignment = preg_replace("/\W/", '', $_GET['assignmentId']);
    $hit = preg_replace("/\W/", "", $_GET['hitId']);
    # Sanitize URL based on RFC 3986
    $submitto = preg_replace("/[^\w\d.~!*'();:@&=+$,\/%#?[\]\-]/", "", $_GET['turkSubmitTo']);

    $params = parse_tsv_file($current['file']);

    # Is there a plan available?
    $plan = $db->get_plan($assignment);
    if (!$plan) {
        # We don't have a plan, so generate a plan.
        # Shuffle in place first
        shuffle($params);
        # Sanity check that we don't have too many elements
        if ($current['count'] <= count($params)) {
            $shuffled = array_slice($params, 0, $current['count'], true);
            $plan = array_repeat($shuffled, $current['replicates']);
        } else {
            http_response_code(500);
            msg("Server error: trial $trial has too many entries ${current['count']}");
            exit;
        }
        $db->add_plan($assignment, $trial, $hit, $worker, $submitto, $plan);
    }
} else if (isset($_GET['hitId']) && $_GET['assignmentId'] == 'ASSIGNMENT_ID_NOT_AVAILABLE') {
    # This is an MTurk preview. Just direct them to the endpoint in config.ini
    # and slap on all of the other query parameters.
    $qs = http_build_query($_GET);
    header("Location: $current[endpoint]?$qs");
    msg("MTurk preview [$trial]: hitId=$_GET[hitId]");
    exit;
}

# Direct people to the next step.

# Fetch the plan that should exist.
if (!isset($plan)) {
    $plan = $db->get_plan($assignment);
}

$n_plan = count($plan);

# If we don't have workerId etc. information, fetch this from the database.
list($worker, $hit, $submitto) = $db->get_assignment_info($assignment);

# Initialize empty "content" variables.
$head = $body = '';

$next_plan_seq = $db->next_sequence_id($assignment);

if (array_key_exists($next_plan_seq, $plan)) {
    $next_plan = (array) $plan[$next_plan_seq];
    # Mangle the sequence ID with the assignment ID because (1) that is
    # the only parameter guaranteed to be POSTed back to us, and (2) we
    # would otherwise need to track which is the "active" subassignment
    # that has been farmed out. This method is thus resistant to e.g.,
    # the user pressing the back button and resubmitting a stale task.
    $qsa = array(
        'assignmentId' => "${assignment}__$next_plan_seq",
        'hitId' => $hit,
        'workerId' => $worker,
        'turkSubmitTo' => "http://$_SERVER[HTTP_HOST]/$trial"
    );
    $qsa = array_merge($qsa, $next_plan);
    $qst = http_build_query($qsa);
    $next_url = "$current[endpoint]?$qst";
    $db->append_log($assignment, $next_plan_seq, 'assigned', null);

    # Use a meta refresh so people can watch their progress.
    $head .= '<meta http-equiv="refresh" content="2; url=' . htmlspecialchars($next_url, ENT_QUOTES) . '">';
    $body .= "$next_plan_seq of $n_plan tasks complete, redirecting you in 2 seconds...<br>";
    $body .= '<a href="' . htmlspecialchars($next_url, ENT_QUOTES) . '">click here if you are not redirected</a>';
} else {
    # Nothing left in our plan, so let's batch it up and post it to Amazon.
    $allresults = $db->get_all_results($assignment);

    # Check that the submit URL goes to the right place.
    if (!preg_match('/\/mturk\/externalSubmit$/', $submitto)) {
        $submitto .= '/mturk/externalSubmit';
    }

    $body .= "All $n_plan tasks complete!";
    $body .= '<form method="post" action="' . htmlspecialchars($submitto, ENT_QUOTES) . '">';
    $body .= '<input type="hidden" name="__allresults" value="' . htmlspecialchars($allresults, ENT_QUOTES) . '">';
    $body .= '<input type="hidden" name="__plan" value="' . htmlspecialchars(json_encode($plan), ENT_QUOTES) . '">';
    $body .= '<input type="hidden" name="assignmentId" value="' . htmlspecialchars($assignment, ENT_QUOTES) . '">';
    $body .= '<input type="hidden" name="workerId" value="' . htmlspecialchars($worker, ENT_QUOTES) . '">';
    $body .= '<input type="submit" value="Submit my results">';
}


echo <<<_HTML
<!doctype html> <html> <head>
    <meta charset="utf-8">
    <style>body {font-size: 200%} input, button {font-size: 100%}; </style>
    $head
</head> <body>
$body
</body> </html>
_HTML;

?>
