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
 * The recording entity.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

namespace mod_bigbluebuttonbn\local\bigbluebutton\recordings;

use cache;
use core\persistent;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\local\proxy\recording_proxy;
use stdClass;

/**
 * Utility class that defines a recording and provides methods for handlinging locally in Moodle and externally in BBB.
 *
 * Utility class for recording helper
 *
 * @package mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording extends persistent {
    /** The table name. */
    const TABLE = 'bigbluebuttonbn_recordings';

    /** @var int Defines that the activity used to create the recording no longer exists */
    public const RECORDING_HEADLESS = 1;

    /** @var int Defines that the recording is not the original but an imported one */
    public const RECORDING_IMPORTED = 1;

    /** @var int Defines that the list should include imported recordings */
    public const INCLUDE_IMPORTED_RECORDINGS = true;

    /** @var int A meeting set to be recorded still awaits for a recording update */
    public const RECORDING_STATUS_AWAITING = 0;

    /** @var int A meeting set to be recorded was not recorded and dismissed by BBB */
    public const RECORDING_STATUS_DISMISSED = 1;

    /** @var int A meeting set to be recorded has a recording processed */
    public const RECORDING_STATUS_PROCESSED = 2;

    /** @var int A meeting set to be recorded received notification callback from BBB */
    public const RECORDING_STATUS_NOTIFIED = 3;

    /** @var bool $metadatachanged has metadata been changed so the remote information needs to be updated ? */
    protected $metadatachanged = false;

    /** @var int A refresh period for recordings, defaults to 300s (5mins) */
    public const RECORDING_REFRESH_DEFAULT_PERIOD = 300;

    /**
     * Create an instance of this class.
     *
     * @param int $id If set, this is the id of an existing record, used to load the data.
     * @param stdClass|null $record If set will be passed to from_record
     */
    public function __construct($id = 0, stdClass $record = null) {
        if ($record) {
            $record->headless = $record->headless ?? false;
            $record->imported = $record->imported ?? false;
            $record->groupid = $record->groupid ?? 0;
            $record->status = $record->status ?? self::RECORDING_STATUS_AWAITING;
        }
        parent::__construct($id, $record);
        $this->get_latest_metadata();
    }

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return array(
            'courseid' => array(
                'type' => PARAM_INT,
            ),
            'bigbluebuttonbnid' => array(
                'type' => PARAM_INT,
            ),
            'groupid' => array(
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
            ),
            'recordingid' => array(
                'type' => PARAM_RAW,
            ),
            'headless' => array(
                'type' => PARAM_BOOL,
            ),
            'imported' => array(
                'type' => PARAM_BOOL,
            ),
            'status' => array(
                'type' => PARAM_INT,
            ),
            'importeddata' => array(
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => ''
            ),
            'name' => array(
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'description' => array(
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => 0
            ),
            'protected' => array(
                'type' => PARAM_BOOL,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'starttime' => array(
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'endtime' => array(
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'published' => array(
                'type' => PARAM_BOOL,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'protect' => array(
                'type' => PARAM_BOOL,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
            'playbacks' => array(
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null
            ),
        );
    }

    /**
     * Before doing the database update, let's check if we need to update metadata
     *
     * @return void
     */
    protected function before_update() {
        // We update if the remote metadata has been changed locally.
        if ($this->metadatachanged && !$this->get('imported')) {
            $cacheddata = $this->get_latest_metadata();
            if ($cacheddata) {
                recording_proxy::update_recording(
                    $this->get('recordingid'),
                    $cacheddata);
            }
            $this->metadatachanged = false;
        }
    }

    /**
     * Create a new imported recording from current recording
     *
     * @param instance $instance
     * @return recording
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     */
    public function create_imported_recording(instance $instance) {
        $recordingrec = $this->to_record();
        $remotedata = $this->get_latest_metadata();
        unset($recordingrec->id);
        $recordingrec->bigbluebuttonbnid = $instance->get_instance_id();
        $recordingrec->courseid = $instance->get_course_id();
        $recordingrec->groupid = 0; // The recording is available to everyone.
        $recordingrec->importeddata = json_encode($remotedata);
        $recordingrec->imported = true;
        $importedrecording = new recording(0, $recordingrec);
        $importedrecording->create();
        return $importedrecording;
    }

    /**
     * Delete the recording in the BBB button
     *
     * @return void
     */
    protected function before_delete() {
        $recordid = $this->get('recordingid');
        if ($recordid && !$this->get('imported')) {
            recording_proxy::delete_recording($recordid);
            // Delete in cache if needed.
            $cachedrecordings = cache::make('mod_bigbluebuttonbn', 'recordings');
            $cachedrecordings->delete($recordid);
        }
    }

    /**
     * Set name
     *
     * @param string $value
     */
    protected function set_name($value) {
        $this->metadata_set('name', trim($value));
    }

    /**
     * Set Description
     *
     * @param string $value
     */
    protected function set_description($value) {
        $this->metadata_set('description', trim($value));
    }

    /**
     * Recording is protected
     *
     * @param bool $value
     */
    protected function set_protected($value) {
        $realvalue = $value ? "true" : "false";
        $this->metadata_set('protected', $realvalue);
        recording_proxy::protect_recording($this->get('recordingid'), $realvalue);
    }

    /**
     * Recording starttime
     *
     * @param int $value
     */
    protected function set_starttime($value) {
        $this->metadata_set('starttime', $value);
    }

    /**
     * Recording endtime
     *
     * @param int $value
     */
    protected function set_endtime($value) {
        $this->metadata_set('endtime', $value);
    }

    /**
     * Recording is published
     *
     * @param bool $value
     */
    protected function set_published($value) {
        $realvalue = $value ? "true" : "false";
        $this->metadata_set('published', $realvalue);
        // Now set this flag onto the remote bbb server.
        recording_proxy::publish_recording($this->get('recordingid'), $realvalue);
    }

    /**
     * POSSIBLE_REMOTE_META_SOURCE match a field type and its metadataname (historical and current).
     */
    const POSSIBLE_REMOTE_META_SOURCE = [
        'description' => array('meta_bbb-recording-description', 'meta_contextactivitydescription'),
        'name' => array('meta_bbb-recording-name', 'meta_contextactivity', 'meetingName'),
        'playbacks' => array('playbacks'),
        'starttime' => array('startTime'),
        'endtime' => array('endTime'),
        'published' => array('published'),
        'protected' => array('protect'),
        'tags' => array('meta_bbb-recording-tags')
    ];

    /**
     * Get the real metadata name for the possible source.
     *
     * @param string $sourcetype the name of the source we look for (name, description...)
     * @param array $metadata current metadata
     */
    protected function get_possible_meta_name_for_source($sourcetype, $metadata): string {
        $possiblesource = self::POSSIBLE_REMOTE_META_SOURCE[$sourcetype];
        $possiblesourcename = $possiblesource[0];
        foreach ($possiblesource as $possiblesname) {
            if (isset($meta[$possiblesname])) {
                $possiblesourcename = $possiblesname;
            }
        }
        return $possiblesourcename;
    }

    /**
     * Convert string (metadata) to json object
     *
     * @return mixed|null
     */
    protected function remote_meta_convert() {
        $remotemeta = $this->raw_get('importeddata');
        return json_decode($remotemeta, true);
    }

    /**
     * Description is stored in the metadata, so we sometimes needs to do some conversion.
     */
    protected function get_description() {
        return trim($this->metadata_get('description'));

    }

    /**
     * Name is stored in the metadata
     */
    protected function get_name() {
        return trim($this->metadata_get('name'));
    }

    /**
     * List of playbacks for this recording
     *
     * @return mixed|null
     * @throws \coding_exception
     */
    protected function get_playbacks() {
        return $this->metadata_get('playbacks');
    }

    /**
     * Is protected
     *
     * @return mixed|null
     * @throws \coding_exception
     */
    protected function get_protected() {
        $protectedtext = $this->metadata_get('protected');
        return $protectedtext === "true";
    }

    /**
     * Start time
     *
     * @return mixed|null
     * @throws \coding_exception
     */
    protected function get_starttime() {
        return $this->metadata_get('starttime');
    }

    /**
     * Start time
     *
     * @return mixed|null
     * @throws \coding_exception
     */
    protected function get_endtime() {
        return $this->metadata_get('endtime');
    }

    /**
     * Is published
     *
     * @return mixed|null
     * @throws \coding_exception
     */
    protected function get_published() {
        $publishedtext = $this->metadata_get('published');
        return $publishedtext === "true";
    }

    /**
     * Set locally stored metadata from this instance
     *
     * @param string $fieldname
     * @param mixed $value
     * @throws \coding_exception
     */
    protected function metadata_set($fieldname, $value) {
        // Can we can change the metadata on the imported record ?
        if (!$this->get('imported')) {
            $this->metadatachanged = true;
            $metadata = $this->get_latest_metadata();
            $possiblesourcename = $this->get_possible_meta_name_for_source($fieldname, $metadata);
            $metadata[$possiblesourcename] = $value;
            $recordid = $this->get('recordingid');
            // Update the cache.
            $this->set_cached_metadata($metadata);
        }
    }

    /**
     * Get information stored in the recording metadata such as description, name and other info
     *
     * @param string $fieldname
     * @return mixed|null
     */
    protected function metadata_get($fieldname) {
        $remotemetadata = $this->get_latest_metadata();
        $possiblesourcename = $this->get_possible_meta_name_for_source($fieldname, $remotemetadata);
        return $remotemetadata[$possiblesourcename] ?? null;
    }

    /**
     * Set cached metadata for this recording
     *
     * @param array $metadata metadata associative array
     * @return boolean
     * @throws \coding_exception
     */
    protected function set_cached_metadata($metadata) {
        $rid = $this->get('recordingid');
        // First try to fetch in cache.
        $cachedrecordings = cache::make('mod_bigbluebuttonbn', 'recordings');
        $cacheddata = (object) [
            'metadata' => $metadata,
            'timestamp' => time()
        ];
        return $cachedrecordings->set($rid, $cacheddata);
    }

    /**
     * Initialise or refresh the cached value.
     *
     * If metadata has changed locally or if it an imported recording, nothing will be done.
     *
     * @return bool|float|int|mixed|string
     * @throws \coding_exception
     */
    protected function get_latest_metadata() {
        $metadata = null;
        $metadatatimestamp = null;
        // First try to fetch in cache.
        $rid = $this->get('recordingid');
        // First try to fetch in cache.
        $cache = cache::make('mod_bigbluebuttonbn', 'recordings');
        if ($cacheddata = $cache->get($rid)) {
            $metadata = $cacheddata->metadata;
            $metadatatimestamp = $cacheddata->timestamp;
        }
        // Init the metadata from the cache. We make sure we do not overwrite cached data
        // if data has been changed.
        if (!$this->metadatachanged) {
            // Check if we need to refresh the metadata.
            $now = time();
            // UI configuration options.
            $refreshperiod = \mod_bigbluebuttonbn\local\config::get('recording_refresh_period');

            if ($metadatatimestamp + $refreshperiod < $now) {
                // Time to refresh or to initialise.
                if (!$this->get('imported')) {
                    $rid = $this->get('recordingid');
                    $recordings = recording_proxy::fetch_recordings([$rid]);
                    if (!empty($recordings[$rid])) {
                        $metadata = $recordings[$rid];
                        $this->set_cached_metadata($metadata);
                    }
                } else {
                    $metadata = json_decode($this->get('importeddata'), true);
                }
            }
        }
        return $metadata;
    }
}
