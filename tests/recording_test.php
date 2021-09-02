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
 * Recording tests.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2018 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 */

namespace mod_bigbluebuttonbn;

use mod_bigbluebuttonbn\test\testcase_helper_trait;

/**
 * Recording test class.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2018 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 * @covers \mod_bigbluebuttonbn\recording
 * @coversDefaultClass \mod_bigbluebuttonbn\recording
 */
class recording_test extends \advanced_testcase {
    use testcase_helper_trait;

    public function setUp(): void {
        parent::setUp();

        $this->require_mock_server();
        $this->getDataGenerator()->get_plugin_generator('mod_bigbluebuttonbn')->reset_mock();
    }

    /**
     * Test for bigbluebuttonbn_get_allrecordings status refresh.
     *
     * @dataProvider get_status_provider
     * @covers ::get
     */
    public function test_get_allrecordings_status_refresh(int $status) {
        ['recordings' => $recordings] = $this->create_activity_with_recordings($this->get_course(),
            instance::TYPE_ALL, [['status' => $status]]);

        $this->assertEquals($status, (new recording($recordings[0]->id))->get('status'));
    }

    /**
     * @covers ::get_name
     */
    public function test_get_name(): void {
        ['recordings' => $recordings] = $this->create_activity_with_recordings($this->get_course(),
            instance::TYPE_ALL, [['name' => 'Example name']]);

        $this->assertEquals('Example name', (new recording($recordings[0]->id))->get('name'));
    }

    /**
     * @covers ::get_description
     */
    public function test_get_description(): void {
        ['recordings' => $recordings] = $this->create_activity_with_recordings($this->get_course(),
            instance::TYPE_ALL, [[
            'description' => 'Example description',
        ]]);

        $this->assertEquals('Example description', (new recording($recordings[0]->id))->get('description'));
    }

    public function get_status_provider(): array {
        return [
            [recording::RECORDING_STATUS_PROCESSED],
            [recording::RECORDING_STATUS_DISMISSED],
        ];
    }

    /**
     * Test for bigbluebuttonbn_get_allrecordings()
     *
     * @param int $type The activity type
     * @dataProvider get_allrecordings_types_provider
     * @covers ::get_recordings_for_instance
     */
    public function test_get_allrecordings(int $type): void {
        $this->resetAfterTest();
        $recordingcount = 2; // Two recordings only.
        list('activity' => $activity) =
            $this->create_activity_with_recordings($this->get_course(),
                $type, array_pad([], $recordingcount, []));

        // Fetch the recordings for the instance.
        // The count shoudl match the input count.
        $recordings = recording::get_recordings_for_instance(instance::get_from_instanceid($activity->id));
        $this->assertCount($recordingcount, $recordings);
    }

    public function get_allrecordings_types_provider(): array {
        return [
            'Instance Type ALL' => [
                'type' => instance::TYPE_ALL
            ],
            'Instance Type ROOM Only' => [
                'type' => instance::TYPE_ROOM_ONLY,
            ],
            'Instance Type Recording only' => [
                'type' => instance::TYPE_RECORDING_ONLY
            ],
        ];
    }

    /**
     * Test for bigbluebuttonbn_get_allrecordings().
     *
     * @dataProvider get_allrecordings_types_provider
     */
    public function test_get_recording_for_group($type) {
        $this->resetAfterTest(true);

        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_bigbluebuttonbn');

        $testcourse = $this->getDataGenerator()->create_course(['groupmodeforce' => true, 'groupmode' => VISIBLEGROUPS]);
        $teacher = $this->getDataGenerator()->create_and_enrol($testcourse, 'editingteacher');

        $group1 = $this->getDataGenerator()->create_group(['G1', 'courseid' => $testcourse->id]);
        $student1 = $this->getDataGenerator()->create_and_enrol($testcourse);
        $this->getDataGenerator()->create_group_member(['userid' => $student1, 'groupid' => $group1->id]);

        $group2 = $this->getDataGenerator()->create_group(['G2', 'courseid' => $testcourse->id]);
        $student2 = $this->getDataGenerator()->create_and_enrol($testcourse);
        $this->getDataGenerator()->create_group_member(['userid' => $student2, 'groupid' => $group2->id]);

        // No group.
        $student3 = $this->getDataGenerator()->create_and_enrol($testcourse);

        $activity = $plugingenerator->create_instance([
            'course' => $testcourse->id,
            'type' => $type,
            'name' => 'Example'
        ]);
        $instance = instance::get_from_instanceid($activity->id);
        $instance->set_group_id(0);
        $this->create_recordings_for_instance($instance,  [['name' => "Pre-Recording 1"], ['name' => "Pre-Recording 2"]]);
        $instance->set_group_id($group1->id);
        $this->create_recordings_for_instance($instance,  [['name' => "Group 1 Recording 1"]]);
        $instance->set_group_id($group2->id);
        $this->create_recordings_for_instance($instance,  [['name' => "Group 2 Recording 1"]]);

        $this->setUser($student1);
        $instance1 = instance::get_from_instanceid($activity->id);
        $instance1->set_group_id($group1->id);
        $recordings = recording::get_recordings_for_instance($instance1);
        $this->assertCount(1, $recordings);
        $this->assert_has_recording_by_name('Group 1 Recording 1', $recordings);

        $this->setUser($student2);
        $instance2 = instance::get_from_instanceid($activity->id);
        $instance2->set_group_id($group2->id);
        $recordings = recording::get_recordings_for_instance($instance2);
        $this->assertCount(1, $recordings);
        $this->assert_has_recording_by_name('Group 2 Recording 1', $recordings);

        $this->setUser($student3);
        $instance3 = instance::get_from_instanceid($activity->id);
        $recordings = recording::get_recordings_for_instance($instance3);
        $this->assertIsArray($recordings);
        $this->assertCount(4, $recordings);
        $this->assert_has_recording_by_name('Pre-Recording 1', $recordings);
        $this->assert_has_recording_by_name('Pre-Recording 2', $recordings);

    }

    /**
     * Check that a recording exist in the list of recordings
     *
     * @param $recordingname
     * @param $recordings
     */
    public function assert_has_recording_by_name($recordingname, $recordings) {
        $recordingnames = array_map(function($r) {
            return $r->get('name');
        }, $recordings);
        $this->assertContains($recordingname, $recordingnames);
    }

}