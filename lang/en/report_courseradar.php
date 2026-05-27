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
 * English language strings for report_courseradar.
 *
 * @package    report_courseradar
 * @copyright  2025 Sergio Comerón <sergiocomeron@icloud.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activityovertime']    = 'Activity over time';
$string['activityovertime_desc'] = 'Daily evolution of student interactions in the selected period.';
$string['activitypattern']     = 'Activity pattern (day x hour)';
$string['activitypattern_desc'] = 'Interactions grouped by day of week and time slot — reveals when students are most active.';
$string['adjustperiod']        = 'Adjust period';
$string['allstudentsviewed']   = 'All students have viewed this!';
$string['applyfilter']         = 'Apply filter';
$string['atrisk']              = 'At-risk students';
$string['atrisk_info']         = 'Students with no interactions or very low activity in the selected period.';
$string['atrisk_lowactivity']  = 'Low engagement (< 30% resources visited)';
$string['atrisk_noactivity']   = 'No interactions';
$string['avgengagement']       = 'Avg. engagement';
$string['avgviews'] = 'Avg. views / module';
$string['completednoftrack']   = 'No completion tracking';
$string['completion']          = 'Completion';
$string['completion_desc']     = 'Students who completed it';
$string['completiondisabled']  = 'Activity completion is not enabled for this course.';
$string['completionstu']       = 'Completion';
$string['completionstu_desc']  = 'Completed / tracked';
$string['courseradar:view']    = 'View Course Radar report';
$string['coursevisits']        = 'Course visits';
$string['coursevisits_desc']   = 'Times students accessed the course home';
$string['coverage']            = 'Coverage';
$string['coverage_desc']       = '% enrolled students who viewed it';
$string['datefrom']            = 'From';
$string['dateto']              = 'To';
$string['daysinactive']    = 'Days inactive';
$string['daysinactive_desc']   = 'Days since last interaction';
$string['details']             = 'Detail';
$string['engdistribution']     = 'Engagement distribution';
$string['engdistribution_desc'] = 'Number of students in each resource-coverage quartile (based on % of resources visited).';
$string['filterbytype']        = 'Filter by type:';
$string['haventviewed']        = "Haven't viewed";
$string['haveviewed']          = 'Have viewed';
$string['hidden']              = 'Hidden';
$string['lastaccess']          = 'Last access';
$string['lastaccess_desc']     = 'Date of last recorded access';
$string['lastactivity']        = 'Last activity';
$string['lastactivity_desc']   = 'Most recent resource interaction';
$string['lastcoursevisit']     = 'Last course visit';
$string['lastcoursevisit_desc'] = 'Last time student accessed the course home';
$string['modules'] = 'Modules';
$string['moduletypesummary'] = 'Interactions by resource type';
$string['moduletypesummary_desc'] = 'Total views per resource type, normalised to the most-viewed type.';
$string['mostviewed']          = 'Most viewed';
$string['msgsent']             = 'Message sent';
$string['neveraccessed']   = 'Never';
$string['noactivitydata']      = 'No activity data for this period.';
$string['nointeractions']      = 'No interactions recorded in this period.';
$string['none']                = 'None';
$string['nostudents']          = 'No students enrolled in this course.';
$string['notifyrisk']          = 'Message at-risk students';
$string['notifyrisk_placeholder'] = 'Write your message here...';
$string['notviewed']           = 'not seen';
$string['noviewsyet']          = 'No views yet.';
$string['plugindesc']          = 'Track student interactions with course resources and activities.';
$string['pluginname']          = 'Course Radar';

$string['resetfilter']         = 'Reset';
$string['resetsort']           = 'Back to section view';
$string['resource']            = 'Resource / Activity';
$string['resourceactivity']    = 'Resources & Activities';
$string['resourceactivity_desc'] = 'Coverage and interactions per resource, grouped by course section. Click a column header to sort.';
$string['resourcesvisited']    = 'Resources visited';
$string['resourcesvisited_desc'] = 'Different resources opened';
$string['riskscore']           = 'Score';
$string['riskscore_desc']      = 'Engagement score (0–100)';
$string['scatter_desc']        = 'Each dot is a student. X axis: % resources visited. Y axis: engagement score. Click a dot to open the student profile.';
$string['scatter_title']       = 'Student comparison: resources vs. engagement';
$string['scatter_xaxis']       = '% Resources visited';
$string['scatter_yaxis']       = 'Engagement score';
$string['scoredist_desc']       = 'Each bar shows how many students fall in that score band. The score (0–100) combines three factors: % of resources visited, days since last access, and activity completion (if enabled). A student who visits few resources but logged in recently scores higher than one who visited the same resources weeks ago.';
$string['scoredist_title']      = 'Engagement score distribution';
$string['searchstudent']       = 'Search student...';
$string['sendmsg']             = 'Send message';
$string['showhidden']          = 'Show hidden activities';
$string['sortby']              = 'Click to sort';
$string['student']             = 'Student';
$string['studentcoverage_desc'] = '% of course resources visited';
$string['studentengagement']   = 'Student Engagement';
$string['studentengagement_desc'] = 'Individual activity per student. Click any column header to sort. Expand a row to see resource-level detail.';
$string['studentviews_desc']   = 'Total accesses to all resources';
$string['tab_overview']        = 'Overview';
$string['tab_resources']       = 'Resources';
$string['tab_students']        = 'Students';
$string['times']               = 'views';
$string['topunseen']       = 'Least visited resources';
$string['topunseeninfo']   = 'Visible resources with lowest student coverage (100% excluded). Top 10 shown per active filter.';
$string['totalinteractions']   = 'Total interactions';
$string['totalresources']      = 'Total resources';

$string['totalviews']          = 'Total views';
$string['totalviews_desc']     = 'Total accesses by all students';
$string['type']                = 'Type';
$string['uniquestudents']      = 'Students';
$string['uniquestudents_desc'] = 'Unique students / enrolled';

$string['viewprofile']         = 'View profile';
$string['weeklyactivity'] = 'Weekly activity';
$string['weeklyaggregated']    = 'Aggregated by week (period > 90 days)';
$string['weekvspreview']       = 'vs. last week';
