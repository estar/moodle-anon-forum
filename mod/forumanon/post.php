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
 * Edit and save a new post to a discussion
 *
 * @package mod-forum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');
require_once($CFG->libdir.'/completionlib.php');

$reply   = optional_param('reply', 0, PARAM_INT);
$forum   = optional_param('forum', 0, PARAM_INT);
$edit    = optional_param('edit', 0, PARAM_INT);
$delete  = optional_param('delete', 0, PARAM_INT);
$prune   = optional_param('prune', 0, PARAM_INT);
$name    = optional_param('name', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_INT);
$groupid = optional_param('groupid', null, PARAM_INT);
$anonymize = optional_param('anonymize', 0, PARAM_INT); // ##################################################
$anon_confirm = optional_param('anon_confirm', 0, PARAM_INT); // ##################################################

$PAGE->set_url('/mod/forumanon/post.php', array(
        'reply' => $reply,
        'forum' => $forum,
        'edit'  => $edit,
        'delete'=> $delete,
        'prune' => $prune,
        'name'  => $name,
        'confirm'=>$confirm,
        'groupid'=>$groupid,
		'anonymize'=>$anonymize,
		'anon_confirm'=>$anon_confirm,
        ));
//these page_params will be passed as hidden variables later in the form.
$page_params = array('reply'=>$reply, 'forum'=>$forum, 'edit'=>$edit);

$sitecontext = get_context_instance(CONTEXT_SYSTEM);

if (!isloggedin() or isguestuser()) {

    if (!isloggedin() and !get_referer()) {
        // No referer+not logged in - probably coming in via email  See MDL-9052
        require_login();
    }

    if (!empty($forum)) {      // User is starting a new discussion in a forum
        if (! $forum = $DB->get_record('forumanon', array('id' => $forum))) {
            print_error('invalidforumid', 'forumanon');
        }
    } else if (!empty($reply)) {      // User is writing a new reply
        if (! $parent = forumanon_get_post_full($reply)) {
            print_error('invalidparentpostid', 'forumanon');
        }
        if (! $discussion = $DB->get_record('forumanon_discussions', array('id' => $parent->discussion))) {
            print_error('notpartofdiscussion', 'forumanon');
        }
        if (! $forum = $DB->get_record('forumanon', array('id' => $discussion->forum))) {
            print_error('invalidforumid');
        }
    }
    if (! $course = $DB->get_record('course', array('id' => $forum->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('forumanon', $forum->id, $course->id)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    $PAGE->set_cm($cm, $course, $forum);
    $PAGE->set_context($modcontext);
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguestpost', 'forumanon').'<br /><br />'.get_string('liketologin'), get_login_url(), get_referer(false));
    echo $OUTPUT->footer();
    exit;
}

require_login(0, false);   // Script is useless unless they're logged in

if (!empty($forum)) {      // User is starting a new discussion in a forum
    if (! $forum = $DB->get_record("forumanon", array("id" => $forum))) {
        print_error('invalidforumid', 'forumanon');
    }
    if (! $course = $DB->get_record("course", array("id" => $forum->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("forumanon", $forum->id, $course->id)) {
        print_error("invalidcoursemodule");
    }

    $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);

    if (! forumanon_user_can_post_discussion($forum, $groupid, -1, $cm)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {
                if (enrol_selfenrol_available($course->id)) {
                    $SESSION->wantsurl = $FULLME;
                    $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                    redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
                }
            }
        }
        print_error('nopostforum', 'forumanon');
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    if (isset($_SERVER["HTTP_REFERER"])) {
        $SESSION->fromurl = $_SERVER["HTTP_REFERER"];
    } else {
        $SESSION->fromurl = '';
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course        = $course->id;
    $post->forum         = $forum->id;
    $post->discussion    = 0;           // ie discussion # not defined yet
    $post->parent        = 0;
    $post->subject       = '';
    $post->userid        = $anonymize ? get_config('forumanon', 'anonid') : $USER->id;
    $post->message       = '';
    $post->messageformat = editors_get_preferred_format();
    $post->messagetrust  = 0;
    $post->anonymize	 = $anonymize;
    $post->anon_confirm  = $anon_confirm;

    if (isset($groupid)) {
        $post->groupid = $groupid;
    } else {
        $post->groupid = groups_get_activity_group($cm);
    }

    forumanon_set_return();

} else if (!empty($reply)) {      // User is writing a new reply

    if (! $parent = forumanon_get_post_full($reply)) {
        print_error('invalidparentpostid', 'forumanon');
    }
    if (! $discussion = $DB->get_record("forumanon_discussions", array("id" => $parent->discussion))) {
        print_error('notpartofdiscussion', 'forumanon');
    }
    if (! $forum = $DB->get_record("forumanon", array("id" => $discussion->forum))) {
        print_error('invalidforumid', 'forumanon');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("forumanon", $forum->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    // Ensure lang, theme, etc. is set up properly. MDL-6926
    $PAGE->set_cm($cm, $course, $forum);

    $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);
    $modcontext    = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (! forumanon_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {  // User is a guest here!
                $SESSION->wantsurl = $FULLME;
                $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
            }
        }
        print_error('nopostforum', 'forumanon');
    }

    // Make sure user can post here
    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        $groupmode =  $cm->groupmode;
    } else {
        $groupmode = $course->groupmode;
    }
    if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
        if ($discussion->groupid == -1) {
            print_error('nopostforum', 'forumanon');
        } else {
            if (!groups_is_member($discussion->groupid)) {
                print_error('nopostforum', 'forumanon');
            }
        }
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course      = $course->id;
    $post->forum       = $forum->id;
    $post->discussion  = $parent->discussion;
    $post->parent      = $parent->id;
    $post->subject     = $parent->subject;
    $post->userid        = $anonymize ? get_config('forumanon', 'anonid') : $USER->id;
    $post->message     = '';
    $post->anonymize	 = $anonymize;
    $post->anon_confirm  = $anon_confirm;

    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $strre = get_string('re', 'forumanon');
    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
        $post->subject = $strre.' '.$post->subject;
    }

    unset($SESSION->fromdiscussion);

} else if (!empty($edit)) {  // User is editing their own post

    if (! $post = forumanon_get_post_full($edit)) {
        print_error('invalidpostid', 'forumanon');
    }
    if ($post->parent) {
        if (! $parent = forumanon_get_post_full($post->parent)) {
            print_error('invalidparentpostid', 'forumanon');
        }
    }

    if (! $discussion = $DB->get_record("forumanon_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'forumanon');
    }
    if (! $forum = $DB->get_record("forumanon", array("id" => $discussion->forum))) {
        print_error('invalidforumid', 'forumanon');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("forumanon", $forum->id, $course->id)) {
        print_error('invalidcoursemodule');
    } else {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    $PAGE->set_cm($cm, $course, $forum);

    if (!($forum->type == 'news' && !$post->parent && $discussion->timestart > time())) {
        if (((time() - $post->created) > $CFG->maxeditingtime) and
                    !has_capability('mod/forumanon:editanypost', $modcontext)) {
            print_error('maxtimehaspassed', 'forumanon', '', format_time($CFG->maxeditingtime));
        }
    }
    if (($post->userid <> $USER->id) and
                !has_capability('mod/forumanon:editanypost', $modcontext)) {
        print_error('cannoteditposts', 'forumanon');
    }


    // Load up the $post variable.
    $post->edit   = $edit;
    $post->course = $course->id;
    $post->forum  = $forum->id;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $post = trusttext_pre_edit($post, 'message', $modcontext);

    unset($SESSION->fromdiscussion);


}else if (!empty($delete)) {  // User is deleting a post

    if (! $post = forumanon_get_post_full($delete)) {
        print_error('invalidpostid', 'forumanon');
    }
    if (! $discussion = $DB->get_record("forumanon_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'forumanon');
    }
    if (! $forum = $DB->get_record("forumanon", array("id" => $discussion->forum))) {
        print_error('invalidforumid', 'forumanon');
    }
    if (!$cm = get_coursemodule_from_instance("forumanon", $forum->id, $forum->course)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $forum->course))) {
        print_error('invalidcourseid');
    }

    require_login($course, false, $cm);
    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    // if ( !(($post->userid == $USER->id && has_capability('mod/forumanon:deleteownpost', $modcontext))
    if ( !(($post->userid == get_config('forumanon', 'anonid') && has_capability('mod/forumanon:deleteownpost', $modcontext))
                || has_capability('mod/forumanon:deleteanypost', $modcontext)) ) {
        print_error('cannotdeletepost', 'forumanon');
    }


    $replycount = forumanon_count_replies($post);

    if (!empty($confirm) && confirm_sesskey()) {    // User has confirmed the delete
        //check user capability to delete post.
        $timepassed = time() - $post->created;
        if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/forumanon:deleteanypost', $modcontext)) {
            print_error("cannotdeletepost", "forumanon",
                      forumanon_go_back_to("discuss.php?d=$post->discussion"));
        }

        if ($post->totalscore) {
            notice(get_string('couldnotdeleteratings', 'rating'),
                    forumanon_go_back_to("discuss.php?d=$post->discussion"));

        } else if ($replycount && !has_capability('mod/forumanon:deleteanypost', $modcontext)) {
            print_error("couldnotdeletereplies", "forumanon",
                    forumanon_go_back_to("discuss.php?d=$post->discussion"));

        } else {
            if (! $post->parent) {  // post is a discussion topic as well, so delete discussion
                if ($forum->type == 'single') {
                    notice("Sorry, but you are not allowed to delete that discussion!",
                            forumanon_go_back_to("discuss.php?d=$post->discussion"));
                }
                forumanon_delete_discussion($discussion, false, $course, $cm, $forum);

                add_to_log($discussion->course, "forumanon", "delete discussion",
                           "view.php?id=$cm->id", "$forum->id", $cm->id);

                redirect("view.php?f=$discussion->forum");

            } else if (forumanon_delete_post($post, has_capability('mod/forumanon:deleteanypost', $modcontext),
                $course, $cm, $forum)) {

                if ($forum->type == 'single') {
                    // Single discussion forums are an exception. We show
                    // the forum itself since it only has one discussion
                    // thread.
                    $discussionurl = "view.php?f=$forum->id";
                } else {
                    $discussionurl = "discuss.php?d=$post->discussion";
                }

                add_to_log($discussion->course, "forumanon", "delete post", $discussionurl, "$post->id", $cm->id);

                redirect(forumanon_go_back_to($discussionurl));
            } else {
                print_error('errorwhiledelete', 'forumanon');
            }
        }


    } else { // User just asked to delete something

        forumanon_set_return();
        $PAGE->navbar->add(get_string('delete', 'forumanon'));
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);

        if ($replycount) {
            if (!has_capability('mod/forumanon:deleteanypost', $modcontext)) {
                print_error("couldnotdeletereplies", "forumanon",
                      forumanon_go_back_to("discuss.php?d=$post->discussion"));
            }
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesureplural", "forumanon", $replycount+1),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/forumanon/discuss.php?d='.$post->discussion.'#p'.$post->id);

            forumanon_print_post($post, $discussion, $forum, $cm, $course, false, false, false);

            if (empty($post->edit)) {
                $forumtracked = forumanon_tp_is_tracked($forum);
                $posts = forumanon_get_all_discussion_posts($discussion->id, "created ASC", $forumtracked);
                forumanon_print_posts_nested($course, $cm, $forum, $discussion, $post, false, false, $forumtracked, $posts);
            }
        } else {
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesure", "forumanon", $replycount),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/forumanon/discuss.php?d='.$post->discussion.'#p'.$post->id);
            forumanon_print_post($post, $discussion, $forum, $cm, $course, false, false, false);
        }

    }
    echo $OUTPUT->footer();
    die;


} else if (!empty($prune)) {  // Pruning

    if (!$post = forumanon_get_post_full($prune)) {
        print_error('invalidpostid', 'forumanon');
    }
    if (!$discussion = $DB->get_record("forumanon_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'forumanon');
    }
    if (!$forum = $DB->get_record("forumanon", array("id" => $discussion->forum))) {
        print_error('invalidforumid', 'forumanon');
    }
    if ($forum->type == 'single') {
        print_error('cannotsplit', 'forumanon');
    }
    if (!$post->parent) {
        print_error('alreadyfirstpost', 'forumanon');
    }
    if (!$cm = get_coursemodule_from_instance("forumanon", $forum->id, $forum->course)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    }
    if (!has_capability('mod/forumanon:splitdiscussions', $modcontext)) {
        print_error('cannotsplit', 'forumanon');
    }

    if (!empty($name) && confirm_sesskey()) {    // User has confirmed the prune

        $newdiscussion = new stdClass();
        $newdiscussion->course       = $discussion->course;
        $newdiscussion->forum        = $discussion->forum;
        $newdiscussion->name         = $name;
        $newdiscussion->firstpost    = $post->id;
        $newdiscussion->userid       = $discussion->userid;
        $newdiscussion->groupid      = $discussion->groupid;
        $newdiscussion->assessed     = $discussion->assessed;
        $newdiscussion->usermodified = $post->userid;
        $newdiscussion->timestart    = $discussion->timestart;
        $newdiscussion->timeend      = $discussion->timeend;

        $newid = $DB->insert_record('forumanon_discussions', $newdiscussion);

        $newpost = new stdClass();
        $newpost->id      = $post->id;
        $newpost->parent  = 0;
        $newpost->subject = $name;

        $DB->update_record("forumanon_posts", $newpost);

        forumanon_change_discussionid($post->id, $newid);

        // update last post in each discussion
        forumanon_discussion_update_last_post($discussion->id);
        forumanon_discussion_update_last_post($newid);

        add_to_log($discussion->course, "forumanon", "prune post",
                       "discuss.php?d=$newid", "$post->id", $cm->id);

        redirect(forumanon_go_back_to("discuss.php?d=$newid"));

    } else { // User just asked to prune something

        $course = $DB->get_record('course', array('id' => $forum->course));

        $PAGE->navbar->add(format_string($post->subject, true), new moodle_url('/mod/forumanon/discuss.php', array('d'=>$discussion->id)));
        $PAGE->navbar->add(get_string("prune", "forumanon"));
        $PAGE->set_title(format_string($discussion->name).": ".format_string($post->subject));
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('pruneheading', 'forumanon'));
        echo '<center>';

        include('prune.html');

        forumanon_print_post($post, $discussion, $forum, $cm, $course, false, false, false);
        echo '</center>';
    }
    echo $OUTPUT->footer();
    die;
} else {
    print_error('unknowaction');

}

if (!isset($coursecontext)) {
    // Has not yet been set by post.php.
    $coursecontext = get_context_instance(CONTEXT_COURSE, $forum->course);
}


// from now on user must be logged on properly

if (!$cm = get_coursemodule_from_instance('forumanon', $forum->id, $course->id)) { // For the logs
    print_error('invalidcoursemodule');
}
$modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
require_login($course, false, $cm);

if (isguestuser()) {
    // just in case
    print_error('noguest');
}

if (!isset($forum->maxattachments)) {  // TODO - delete this once we add a field to the forum table
    $forum->maxattachments = 3;
}

require_once('post_form.php');

$mform_post = new mod_forumanon_post_form('post.php', array('course'=>$course, 'cm'=>$cm, 'coursecontext'=>$coursecontext, 'modcontext'=>$modcontext, 'forum'=>$forum, 'post'=>$post));

$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid, $modcontext->id, 'mod_forumanon', 'attachment', empty($post->id)?null:$post->id);

//load data into form NOW!

// ###############################################
if (!anonymize && $USER->id != $post->userid) {   // Not the original author, so add a message to the end
    $data->date = userdate($post->modified);
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$USER->id.'&course='.$post->course.'">'.
                       fullname($USER).'</a>';
        $post->message .= '<p>(<span class="edited">'.get_string('editedby', 'forumanon', $data).'</span>)</p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(".get_string('editedby', 'forumanon', $data).')';
    }
}

if (!empty($parent)) {
    $heading = get_string("yourreply", "forumanon");
} else {
    if ($forum->type == 'qanda') {
        $heading = get_string('yournewquestion', 'forumanon');
    } else {
        $heading = get_string('yournewtopic', 'forumanon');
    }
}

if (forumanon_is_subscribed($USER->id, $forum->id)) {
    $subscribe = true;

} else if (forumanon_user_has_posted($forum->id, 0, $USER->id)) {
    $subscribe = false;

} else {
    // user not posted yet - use subscription default specified in profile
    $subscribe = !empty($USER->autosubscribe);
}

$draftid_editor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftid_editor, $modcontext->id, 'mod_forumanon', 'post', empty($post->id) ? null : $post->id, array('subdirs'=>true), $post->message);

$mform_post->set_data(array(        'attachments'=>$draftitemid,
                                    'general'=>$heading,
                                    'subject'=>$post->subject,
                                    'message'=>array(
                                        'text'=>$currenttext,
                                        'format'=>empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
                                        'itemid'=>$draftid_editor
                                    ),
                                    'subscribe'=>$subscribe?1:0,
                                    'mailnow'=>!empty($post->mailnow),
                                    'userid'=>$post->userid,
                                    'parent'=>$post->parent,
                                    'discussion'=>$post->discussion,
                                    'course'=>$course->id,
                                    'anon_confirm'=>$DB->count_records('forumanon_statement', array('userid'=>$USER->id))>0 ? 1 : 0) +
                                    $page_params +

                            (isset($post->format)?array(
                                    'format'=>$post->format):
                                array())+

                            (isset($discussion->timestart)?array(
                                    'timestart'=>$discussion->timestart):
                                array())+

                            (isset($discussion->timeend)?array(
                                    'timeend'=>$discussion->timeend):
                                array())+

                            (isset($post->groupid)?array(
                                    'groupid'=>$post->groupid):
                                array())+

                            (isset($discussion->id)?
                                    array('discussion'=>$discussion->id):
                                    array()));

if ($fromform = $mform_post->get_data()) {
	if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/mod/forumanon/view.php?f=$forum->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }
    
    $fromform->itemid        = $fromform->message['itemid'];
    $fromform->messageformat = $fromform->message['format'];
    $fromform->message       = $fromform->message['text'];
    // WARNING: the $fromform->message array has been overwritten, do not use it anymore!
    $fromform->messagetrust  = trusttext_trusted($modcontext);
    
    $fromform->userid = $anonymize ? get_config('forumanon', 'anonid') : $fromform->userid;
    
    if ($fromform->edit) {           // Updating a post
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        //fix for bug #4314
        if (!$realpost = $DB->get_record('forumanon_posts', array('id' => $fromform->id))) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }


        // if user has edit any post capability
        // or has either startnewdiscussion or reply capability and is editting own post
        // then he can proceed
        // MDL-7066
        if ( !(($realpost->userid == $USER->id && (has_capability('mod/forumanon:replypost', $modcontext)
                            || has_capability('mod/forumanon:startdiscussion', $modcontext))) ||
                            has_capability('mod/forumanon:editanypost', $modcontext)) ) {
            print_error('cannotupdatepost', 'forumanon');
        }

        $updatepost = $fromform; //realpost
        $updatepost->forum = $forum->id;
        if (!forumanon_update_post($updatepost, $mform_post, $message)) {
            print_error("couldnotupdate", "forumanon", $errordestination);
        }

        // MDL-11818
        if (($forum->type == 'single') && ($updatepost->parent == '0')){ // updating first post of single discussion type -> updating forum intro
            $forum->intro = $updatepost->message;
            $forum->timemodified = time();
            $DB->update_record("forumanon", $forum);
        }

        $timemessage = 2;
        if (!empty($message)) { // if we're printing stuff about the file upload
            $timemessage = 4;
        }
        $message .= '<br />'.get_string("postupdated", "forumanon");

        if ($subscribemessage = forumanon_post_subscription($fromform, $forum)) {
            $timemessage = 4;
        }
        if ($forum->type == 'single') {
            // Single discussion forums are an exception. We show
            // the forum itself since it only has one discussion
            // thread.
            $discussionurl = "view.php?f=$forum->id";
        } else {
            $discussionurl = "discuss.php?d=$discussion->id#p$fromform->id";
        }
        add_to_log($course->id, "forumanon", "update post",
                "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

        redirect(forumanon_go_back_to("$discussionurl"), $message.$subscribemessage, $timemessage);

        exit;


    } else if ($fromform->discussion) { // Adding a new post to an existing discussion
        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->forum=$forum->id;
        if ($fromform->id = forumanon_add_new_post($addpost, $mform_post, $message)) {

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($subscribemessage = forumanon_post_subscription($fromform, $forum)) {
                $timemessage = 4;
            }

            if (!empty($fromform->mailnow)) {
                $message .= get_string("postmailnow", "forumanon");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "forumanon") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "forumanon", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($forum->type == 'single') {
                // Single discussion forums are an exception. We show
                // the forum itself since it only has one discussion
                // thread.
                $discussionurl = "view.php?f=$forum->id";
            } else {
                $discussionurl = "discuss.php?d=$discussion->id";
            }
            add_to_log($course->id, "forumanon", "add post",
                      "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

            // Update completion state
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($forum->completionreplies || $forum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(forumanon_go_back_to("$discussionurl#p$fromform->id"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "forumanon", $errordestination);
        }
        exit;

    } else {                     // Adding a new discussion
        if (!forumanon_user_can_post_discussion($forum, $fromform->groupid, -1, $cm, $modcontext)) {
            print_error('cannotcreatediscussion', 'forumanon');
        }
        if (empty($fromform->groupid)) {
            $fromform->groupid = -1;
        }

        $fromform->mailnow = empty($fromform->mailnow) ? 0 : 1;

        $discussion = $fromform;
        $discussion->name    = $fromform->subject;

        $newstopic = false;
        if ($forum->type == 'news' && !$fromform->parent) {
            $newstopic = true;
        }
        $discussion->timestart = $fromform->timestart;
        $discussion->timeend = $fromform->timeend;

        $message = '';
        if ($discussion->id = forumanon_add_discussion($discussion, $mform_post, $message, $fromform->userid)) {
            add_to_log($course->id, "forumanon", "add discussion",
                    "discuss.php?d=$discussion->id", "$discussion->id", $cm->id);

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($fromform->mailnow) {
                $message .= get_string("postmailnow", "forumanon");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "forumanon") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "forumanon", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($subscribemessage = forumanon_post_subscription($discussion, $forum)) {
                $timemessage = 4;
            }

            // Update completion status
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($forum->completiondiscussions || $forum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(forumanon_go_back_to("view.php?f=$fromform->forum"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "forumanon", $errordestination);
        }

        exit;
    }
}



// To get here they need to edit a post, and the $post
// variable will be loaded with all the particulars,
// so bring up the form.

// $course, $forum are defined.  $discussion is for edit and reply only.

if ($post->discussion) {
    if (! $toppost = $DB->get_record("forumanon_posts", array("discussion" => $post->discussion, "parent" => 0))) {
        print_error('cannotfindparentpost', 'forumanon', '', $post->id);
    }
} else {
    $toppost->subject = ($forum->type == "news") ? get_string("addanewtopic", "forumanon") :
                                                   get_string("addanewdiscussion", "forumanon");
}

if (empty($post->edit)) {
    $post->edit = '';
}

if (empty($discussion->name)) {
    if (empty($discussion)) {
        $discussion = new stdClass();
    }
    $discussion->name = $forum->name;
}
if ($forum->type == 'single') {
    // There is only one discussion thread for this forum type. We should
    // not show the discussion name (same as forum name in this case) in
    // the breadcrumbs.
    $strdiscussionname = '';
} else {
    // Show the discussion name in the breadcrumbs.
    $strdiscussionname = format_string($discussion->name).':';
}

$forcefocus = empty($reply) ? NULL : 'message';

if (!empty($discussion->id)) {
    $PAGE->navbar->add(format_string($toppost->subject, true), "discuss.php?d=$discussion->id");
}

if ($post->parent) {
    $PAGE->navbar->add(get_string('reply', 'forumanon'));
}

if ($edit) {
    $PAGE->navbar->add(get_string('edit', 'forumanon'));
}

$PAGE->set_title("$course->shortname: $strdiscussionname ".format_string($toppost->subject));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

// checkup
if (!empty($parent) && !forumanon_user_can_see_post($forum, $discussion, $post, null, $cm)) {
    print_error('cannotreply', 'forumanon');
}
if (empty($parent) && empty($edit) && !forumanon_user_can_post_discussion($forum, $groupid, -1, $cm, $modcontext)) {
    print_error('cannotcreatediscussion', 'forumanon');
}

if ($forum->type == 'qanda'
            && !has_capability('mod/forumanon:viewqandawithoutposting', $modcontext)
            && !empty($discussion->id)
            && !forumanon_user_has_posted($forum->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','forumanon'));
}

forumanon_check_throttling($forum, $cm);

if (!empty($parent)) {
    if (! $discussion = $DB->get_record('forumanon_discussions', array('id' => $parent->discussion))) {
        print_error('notpartofdiscussion', 'forumanon');
    }

    forumanon_print_post($parent, $discussion, $forum, $cm, $course, false, false, false);
    if (empty($post->edit)) {
        if ($forum->type != 'qanda' || forumanon_user_can_see_discussion($forum, $discussion, $modcontext)) {
            $forumtracked = forumanon_tp_is_tracked($forum);
            $posts = forumanon_get_all_discussion_posts($discussion->id, "created ASC", $forumtracked);
            forumanon_print_posts_threaded($course, $cm, $forum, $discussion, $parent, 0, false, $forumtracked, $posts);
        }
    }
} else {
    if (!empty($forum->intro)) {
        echo $OUTPUT->box(format_module_intro('forumanon', $forum, $cm->id), 'generalbox', 'intro');
    }
}

$mform_post->display();

echo $OUTPUT->footer();

