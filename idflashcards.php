<?php

//  Lists all the users within a given course

require_once('../../config.php');
require_once($CFG->libdir.'/tablelib.php');
require_once($CFG->libdir.'/filelib.php');

define('USER_SMALL_CLASS', 20);   // Below this is considered small
define('USER_LARGE_CLASS', 200);  // Above this is considered large
define('DEFAULT_PAGE_SIZE', 28);
define('SHOW_ALL_PAGE_SIZE', 5000);
define('MODE_BRIEF', 0);
define('MODE_USERDETAILS', 1);

$page         = optional_param('page', 0, PARAM_INT);                     // which page to show
$perpage      = optional_param('perpage', SHOW_ALL_PAGE_SIZE, PARAM_INT);  // how many per page
$mode         = optional_param('mode', MODE_USERDETAILS, PARAM_INT);                  // use the MODE_ constants
$accesssince  = optional_param('accesssince',0,PARAM_INT);                // filter by last access. -1 = never
$search       = optional_param('search','',PARAM_RAW);                    // make sure it is processed with p() or s() when sending to output!
$roleid       = optional_param('roleid', 0, PARAM_INT);                   // optional roleid, 0 means all enrolled users (or all on the frontpage)
$picturecolumns = optional_param('picturecolumns', 4, PARAM_INT);

$contextid    = optional_param('contextid', 0, PARAM_INT);                // one of this or
$courseid     = optional_param('id', 0, PARAM_INT);                       // this are required

$PAGE->set_url('/blocks/idflashcards/idflashcards.php', array(
            'page' => $page,
            'perpage' => $perpage,
            'mode' => $mode,
            'accesssince' => $accesssince,
            'search' => $search,
            'roleid' => $roleid,
            'contextid' => $contextid,
            'id' => $courseid));

if ($contextid) {
	$context = get_context_instance_by_id($contextid, MUST_EXIST);
	if ($context->contextlevel != CONTEXT_COURSE) {
		print_error('invalidcontext');
	}
	$course = $DB->get_record('course', array('id'=>$context->instanceid), '*', MUST_EXIST);
} else {
	$course = $DB->get_record('course', array('id'=>$courseid), '*', MUST_EXIST);
	$context = get_context_instance(CONTEXT_COURSE, $course->id, MUST_EXIST);
}
// not needed anymore
unset($contextid);
unset($courseid);

require_login($course);

$systemcontext = get_context_instance(CONTEXT_SYSTEM);
$isfrontpage = ($course->id == SITEID);

$frontpagectx = get_context_instance(CONTEXT_COURSE, SITEID);

if ($isfrontpage) {
	$PAGE->set_pagelayout('admin');
	require_capability('moodle/site:viewparticipants', $systemcontext);
} else {
	if ($mode) {
		$PAGE->set_pagelayout('incourse');
	} else {
		$PAGE->set_pagelayout('idflashcards');
	}
	require_capability('moodle/course:viewparticipants', $context);
}

$rolenamesurl = new moodle_url("$CFG->wwwroot/blocks/idflashcards/idflashcards.php?contextid=$context->id&sifirst=&silast=");

$allroles = get_all_roles();
$roles = get_profile_roles($context);
$allrolenames = array();
if ($isfrontpage) {
	$rolenames = array(0=>get_string('allsiteusers', 'role'));
} else {
	$rolenames = array(0=>get_string('allparticipants'));
}

foreach ($allroles as $role) {
	$allrolenames[$role->id] = strip_tags(role_get_name($role, $context));   // Used in menus etc later on
	if (isset($roles[$role->id])) {
		$rolenames[$role->id] = $allrolenames[$role->id];
	}
}

// make sure other roles may not be selected by any means
if (empty($rolenames[$roleid])) {
	print_error('noparticipants');
}

// no roles to display yet?
// frontpage course is an exception, on the front page course we should display all users
if (empty($rolenames) && !$isfrontpage) {
	if (has_capability('moodle/role:assign', $context)) {
		redirect($CFG->wwwroot.'/'.$CFG->admin.'/roles/assign.php?contextid='.$context->id);
	} else {
		print_error('noparticipants');
	}
}

add_to_log($course->id, 'user', 'view all', 'idflashcards.php?id='.$course->id, '');

$countries = get_string_manager()->get_list_of_countries();

$strnever = get_string('never');

if ($mode !== NULL) {
	$mode = (int)$mode;
	$SESSION->userindexmode = $mode;
} else if (isset($SESSION->userindexmode)) {
	$mode = (int)$SESSION->userindexmode;
} else {
	$mode = MODE_BRIEF;
}

/// Check to see if groups are being used in this course
/// and if so, set $currentgroup to reflect the current group

$groupmode    = groups_get_course_groupmode($course);   // Groups are being used
$currentgroup = groups_get_course_group($course, true);

if (!$currentgroup) {      // To make some other functions work better later
	$currentgroup  = NULL;
}

$isseparategroups = ($course->groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context));

$PAGE->set_title("$course->shortname: ".get_string('participants'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->add_body_class('path-user');                     // So we can style it independently
$PAGE->set_other_editing_capability('moodle/course:manageactivities');

echo $OUTPUT->header();
//echo $OUTPUT->box('This list is generated from data in KnowHope Plus, but students who have withdrawn (grade W) from the course will continue to appear in KnowHope Plus after disappearing from this list because the W grade is part of their academic record. Please contact the Registrar for all enrollment issues.', 'generalbox', 'knowhopeplus');
echo '<div class="userlist">';

if ($isseparategroups and (!$currentgroup) ) {
	// The user is not in the group so show message and exit
	echo $OUTPUT->heading(get_string("notingroup"));
	echo $OUTPUT->footer();
	exit;
}


// Should use this variable so that we don't break stuff every time a variable is added or changed.
$baseurl = new moodle_url('/blocks/idflashcards/idflashcards.php', array(
            'contextid' => $context->id,
            'roleid' => $roleid,
            'id' => $course->id,
            'perpage' => $perpage,
            'accesssince' => $accesssince,
            'search' => s($search)));

/// setting up tags
if ($course->id == SITEID) {
	$filtertype = 'site';
} else if ($course->id && !$currentgroup) {
	$filtertype = 'course';
	$filterselect = $course->id;
} else {
	$filtertype = 'group';
	$filterselect = $currentgroup;
}


/*
 /// Get the hidden field list
 if (has_capability('moodle/course:viewhiddenuserfields', $context)) {
 $hiddenfields = array();  // teachers and admins are allowed to see everything
 } else {
 $hiddenfields = array_flip(explode(',', $CFG->hiddenuserfields));
 }

 if (isset($hiddenfields['lastaccess'])) {
 // do not allow access since filtering
 $accesssince = 0;
 }
 */

/// Print settings and things in a table across the top
$controlstable = new html_table();
$controlstable->attributes['class'] = 'controls';
$controlstable->cellspacing = 0;
$controlstable->data[] = new html_table_row();
$formatmenu = array( '0' => 'with names',
                         '1' => 'without names');
$select = new single_select($baseurl, 'mode', $formatmenu, $mode, null, 'formatmenu');
$select->set_label(get_string('userlist'));
$userlistcell = new html_table_cell();
$userlistcell->attributes['class'] = 'right';
$userlistcell->text = $OUTPUT->render($select);
$controlstable->data[0]->cells[] = $userlistcell;
echo html_writer::table($controlstable);

/// Define a table showing a list of users in the current role selection
$tablecolumns = array('userpic', 'fullname');
$extrafields = get_extra_user_fields($context);
$tableheaders = array(get_string('userpic'), get_string('fullnameuser'));
$table = new flexible_table('user-index-participants-'.$course->id);
$table->define_columns($tablecolumns);
$table->define_headers($tableheaders);
$table->define_baseurl($baseurl->out());
$table->no_sorting('roles');
$table->no_sorting('groups');
$table->no_sorting('groupings');
$table->no_sorting('select');
$table->set_attribute('cellspacing', '0');
$table->set_attribute('id', 'participants');
$table->set_attribute('class', 'generaltable generalbox');
$table->set_control_variables(array(
TABLE_VAR_SORT    => 'ssort',
TABLE_VAR_HIDE    => 'shide',
TABLE_VAR_SHOW    => 'sshow',
TABLE_VAR_IFIRST  => 'sifirst',
TABLE_VAR_ILAST   => 'silast',
TABLE_VAR_PAGE    => 'spage'
));
$table->setup();

// we are looking for all users with this role assigned in this context or higher
$contextlist = get_related_contexts_string($context);

list($esql, $params) = get_enrolled_sql($context, NULL, $currentgroup, true);
$joins = array("FROM {user} u");
$wheres = array();

$extrasql = get_extra_user_fields_sql($context, 'u', '', array(
            'id', 'username', 'firstname', 'lastname', 'email', 'city', 'country',
            'picture', 'lang', 'timezone', 'maildisplay', 'imagealt', 'lastaccess'));

if ($isfrontpage) {
	$select = "SELECT u.id, u.username, u.firstname, u.lastname,
                          u.email, u.city, u.country, u.picture,
                          u.lang, u.timezone, u.maildisplay, u.imagealt,
                          u.lastaccess$extrasql";
	$joins[] = "JOIN ($esql) e ON e.id = u.id"; // everybody on the frontpage usually
	if ($accesssince) {
		$wheres[] = get_user_lastaccess_sql($accesssince);
	}

} else {
	$select = "SELECT u.id, u.username, u.firstname, u.lastname,
                          u.email, u.city, u.country, u.picture,
                          u.lang, u.timezone, u.maildisplay, u.imagealt,
                          COALESCE(ul.timeaccess, 0) AS lastaccess$extrasql";
	$joins[] = "JOIN ($esql) e ON e.id = u.id"; // course enrolled users only
	$joins[] = "LEFT JOIN {user_lastaccess} ul ON (ul.userid = u.id AND ul.courseid = :courseid)"; // not everybody accessed course yet
	$params['courseid'] = $course->id;
	if ($accesssince) {
		$wheres[] = get_course_lastaccess_sql($accesssince);
	}
}

// performance hacks - we preload user contexts together with accounts
list($ccselect, $ccjoin) = context_instance_preload_sql('u.id', CONTEXT_USER, 'ctx');
$select .= $ccselect;
$joins[] = $ccjoin;


// limit list to users with some role only
if ($roleid) {
	$wheres[] = "u.id IN (SELECT userid FROM {role_assignments} WHERE roleid = :roleid AND contextid $contextlist)";
	$params['roleid'] = $roleid;
}

$from = implode("\n", $joins);
if ($wheres) {
	$where = "WHERE " . implode(" AND ", $wheres);
} else {
	$where = "";
}

$totalcount = $DB->count_records_sql("SELECT COUNT(u.id) $from $where", $params);

if (!empty($search)) {
	$fullname = $DB->sql_fullname('u.firstname','u.lastname');
	$wheres[] = "(". $DB->sql_like($fullname, ':search1', false, false) .
                    " OR ". $DB->sql_like('email', ':search2', false, false) .
                    " OR ". $DB->sql_like('idnumber', ':search3', false, false) .") ";
	$params['search1'] = "%$search%";
	$params['search2'] = "%$search%";
	$params['search3'] = "%$search%";
}

list($twhere, $tparams) = $table->get_sql_where();
if ($twhere) {
	$wheres[] = $twhere;
	$params = array_merge($params, $tparams);
}

$from = implode("\n", $joins);
if ($wheres) {
	$where = "WHERE " . implode(" AND ", $wheres);
} else {
	$where = "";
}

if ($table->get_sql_sort()) {
	$sort = ' ORDER BY '.$table->get_sql_sort();
} else {
	$sort = '';
}

$matchcount = $DB->count_records_sql("SELECT COUNT(u.id) $from $where", $params);

$table->initialbars(true);
$table->pagesize($perpage, $matchcount);

// list of users at the current visible page - paging makes it relatively short
$userlist = $DB->get_recordset_sql("$select $from $where $sort", $params, $table->get_page_start(), $table->get_page_size());

/// If there are multiple Roles in the course, then show a drop down menu for switching
if (count($rolenames) > 1) {
	echo '<div class="rolesform">';
	echo '<label for="rolesform_jump">'.get_string('currentrole', 'role').'&nbsp;</label>';
	echo $OUTPUT->single_select($rolenamesurl, 'roleid', $rolenames, $roleid, null, 'rolesform');
	echo '</div>';

} else if (count($rolenames) == 1) {
	// when all users with the same role - print its name
	echo '<div class="rolesform">';
	echo get_string('role').get_string('labelsep', 'langconfig');
	$rolename = reset($rolenames);
	echo $rolename;
	echo '</div>';
}

if ($roleid > 0) {
	$a = new stdClass();
	$a->number = $totalcount;
	$a->role = $rolenames[$roleid];
	$heading = format_string(get_string('xuserswiththerole', 'role', $a));

	if ($currentgroup and $group) {
		$a->group = $group->name;
		$heading .= ' ' . format_string(get_string('ingroup', 'role', $a));
	}

	if ($accesssince) {
		$a->timeperiod = $timeoptions[$accesssince];
		$heading .= ' ' . format_string(get_string('inactiveformorethan', 'role', $a));
	}

	$heading .= ": $a->number";

	if (user_can_assign($context, $roleid)) {
		$heading .= ' <a href="'.$CFG->wwwroot.'/'.$CFG->admin.'/roles/assign.php?roleid='.$roleid.'&amp;contextid='.$context->id.'">';
		$heading .= '<img src="'.$OUTPUT->pix_url('i/edit') . '" class="icon" alt="" /></a>';
	}
	echo $OUTPUT->heading($heading, 3);
} else {
	if ($course->id != SITEID && has_capability('moodle/course:enrolreview', $context)) {
		$editlink = $OUTPUT->action_icon(new moodle_url('/enrol/users.php', array('id' => $course->id)),
		new pix_icon('i/edit', get_string('edit')));
	} else {
		$editlink = '';
	}
	if ($course->id == SITEID and $roleid < 0) {
		$strallparticipants = get_string('allsiteusers', 'role');
	} else {
		$strallparticipants = get_string('allparticipants');
	}
	if ($matchcount < $totalcount) {
		echo $OUTPUT->heading($strallparticipants.get_string('labelsep', 'langconfig').$matchcount.'/'.$totalcount . $editlink, 3);
	} else {
		echo $OUTPUT->heading($strallparticipants.get_string('labelsep', 'langconfig').$matchcount . $editlink, 3);
	}
}

if ($totalcount < 1) {
	echo $OUTPUT->heading(get_string('nothingtodisplay'));
} else {
	if ($totalcount > $perpage) {

		$firstinitial = $table->get_initial_first();
		$lastinitial  = $table->get_initial_last();
		$strall = get_string('all');
		$alpha  = explode(',', get_string('alphabet', 'langconfig'));

		// Bar of first initials

		echo '<div class="initialbar firstinitial">'.get_string('firstname').' : ';
		if(!empty($firstinitial)) {
			echo '<a href="'.$baseurl->out().'&amp;sifirst=">'.$strall.'</a>';
		} else {
			echo '<strong>'.$strall.'</strong>';
		}
		foreach ($alpha as $letter) {
			if ($letter == $firstinitial) {
				echo ' <strong>'.$letter.'</strong>';
			} else {
				echo ' <a href="'.$baseurl->out().'&amp;sifirst='.$letter.'">'.$letter.'</a>';
			}
		}
		echo '</div>';

		// Bar of last initials

		echo '<div class="initialbar lastinitial">'.get_string('lastname').' : ';
		if(!empty($lastinitial)) {
			echo '<a href="'.$baseurl->out().'&amp;silast=">'.$strall.'</a>';
		} else {
			echo '<strong>'.$strall.'</strong>';
		}
		foreach ($alpha as $letter) {
			if ($letter == $lastinitial) {
				echo ' <strong>'.$letter.'</strong>';
			} else {
				echo ' <a href="'.$baseurl->out().'&amp;silast='.$letter.'">'.$letter.'</a>';
			}
		}
		echo '</div>';

		$pagingbar = new paging_bar($matchcount, intval($table->get_page_start() / $perpage), $perpage, $baseurl);
		$pagingbar->pagevar = 'spage';
		echo $OUTPUT->render($pagingbar);
	}

	if ($matchcount > 0) {
		$usersprinted = array();
		$i = 0;
		$j = 0;
		$table = new html_table();
		if ($mode === MODE_BRIEF) { 
			$picturecolumns = 7; 
		}
		if ($mode === MODE_USERDETAILS) { 
			$picturecolumns = 4; 
		}
		foreach ($userlist as $user) {
			if (in_array($user->id, $usersprinted)) { /// Prevent duplicates by r.hidden - MDL-13935
				continue;
			}
			$usersprinted[] = $user->id; /// Add new user to the array of users printed

			context_instance_preload($user);

			$context = get_context_instance(CONTEXT_COURSE, $course->id);
			$usercontext = get_context_instance(CONTEXT_USER, $user->id);

			$countries = get_string_manager()->get_list_of_countries();

			if ($i === 0 || $i % $picturecolumns === 0) {
				$row = new html_table_row();
				$j++;
			}
			$row->cells[$picturecolumns - ($i % $picturecolumns)] = new html_table_cell();
			$row->cells[$picturecolumns - ($i % $picturecolumns)]->attributes['class'] = 'left side';

			$row->cells[$picturecolumns - ($i % $picturecolumns)]->text = $OUTPUT->user_picture($user, array('size' => 100, 'courseid'=>$course->id));
			if ($mode === MODE_BRIEF) { $row->cells[$picturecolumns - ($i % $picturecolumns)]->text .= '<br>' . $user->firstname . '<br>' . $user->lastname; }
			$table->data[$j] = $row;
			$i++;
		}
		echo html_writer::table($table);

	} else {
		echo $OUTPUT->heading(get_string('nothingtodisplay'));
	}
}

if (has_capability('moodle/site:viewparticipants', $context) && $totalcount > ($perpage*3)) {
	echo '<form action="idflashcards.php" class="searchform"><div><input type="hidden" name="id" value="'.$course->id.'" />'.get_string('search').':&nbsp;'."\n";
	echo '<input type="text" name="search" value="'.s($search).'" />&nbsp;<input type="submit" value="'.get_string('search').'" /></div></form>'."\n";
}

$perpageurl = clone($baseurl);
$perpageurl->remove_params('perpage');
if ($perpage == SHOW_ALL_PAGE_SIZE) {
	$perpageurl->param('perpage', DEFAULT_PAGE_SIZE);
	echo $OUTPUT->container(html_writer::link($perpageurl, get_string('showperpage', '', DEFAULT_PAGE_SIZE)), array(), 'showall');

} else if ($matchcount > 0 && $perpage < $matchcount) {
	$perpageurl->param('perpage', SHOW_ALL_PAGE_SIZE);
	echo $OUTPUT->container(html_writer::link($perpageurl, get_string('showall', '', $matchcount)), array(), 'showall');
}

echo '</div>';  // userlist
//echo $OUTPUT->box("This photo roster is given to you for your internal Hope College course management.  Hope College recognizes student photos as directory information (in compliance with FERPA), yet we do not want to be careless with student photos.  A good rule of thumb for student safety is to never post or publish names with photos of students.<br><br><strong>Note:  Students may choose to restrict disclosure of their photos.  If a student's photo does not appear on this roster, (s)he may have completed a non-disclosure form available in the Registrar's Office.  If (s)he is a new student, (s)he may not have an ID photo in the official ID photograph system.</strong>", 'generalbox', 'photooptout');

echo $OUTPUT->footer();

if ($userlist) {
	$userlist->close();
}


function get_course_lastaccess_sql($accesssince='') {
	if (empty($accesssince)) {
		return '';
	}
	if ($accesssince == -1) { // never
		return 'ul.timeaccess = 0';
	} else {
		return 'ul.timeaccess != 0 AND ul.timeaccess < '.$accesssince;
	}
}

function get_user_lastaccess_sql($accesssince='') {
	if (empty($accesssince)) {
		return '';
	}
	if ($accesssince == -1) { // never
		return 'u.lastaccess = 0';
	} else {
		return 'u.lastaccess != 0 AND u.lastaccess < '.$accesssince;
	}
}
