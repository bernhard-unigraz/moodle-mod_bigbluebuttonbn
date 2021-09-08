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

namespace mod_bigbluebuttonbn\external;

use external_api;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\test\testcase_helper_trait;
use moodle_exception;
use require_login_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the update_course class.
 *
 * @package    mod_bigbluebuttonbn
 * @category   test
 * @copyright  2021 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Laurent David (laurent@call-learning.fr)
 * @coversDefaultClass \mod_bigbluebuttonbn\external\get_recordings
 */
class get_recordings_test extends \externallib_advanced_testcase {
    use testcase_helper_trait;

    protected function get_recordings(...$params) {
        $recordings = get_recordings::execute(...$params);

        return external_api::clean_returnvalue(get_recordings::execute_returns(), $recordings);
    }

    public function test_execute_wrong_instance() {
        $getrecordings = $this->get_recordings(1234);

        $this->assertIsArray($getrecordings);
        $this->assertArrayHasKey('status', $getrecordings);
        $this->assertEquals(false, $getrecordings['status']);
        $this->assertStringContainsString('nosuchinstance', $getrecordings['warnings'][0]['warningcode']);
    }

    public function test_execute_without_login() {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $record = $this->getDataGenerator()->create_module('bigbluebuttonbn', ['course' => $course->id]);
        $instance = instance::get_from_instanceid($record->id);

        $this->expectException(require_login_exception::class);
        $this->get_recordings($instance->get_instance_id());
    }

    public function test_execute_with_invalid_login() {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $record = $generator->create_module('bigbluebuttonbn', ['course' => $course->id]);
        $instance = instance::get_from_instanceid($record->id);

        $user = $generator->create_user();
        $this->setUser($user);

        $this->expectException(require_login_exception::class);
        $this->get_recordings($instance->get_instance_id());
    }

    public function test_execute_with_valid_login() {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $record = $generator->create_module('bigbluebuttonbn', ['course' => $course->id]);
        $instance = instance::get_from_instanceid($record->id);

        $user = $generator->create_and_enrol($course, 'student');
        $this->setUser($user);

        $getrecordings = $this->get_recordings($instance->get_instance_id());

        $this->assertIsArray($getrecordings);
        $this->assertArrayHasKey('status', $getrecordings);
        $this->assertEquals(true, $getrecordings['status']);
        $this->assertNotEmpty($getrecordings['tabledata']);
        $this->assertEquals($getrecordings['tabledata']['data'], '[]');
    }

    /**
     * Check if tools are present for teacher/moderator
     */
    public function test_get_recordings_tools() {
        $this->resetAfterTest();
        $dataset = [
            'type' => instance::TYPE_ALL,
            'groups' => null,
            'users' => [['username' => 't1', 'role' => 'editingteacher'], ['username' => 's1', 'role' => 'student']],
            'recordingsdata' => [
                [['name' => 'Recording1']],
                [['name' => 'Recording2']]
            ],
        ];
        $activityid = $this->create_from_dataset($dataset);
        $instance = instance::get_from_instanceid($activityid);

        $context = \context_course::instance($instance->get_course_id());
        foreach ($dataset['users'] as $userdef) {
            $user = \core_user::get_user_by_username($userdef['username']);
            $this->setUser($user);
            $getrecordings = $this->get_recordings($instance->get_instance_id());
            // Check users see or do not see recording dependings on their groups.
            foreach ($dataset['recordingsdata'] as $recordingdata) {
                foreach ($recordingdata as $recording) {
                    if (has_capability('moodle/course:update', $context)) {
                        $this->assertStringContainsString('data-action=\"delete\"', $getrecordings['tabledata']['data'],
                            "User $user->username, should be able to delete the recording {$recording['name']}");
                    } else {
                        $this->assertStringNotContainsString('data-action=\"delete\"', $getrecordings['tabledata']['data'],
                            "User $user->username, should not be able to delete the recording {$recording['name']}");
                    }
                }
            }
        }
    }

    /**
     * Check preview is present and displayed
     */
    public function test_get_recordings_preview() {
        $this->resetAfterTest();
        $dataset = [
            'type' => instance::TYPE_ALL,
            'additionalsettings' => [
                'recordings_preview' => 1
            ],
            'groups' => null,
            'users' => [['username' => 't1', 'role' => 'editingteacher'], ['username' => 's1', 'role' => 'student']],
            'recordingsdata' => [
                [['name' => 'Recording1']],
                [['name' => 'Recording2']]
            ],
        ];
        $activityid = $this->create_from_dataset($dataset);
        $instance = instance::get_from_instanceid($activityid);

        $context = \context_course::instance($instance->get_course_id());
        foreach ($dataset['users'] as $userdef) {
            $user = \core_user::get_user_by_username($userdef['username']);
            $this->setUser($user);
            $getrecordings = $this->get_recordings($instance->get_instance_id());
            $this->assertNotEmpty($getrecordings['tabledata']['columns']['3']);
            $this->assertEquals('preview', $getrecordings['tabledata']['columns']['3']['key']);
        }
    }

    /**
     * Check we can see all recording from a cours in a room only instance
     */
    public function test_get_recordings_room_only() {
        $this->resetAfterTest();
        set_config('bigbluebuttonbn_importrecordings_enabled', 1);
        $dataset = [
            'type' => instance::TYPE_ALL,
            'groups' => null,
            'users' => [['username' => 't1', 'role' => 'editingteacher'], ['username' => 's1', 'role' => 'student']],
            'recordingsdata' => [
                [['name' => 'Recording1']],
                [['name' => 'Recording2']]
            ],
        ];
        $activityid = $this->create_from_dataset($dataset);
        $instance = instance::get_from_instanceid($activityid);

        // Now create a recording only activity.
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_bigbluebuttonbn');
        // Now create a new activity and import the first record.
        $newactivity = $plugingenerator->create_instance([
            'course' => $instance->get_course_id(),
            'type' => instance::TYPE_RECORDING_ONLY,
            'name' => 'Example 2'
        ]);
        $plugingenerator->create_meeting([
            'instanceid' => $newactivity->id,
        ]); // We need to have a meeting created in order to import recordings.
        $newinstance = instance::get_from_instanceid($newactivity->id);
        $this->create_recordings_for_instance($newinstance, [['name' => 'Recording3']]);

        foreach ($dataset['users'] as $userdef) {
            $user = \core_user::get_user_by_username($userdef['username']);
            $this->setUser($user);
            $getrecordings = $this->get_recordings($newinstance->get_instance_id());
            // Check users see or do not see recording dependings on their groups.
            $data = json_decode($getrecordings['tabledata']['data']);
            $this->assertCount(3, $data);
        }
    }

    /**
     * Check if we can see the imported recording in a new instance
     */
    public function test_get_recordings_imported() {
        $this->resetAfterTest();
        set_config('bigbluebuttonbn_importrecordings_enabled', 1);
        $dataset = [
            'type' => instance::TYPE_ALL,
            'groups' => null,
            'users' => [['username' => 't1', 'role' => 'editingteacher'], ['username' => 's1', 'role' => 'student']],
            'recordingsdata' => [
                [['name' => 'Recording1']],
                [['name' => 'Recording2']]
            ],
        ];

        $activityid = $this->create_from_dataset($dataset);
        $instance = instance::get_from_instanceid($activityid);

        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_bigbluebuttonbn');
        // Now create a new activity and import the first record.
        $newactivity = $plugingenerator->create_instance([
            'course' => $instance->get_course_id(),
            'type' => instance::TYPE_ALL,
            'name' => 'Example 2'
        ]);
        $plugingenerator->create_meeting([
            'instanceid' => $newactivity->id,
        ]); // We need to have a meeting created in order to import recordings.
        $newinstance = instance::get_from_instanceid($newactivity->id);
        $recordings = $instance->get_recordings();
        end($recordings)->create_imported_recording($newinstance);

        foreach ($dataset['users'] as $userdef) {
            $user = \core_user::get_user_by_username($userdef['username']);
            $this->setUser($user);
            $getrecordings = $this->get_recordings($newinstance->get_instance_id());
            // Check users see or do not see recording dependings on their groups.
            foreach ($dataset['recordingsdata'] as $index => $recordingdata) {
                foreach ($recordingdata as $recording) {
                    if ($instance->can_manage_recordings()) {
                        $this->assertStringContainsString('data-action=\"delete\"', $getrecordings['tabledata']['data'],
                            "User $user->username, should be able to delete the recording {$recording['name']}");
                    } else {
                        $this->assertStringNotContainsString('data-action=\"delete\"', $getrecordings['tabledata']['data'],
                            "User $user->username, should not be able to delete the recording {$recording['name']}");
                    }
                }
                if ($index === 1) {
                    $this->assertStringContainsString($recording['name'], $getrecordings['tabledata']['data']);
                } else {
                    $this->assertStringNotContainsString($recording['name'], $getrecordings['tabledata']['data']);
                }
            }

        }
    }

    /**
     * Check if recording are visible/invisible depending on the group.
     */
    public function test_get_recordings_groups_visible() {
        $this->resetAfterTest();
        $dataset = [
            'type' => instance::TYPE_ALL,
            'groups' => ['G1' => ['s1'], 'G2' => ['s2']],
            'users' => [['username' => 't1', 'role' => 'editingteacher'], ['username' => 's1', 'role' => 'student'],
                ['username' => 's2', 'role' => 'student']],
            'recordingsdata' => [
                'G1' => [['name' => 'Recording1']],
                'G2' => [['name' => 'Recording2']]
            ],
        ];
        $activityid = $this->create_from_dataset($dataset);
        $instance = instance::get_from_instanceid($activityid);

        foreach ($dataset['users'] as $userdef) {
            $user = \core_user::get_user_by_username($userdef['username']);
            $this->setUser($user);
            $groups = array_values(groups_get_my_groups());
            $usergroupnames = array_map(function($g) {
                return $g->name;
            }, $groups);
            $mygroup = !empty($groups) ? end($groups) : null;

            $getrecordings = $this->get_recordings(
                $instance->get_instance_id(), 0, null, !empty($mygroup) ? $mygroup->id : null);
            // Check users see or do not see recording dependings on their groups.
            foreach ($dataset['recordingsdata'] as $groupname => $recordingdata) {
                foreach ($recordingdata as $recording) {
                    if (in_array($groupname, $usergroupnames) || $instance->can_manage_recordings()) {
                        $this->assertStringContainsString($recording['name'], $getrecordings['tabledata']['data'],
                            "User $user->username, should see recording {$recording['name']}");
                    } else {
                        $this->assertStringNotContainsString($recording['name'], $getrecordings['tabledata']['data'],
                            "User $user->username, should not see recording {$recording['name']}");
                    }
                }
            }
        }
    }
}
