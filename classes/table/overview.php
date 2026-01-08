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
 * Completion Progress block overview table.
 *
 * @package    block_completion_progress
 * @copyright  2016 Michael de Raadt
 * @copyright  2021 Jonathon Fowler <fowlerj@usq.edu.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_completion_progress\table;

defined('MOODLE_INTERNAL') || die;

use block_completion_progress\completion_progress;
use block_completion_progress\defaults;
use context_block;
use core_table\dynamic;
use core_table\local\filter\filterset;
use core_table\sql_table;
use renderable;

require_once($CFG->libdir . '/tablelib.php');

/**
 * Overview table.
 */
class overview extends sql_table implements dynamic, renderable {
    /** @var int $courseid Course id. */
    protected $courseid;
    /** @var stdClass $course Course object. */
    protected $course;
    /** @var context_course $context Context of the course. */
    protected $context;
    /** @var int $blockinstanceid Block instance id. */
    protected $blockinstanceid;
    /** @var stdClass $blockinstance Block instance object. */
    protected $blockinstance;
    /** @var context_block $blockcontext Context of the block. */
    protected $blockcontext;

    /** @var completion_progress Progress bar instance. */
    protected $progress;

    /** @var string $strftimedaydatetime Common date formatting pattern string. */
    protected $strftimedaydatetime;
    /** @var string $strindeterminate Indeterminate percentage string. */
    protected $strindeterminate;
    /** @var string $strnever Never accessed string. */
    protected $strnever;

    /**
     * Display the table.
     *
     * @param int $pagesize
     * @param bool $useinitialsbar
     * @param string $downloadhelpbutton
     */
    public function out($pagesize, $useinitialsbar, $downloadhelpbutton = '') {
        global $OUTPUT;

        $this->strftimedaydatetime = get_string('strftimedaydatetime', 'langconfig');
        $this->strindeterminate = get_string('indeterminate', 'block_completion_progress');
        $this->strnever = get_string('never');

        $tablecolumns = [];
        $tableheaders = [];

        if (!$this->is_downloading()) {
            $checkbox = new \core\output\checkbox_toggleall('participants-table', true, [
                'id' => 'select-all-participants',
                'name' => 'select-all-participants',
                'label' => get_string('selectall'),
                'labelclasses' => 'sr-only',
                'checked' => false,
            ]);
            $tablecolumns[] = 'select';
            $tableheaders[] = $OUTPUT->render($checkbox);
        }

        $tablecolumns[] = 'fullname';
        $tableheaders[] = get_string('fullname');

        /*TODO
        foreach (\core_user\fields::get_identity_fields($this->context) as $field) {
            $tablecolumns[] = $field;
            $tableheaders[] = \core_user\fields::get_display_name($field);
        }
        */
        if (get_config('block_completion_progress', 'showlastincourse') != 0) {
            // TODO also check moodle/course:viewhiddenuserfields and $CFG->hiddenuserfields.
            $tablecolumns[] = 'timeaccess';
            $tableheaders[] = get_string('lastonline', 'block_completion_progress');
        }

        if (!$this->is_downloading()) {
            $tablecolumns[] = 'progressbar';
            $tableheaders[] = get_string('progressbar', 'block_completion_progress');
        }

        $tablecolumns[] = 'progress';
        $tableheaders[] = get_string('progress', 'block_completion_progress');

        $this->define_columns($tablecolumns);
        $this->define_headers($tableheaders);
        $this->define_header_column('fullname');
        $this->sortable(true, 'firstname');
        $this->no_sorting('select');
        $this->no_sorting('progressbar');
        $this->set_default_per_page(20);
        $this->set_attribute('id', 'overview');
        $this->column_class('select', 'col-select');
        $this->column_class('fullname', 'col-fullname');
        $this->column_class('timeaccess', 'col-timeaccess');
        $this->column_class('progressbar', 'col-progressbar');
        $this->column_class('progress', 'col-progress');

        parent::out($pagesize, $useinitialsbar, $downloadhelpbutton);
    }

    /**
     * Query the db. Store results in the table object for use by build_table.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar. Bar
     * will only be used if there is a fullname column defined for the table.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;

        $picturefields = \core_user\fields::for_userpic()->get_sql('u', false, '', '', false)->selects;

        $params = ['courseid' => $this->courseid];

        $showinactive = !!get_config('block_completion_progress', 'showinactive');
        $enroljoin = get_enrolled_with_capabilities_join($this->context, '', '', 0, !$showinactive);
        $params += $enroljoin->params;

        // Table conditions.
        [$twhere, $tparams] = $this->get_sql_where();
        $twhere = $twhere ? "({$twhere})" : '1=1';
        $params += $tparams;

        // Filter conditions.
        $fjoins = [];
        $fwheres = [];

        if ($this->filterset->has_filter('groups')) {
            $filter = $this->filterset->get_filter('groups');
            switch ($filter->get_join_type()) {
                case filterset::JOINTYPE_NONE:
                    $groupsjointype = GROUPS_JOIN_NONE;
                    break;
                case filterset::JOINTYPE_ANY:
                    $groupsjointype = GROUPS_JOIN_ANY;
                    break;
                case filterset::JOINTYPE_ALL:
                    $groupsjointype = GROUPS_JOIN_ALL;
                    break;
                default:
                    throw new \coding_exception('unanticipated groups join type');
            }

            $groupids = $filter->get_filter_values();
            if ($groupids) {
                $groupsjoin = groups_get_members_join($groupids, 'u.id', $this->context, $groupsjointype);
                $fjoins[] = $groupsjoin->joins;
                $fwheres[] = "({$groupsjoin->wheres})";
                $params += $groupsjoin->params;
            }
        }

        if ($this->filterset->has_filter('groupings')) {
            $filter = $this->filterset->get_filter('groupings');
            if ($filter->get_join_type() !== filterset::JOINTYPE_ANY) {
                throw new \coding_exception('unanticipated groupings join type');
            }

            [$groupingsql, $groupingparams] = $DB->get_in_or_equal($filter->get_filter_values(), SQL_PARAMS_NAMED, 'grouping');
            $groupids = $DB->get_fieldset_sql(
                'SELECT g.id
                    FROM {groupings_groups} gg JOIN {groups} g ON gg.groupid = g.id
                    WHERE g.courseid = :courseid AND gg.groupingid ' . $groupingsql,
                ['courseid' => $this->courseid] + $groupingparams
            );
            if ($groupids) {
                $groupsjoin = groups_get_members_join($groupids, 'u.id', $this->context, GROUPS_JOIN_ANY);
                $fjoins[] = $groupsjoin->joins;
                $fwheres[] = "({$groupsjoin->wheres})";
                $params += $groupsjoin->params;
            }
        }

        if ($this->filterset->has_filter('roles')) {
            $filter = $this->filterset->get_filter('roles');
            if ($roleids = $filter->get_filter_values()) {
                $equate = true;
                switch ($filter->get_join_type()) {
                    case filterset::JOINTYPE_NONE:
                        $equate = false;
                        // Fallthrough.
                    case filterset::JOINTYPE_ANY:
                        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role', $equate);
                        break;
                    default:
                        throw new \coding_exception('unanticipated roles join type');
                }

                $fjoins[] = 'INNER JOIN {role_assignments} ra ON ra.contextid = :contextid AND ra.userid = u.id';
                $fwheres[] = "(ra.roleid $rolesql)";
                $params['contextid'] = $this->context->id;
                $params = array_merge($params, $roleparams);
            }
        }

        $params['bi'] = $this->blockinstanceid;

        switch ($this->filterset->get_join_type()) {
            case filterset::JOINTYPE_NONE:
                $joiner = ' OR ';
                $negate = true;
                break;
            case filterset::JOINTYPE_ANY:
                $joiner = ' OR ';
                $negate = false;
                break;
            case filterset::JOINTYPE_ALL:
                $joiner = ' AND ';
                $negate = false;
                break;
            default:
                throw new \coding_exception('unanticipated join type');
        }

        $fjoins = implode(' ', $fjoins);
        $fwheres = $fwheres ? implode($joiner, $fwheres) : '1=1';
        if ($negate) {
            $fwheres = "NOT ($fwheres)";
        }

        $total = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT u.id) FROM {user} u {$enroljoin->joins} {$fjoins} WHERE {$enroljoin->wheres} AND ({$fwheres})",
            $params
        );
        $this->use_pages = true;
        $this->pagesize($pagesize, $total);

        $this->rawdata = $DB->get_records_sql(
            "SELECT DISTINCT $picturefields, l.timeaccess, b.percentage AS progress, b.timemodified AS progressage
                FROM {user} u {$enroljoin->joins} {$fjoins}
                    LEFT JOIN {user_lastaccess} l ON l.userid = u.id AND l.courseid = :courseid
                    LEFT JOIN {block_completion_progress} b ON b.userid = u.id AND b.blockinstanceid = :bi
                WHERE {$enroljoin->wheres} AND {$twhere} AND ({$fwheres})
                ORDER BY {$this->get_sql_sort()}",
            $params,
            $this->get_page_start(),
            $this->get_page_size()
        );

        $this->initialbars($useinitialsbar);
    }

    /**
     * Prepare the progress bar for a user's row.
     * @param object $row
     * @return array
     */
    public function format_row($row) {
        if (!$this->progress) {
            $this->progress = (new completion_progress($this->course))->for_overview()->for_block_instance($this->blockinstance);
        }
        $this->progress->for_user($row);
        return parent::format_row($row);
    }

    /**
     * Form a select checkbox for the row.
     * @param object $row
     * @return string HTML
     */
    public function col_select($row) {
        global $OUTPUT;
        $checkbox = new \core\output\checkbox_toggleall('participants-table', false, [
            'classes' => 'usercheckbox',
            'id' => 'user' . $row->id,
            'name' => 'user' . $row->id,
            'checked' => false,
            'label' => get_string(
                'selectitem',
                'block_completion_progress',
                fullname($row, has_capability('moodle/site:viewfullnames', $this->context))
            ),
            'labelclasses' => 'accesshide',
        ]);
        return $OUTPUT->render($checkbox);
    }

    /**
     * Format the time last accessed value.
     * @param object $row
     * @return string HTML
     */
    public function col_timeaccess($row) {
        if ($row->timeaccess == 0) {
            return $this->strnever;
        }
        return userdate($row->timeaccess, $this->strftimedaydatetime);
    }

    /**
     * Produce a progress bar.
     * @param object $row
     * @return string HTML
     */
    public function col_progressbar($row) {
        global $OUTPUT;
        return $OUTPUT->render($this->progress);
    }

    /**
     * Format the percentage progress column.
     * @param object $row
     * @return string HTML
     */
    public function col_progress($row) {
        $pct = $row->progress ?? $this->progress->get_percentage();
        if ($pct === null) {
            $value = $this->strindeterminate;
        } else {
            $value = get_string('percents', '', $pct);
        }
        $age = time() - (int)$row->progressage;
        if ($row->progressage !== null && $age > 0) {
            $title = get_string('progresscachetime', 'block_completion_progress', \format_time($age));
            $value = \html_writer::span($value, '', ['title' => $title]);
        }
        return $value;
    }

    /**
     * Set filters and build table structure.
     *
     * @param filterset $filterset The filterset object to get the filters from.
     */
    public function set_filterset(filterset $filterset): void {
        global $DB;

        $this->courseid = $filterset->get_filter('courseid')->current();
        $this->blockinstanceid = $filterset->get_filter('blockinstanceid')->current();

        $this->course = get_course($this->courseid);
        $this->context = \context_course::instance($this->courseid, MUST_EXIST);
        $this->blockinstance = $DB->get_record('block_instances', ['id' => $this->blockinstanceid], '*', MUST_EXIST);
        $this->blockcontext = \context_block::instance($this->blockinstanceid, MUST_EXIST);

        parent::set_filterset($filterset);
    }

    /**
     * Check capability for users accessing the dynamic table.
     * @return bool
     */
    public function has_capability(): bool {
        return has_capability('block/completion_progress:overview', $this->context);
    }

    /**
     * Give the context for the table.
     * @return context
     */
    public function get_context(): \context {
        return $this->context;
    }

    /**
     * Give the base URL of the page.
     */
    public function guess_base_url(): void {
        $this->baseurl = new \moodle_url('/blocks/completion_progress/overview.php', [
            'instanceid' => $this->blockinstanceid,
            'courseid' => $this->courseid,
        ]);
    }
}
