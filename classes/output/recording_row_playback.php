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

namespace mod_bigbluebuttonbn\output;

use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\local\bigbluebutton\recordings\recording_data;
use mod_bigbluebuttonbn\recording;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Renderer for recording row playback column
 *
 * @copyright 2010 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Laurent David  (laurent.david [at] call-learning [dt] fr)
 */
class recording_row_playback implements renderable, templatable {

    /**
     * @var $instance
     */
    protected $instance;

    /**
     * @var $recording
     */
    protected $recording;

    /**
     * recording_row_playback constructor.
     *
     * @param recording $rec
     * @param instance $instance
     */
    public function __construct(recording $rec, instance $instance) {
        $this->instance = $instance;
        $this->recording = $rec;
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $ispublished = $this->recording->get('published');
        $recordingid = $this->recording->get('id');
        $context = new stdClass();
        $context->dataimported = $this->recording->get('imported');
        $context->id = 'playbacks-' . $this->recording->get('id');
        $context->recordingid = $recordingid;
        $context->additionaloptions = '';
        $context->playbacks = [];
        $playbacks = $this->recording->get('playbacks');
        if ($ispublished && $playbacks) {
            global $CFG;
            foreach ($playbacks as $playback) {
                if ($this->should_be_included($playback)) {
                    $href =
                        $CFG->wwwroot . '/mod/bigbluebuttonbn/bbb_view.php?action=play&bn=' . $this->instance->get_instance_id() .
                        '&rid=' . $this->recording->get('id') . '&rtype=' . $playback['type'];
                    $linkattributes = [
                        'id' => 'recording-play-' . $playback['type'] . '-' . $recordingid,
                        'class' => 'btn btn-sm btn-default',
                        'onclick' => 'M.mod_bigbluebuttonbn.recordings.recordingPlay(this);',
                        'data-action' => 'play',
                        'data-target' => $playback['type'],
                        'data-href' => $href,
                    ];
                    $actionlink = new \action_link(
                        new \moodle_url('#'),
                        recording_data::type_text($playback['type']),
                        null,
                        $linkattributes
                    );
                    $context->playbacks[] = $actionlink->export_for_template($output);
                }
            }
        }
        return $context;
    }
    /**
     * Helper function renders the link used for recording type in row for the data used by the recording table.
     *
     * @param array $playback
     * @return boolean
     */
    protected function should_be_included(array $playback): bool {
        // All types that are not restricted are included.
        if (array_key_exists('restricted', $playback) && strtolower($playback['restricted']) == 'false') {
            return true;
        }
        // All types that are not statistics are included.
        if ($playback['type'] != 'statistics') {
            return true;
        }

        // Exclude imported recordings.
        if ($this->recording->get('imported')) {
            return false;
        }

        // Exclude non moderators.
        if (!$this->instance->is_admin() && !$this->instance->is_moderator()) {
            return false;
        }
        return true;

    }
}